<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekAyari;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Yedek alma (BY-007; README §6.1 1–5. adımlar, §4.1, §4.4 1. düzey):
 *
 * 1. Kilit çağıranda (yaz kuralı: durum klasörünün kilidi tutulmalı). Ölen
 *    bir süreçten kalan `calisiyor` kayıt önce sonuçlanır (SahipsizYedekler);
 *    sonuçlandırma depoyu doğrulamadan hiçbir kayda dokunmaz (BY-011-R4).
 * 2. Yedek klasörü depo işaretiyle doğrulanamazsa (bağlanmamış disk;
 *    YedekDeposu, BY-011-R3) yedek başlamaz, kayıt `basarisiz` olur. Boş
 *    disk alanı eşiğin altındaysa yedek başlamaz; eski yedekler kural
 *    dışı silinmez (README §4.3).
 * 3. Anlık görüntü açılır; sayımlar ve migration listesi ondan okunur;
 *    `pg_dump --snapshot` aynı görüntüyle GEÇİCİ ada yazar. Geçici tabloların
 *    verisi dışarıda, şeması içeride (README §7.1).
 * 4. 1. düzey doğrulama: boyut > 0, SHA-256, `pg_restore --list` (beklenen
 *    tablo var mı), `pg_restore --file=/dev/null` (bütün veri blokları okunur).
 * 5. İmzalı manifest yazılır, geçici dosya asıl adına taşınır, klasör diske
 *    zorlanır; dizin kaydı `tamam`.
 *
 * 6. Sınama geri yüklemesi açıksa (`sinama_geri_yukleme`) yedek geçici
 *    veritabanına geri yüklenip karşılaştırılır (GeriYukleyici::sina, BY-026).
 *
 * Hata yolunda geçici dosya silinir, kayıt `basarisiz` olur, sonuç denetim
 * kaydına yazılır; yarım dosya listede hiç görünmez. Kopyalama, planlar ve
 * temizlik bu sınıfın işi değildir (BY-008…BY-010).
 */
final class YedekAlici
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly PgAraclari $pg,
        private readonly KaynakVeritabani $kaynak,
        private readonly ManifestDeposu $manifestler,
        private readonly OlayGunlugu $olaylar,
        private readonly YedekDenetimi $denetim,
        private readonly YedekBildirimcisi $bildirimci,
        private readonly YasamSinyali $sinyal,
        private readonly AyarKopyasi $ayarKopyasi,
        private readonly GeriYukleyici $geriYukleyici,
        private readonly SahipsizYedekler $sahipsizler,
    ) {}

    /**
     * @param  YedekKilidi  $kilit  Durum klasörünün tutulan kilidi
     * @param  list<string>  $planAdlari
     * @param  array{baglanma_yetkileri: string|null}|null  $ozellikDuzeltmesi  Manifestin veritabanı özelliklerinde anlık görüntüdeki değerin yerine yazılacaklar:
     *                                                                          üstüne geri yüklemenin yazıcı bariyeri bağlanma yetkilerini daraltmışken alınan öncesi yedek ÖZGÜN yetkileri taşır (BY-028-R1)
     * @return Yedek `tamam` ya da `basarisiz` dizin kaydı
     *
     * @throws LogicException Kilit tutulmuyor
     * @throws YedekAlmaHatasi Açık bir üstüne geri yükleme var (kayıt açılmaz)
     */
    public function al(
        YedekKilidi $kilit,
        YedekKaynagi $kaynak,
        ?string $aciklama = null,
        ?int $kullaniciId = null,
        array $planAdlari = [],
        ?string $geriYuklemeKimligi = null,
        ?array $ozellikDuzeltmesi = null,
    ): Yedek {
        $durum = $this->yapilandirma->islemDurumu();

        if (! $kilit->dosyayiTutuyorMu($this->yapilandirma->durumKlasoru().'/kilit')) {
            throw new LogicException('Yedek yalnız yedekleme kilidini tutan süreç tarafından alınır.');
        }

        // Ölen önceki süreçten kalan `calisiyor` kaydı sonuçlanır (BY-007-R1).
        // Sonuçlandırma önce depoyu doğrular (BY-011-R4): doğrulanamazsa eski
        // kayda dokunulmaz, bu yedek aşağıda o hatayla başarısız olur (kayıt,
        // denetim, bildirim — depo denetimi önceden de yedeğin hata yolundaydı).
        $klasor = $this->klasoruHazirla();
        $oncekiHata = null;
        try {
            $this->sahipsizler->sonuclandir($kilit);
        } catch (Throwable $hata) {
            $oncekiHata = $hata;
        }

        // Geri yükleme öncesi yedeği geri yüklemenin İÇİNDE alınır; diğerleri
        // açık bir üstüne geri yükleme varken başlamaz (README §6.4)
        $geriYuklemeIcinde = $kaynak === YedekKaynagi::GeriYuklemeOncesi;
        if (! $geriYuklemeIcinde && $durum->acikGeriYuklemeVarMi()) {
            throw new YedekAlmaHatasi(YedekAlmaHatasi::ACIK_GERI_YUKLEME, 'Açık bir geri yükleme işlemi var; yedek alınmadı.');
        }

        $baslangic = hrtime(true);
        $zaman = CarbonImmutable::now('Europe/Istanbul');
        $dosyaAdi = $this->dosyaAdi($klasor, $kaynak, $zaman);
        $islemKimligi = (string) Str::uuid();

        $yedek = Yedek::query()->create([
            'kimlik' => (string) Str::uuid(),
            'dosya_adi' => $dosyaAdi,
            'kaynak' => $kaynak,
            'durum' => YedekDurumu::Calisiyor,
            'aciklama' => $aciklama,
            'alan_kullanici_id' => $kullaniciId,
        ]);

        $yaz = function (string $adim, ?array $hata = null) use ($geriYuklemeIcinde, $durum, $kilit, $islemKimligi, $zaman, $yedek, $kullaniciId): void {
            if ($geriYuklemeIcinde) {
                return;
            }

            $durum->yaz(new IslemKaydi(
                kimlik: $islemKimligi,
                tur: IslemTuru::Yedek,
                adim: $adim,
                acik: false,
                baslangic: $zaman->utc(),
                sonGuncelleme: CarbonImmutable::now('UTC'),
                hata: $hata,
                erisimAnahtariOzeti: null,
                yedekKimligi: $yedek->kimlik,
                isteyenKullaniciId: $kullaniciId,
            ), $kilit);
        };

        $asil = $klasor.'/'.$dosyaAdi;
        $gecici = AtomikDosya::geciciAd($asil);
        $manifestYolu = null;

        try {
            $yaz(YedekAdimi::Basladi->value);
            // Diski ayrılmış bağlama noktasına (kök diske) yazılmaz (BY-011-R3)
            if ($oncekiHata instanceof DepoIsaretiYok) {
                throw new YedekAlmaHatasi(YedekAlmaHatasi::DEPO, $oncekiHata->getMessage());
            }
            if ($oncekiHata !== null) {
                throw $oncekiHata;
            }
            $this->diskDenetle($klasor);
            $sure = $this->sureSiniri();

            $manifest = $this->pg->parolaylaCalis(function () use ($gecici, $sure, $yaz, $yedek, $kaynak, $zaman, $planAdlari, $aciklama, $kullaniciId, $geriYuklemeKimligi, $dosyaAdi, $ozellikDuzeltmesi): YedekManifesti {
                $pgDumpSurumu = $this->pg->surum('pg_dump');
                $baglanti = $this->pg->baglanti();

                $goruntu = $this->kaynak->anlikGoruntuyle(function (AnlikGoruntu $goruntu) use ($gecici, $sure, $yaz, $baglanti): AnlikGoruntu {
                    $yaz(YedekAdimi::PgDump->value);
                    $haric = array_map(fn (string $t): string => '--exclude-table-data=public.'.$t, $this->yapilandirma->verisiAlinmayanTablolar());
                    $sonuc = $this->pg->calistir(['pg_dump', '--format=custom', '--file='.$gecici, '--snapshot='.$goruntu->kimlik, ...$haric, ...$baglanti->argumanlar()], $sure);

                    if (! $sonuc->successful()) {
                        throw new YedekAlmaHatasi(YedekAlmaHatasi::PG_DUMP, 'pg_dump başarısız (çıkış '.$sonuc->exitCode().'): '.Str::limit(trim($sonuc->errorOutput()), 400));
                    }

                    // pg_dump süreç umask'ıyla (çoğu zaman 0644) yazar; yedek bütün veridir (README §8)
                    @chmod($gecici, 0640);

                    return $goruntu;
                });

                $yaz(YedekAdimi::Dogrulama->value);
                [$boyut, $ozet] = $this->dogrula($gecici, $sure);

                return new YedekManifesti(
                    kimlik: $yedek->kimlik,
                    alinmaZamani: $zaman,
                    kaynak: $kaynak,
                    planAdlari: $planAdlari,
                    veritabani: $baglanti->veritabani,
                    dosyaAdi: $dosyaAdi,
                    boyut: $boyut,
                    sha256: $ozet,
                    pgSunucuSurumu: $goruntu->sunucuSurumu,
                    pgDumpSurumu: $pgDumpSurumu,
                    uygulamaSurumu: UygulamaSurumu::oku(),
                    migrationlar: $goruntu->migrationlar,
                    satirSayilari: $goruntu->satirSayilari,
                    verisiAlinmayanTablolar: $this->yapilandirma->verisiAlinmayanTablolar(),
                    veritabaniOzellikleri: [...$goruntu->ozellikler, ...($ozellikDuzeltmesi ?? [])],
                    sahipRol: $goruntu->sahipRol,
                    sifreli: false,
                    appKeyParmakIzi: AppKeyParmakIzi::hesapla(),
                    aciklama: $aciklama,
                    alanKullaniciId: $kullaniciId,
                    alanKullanici: $kullaniciId === null ? null : User::query()->whereKey($kullaniciId)->value('ad'),
                    geriYuklemeKimligi: $geriYuklemeKimligi,
                );
            });

            $yaz(YedekAdimi::Manifest->value);
            $manifestYolu = $this->manifestler->yaz($manifest, $klasor);

            if (! @rename($gecici, $asil)) {
                throw new YedekAlmaHatasi(YedekAlmaHatasi::DOSYA, 'Yedek dosyası asıl adına taşınamadı.');
            }
            KaliciKlasor::esitle($klasor);

            $yedek->update([
                'durum' => YedekDurumu::Tamam,
                'dogrulama_durumu' => DogrulamaDurumu::OzetTamam,
                'imzali' => true,
                'boyut' => $manifest->boyut,
                'sha256' => $manifest->sha256,
                'alinma_zamani' => $zaman,
                'bitti' => CarbonImmutable::now(),
            ]);
            $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Dogrulandi, ['durum' => DogrulamaDurumu::OzetTamam->value]));
            $yaz(YedekAdimi::Bitti->value);

            $this->denetim->kaydet('yedek_al', IslemSonucu::Basarili, $yedek->id, $kullaniciId, $this->sureMs($baslangic), [
                'kaynak' => $kaynak->value, 'dosya' => $dosyaAdi, 'boyut' => $manifest->boyut,
            ]);
            $this->sinyal->gonder(YasamSinyali::YEREL);

            // Ayarların dosya kopyası taze kalsın (bildirim ve geri yükleme uzlaştırması için)
            try {
                $this->ayarKopyasi->yaz();
            } catch (Throwable $hata) {
                report($hata);
            }
        } catch (Throwable $hata) {
            @unlink($gecici);
            // Manifest yazıldı ama dosya taşınamadıysa dosyasız manifest bırakma
            if ($manifestYolu !== null && ! is_file($asil)) {
                @unlink($manifestYolu);
            }

            [$kod, $mesaj] = $this->hataBilgisi($hata);
            $yedek->update([
                'durum' => YedekDurumu::Basarisiz,
                'hata_kodu' => $kod,
                'hata_mesaji' => $mesaj,
                'bitti' => CarbonImmutable::now(),
            ]);

            try {
                $yaz(YedekAdimi::Basarisiz->value, ['kod' => $kod, 'mesaj' => $mesaj]);
            } catch (Throwable) {
                // Durum yazılamasa da yedek kaydı ve denetim kaydı hatayı taşır
            }

            $this->denetim->kaydet('yedek_al', IslemSonucu::Basarisiz, $yedek->id, $kullaniciId, $this->sureMs($baslangic), [
                'kaynak' => $kaynak->value, 'kod' => $kod,
            ], $mesaj);

            $this->bildirimci->bildir(
                $kod === YedekAlmaHatasi::DISK_DOLU ? 'disk_esigi' : 'yedek_basarisiz',
                [__('yedekleme.mail.yedek_basarisiz').": {$dosyaAdi}", "{$kod}: {$mesaj}"],
            );
        }

        // 2. düzey doğrulama (README §4.4; BY-026): her başarılı yedekten sonra
        // sınama geri yüklemesi. Sonucu yedeği bozmaz; geri yükleme öncesi
        // yedekte yapılmaz (bakım modunda, geri yüklemenin içinde alınır).
        if (! $geriYuklemeIcinde && $yedek->durum === YedekDurumu::Tamam && $this->yapilandirma->sinamaGeriYuklemesi()) {
            try {
                $this->geriYukleyici->sina($kilit, $yedek, $kullaniciId);
            } catch (Throwable $hata) {
                report($hata);
            }
        }

        return $yedek->refresh();
    }

    /**
     * Yedek klasörü yoksa açılır (geçiş dönemi). Sunucu düzeninde kurulum açar.
     */
    private function klasoruHazirla(): string
    {
        $klasor = $this->yapilandirma->klasor();

        if (! is_dir($klasor)) {
            if (! @mkdir($klasor, 0750, true) && ! is_dir($klasor)) {
                throw new RuntimeException("Yedek klasörü açılamadı: {$klasor}");
            }
            KaliciKlasor::esitle(dirname($klasor));
        }

        return $klasor;
    }

    /**
     * Ad kalıbı (README §5.5): erp_<kaynak>_<YYYY-AA-GG_SSddss><saat farkı>.dump;
     * aynı saniyede ikinci yedek `_2` alır.
     */
    private function dosyaAdi(string $klasor, YedekKaynagi $kaynak, CarbonImmutable $zaman): string
    {
        $kok = 'erp_'.$kaynak->value.'_'.$zaman->format('Y-m-d_HisO');
        $ad = $kok.'.dump';

        for ($i = 2; file_exists($klasor.'/'.$ad) || Yedek::query()->where('dosya_adi', $ad)->exists(); $i++) {
            $ad = $kok.'_'.$i.'.dump';
        }

        return $ad;
    }

    private function diskDenetle(string $klasor): void
    {
        $bos = @disk_free_space($klasor);
        $toplam = @disk_total_space($klasor);

        if ($bos === false || $toplam === false || $toplam <= 0) {
            throw new YedekAlmaHatasi(YedekAlmaHatasi::DOSYA, 'Yedek klasörünün boş alanı ölçülemedi.');
        }

        $esik = YedekAyari::guncel()->enaz_bos_alan_yuzde;
        $yuzde = $bos / $toplam * 100;

        if ($yuzde < $esik) {
            throw new YedekAlmaHatasi(YedekAlmaHatasi::DISK_DOLU, sprintf('Boş disk alanı %%%.1f; eşik %%%d. Yedek başlamadı, eski yedekler silinmedi.', $yuzde, $esik));
        }
    }

    private function sureSiniri(): int
    {
        try {
            return $this->yapilandirma->sureSiniri('yedek');
        } catch (YapilandirmaHatasi $hata) {
            throw new YedekAlmaHatasi(YedekAlmaHatasi::YAPILANDIRMA, $hata->getMessage());
        }
    }

    /**
     * @return array{0: int, 1: string} [boyut, sha256]
     */
    private function dogrula(string $dosya, int $sure): array
    {
        clearstatcache(true, $dosya);
        $boyut = @filesize($dosya);

        if ($boyut === false || $boyut < 1) {
            throw new YedekAlmaHatasi(YedekAlmaHatasi::DOGRULAMA, 'Yedek dosyası boş ya da okunamıyor.');
        }

        $ozet = @hash_file('sha256', $dosya);
        if (! is_string($ozet)) {
            throw new YedekAlmaHatasi(YedekAlmaHatasi::DOGRULAMA, 'Yedek dosyasının özeti hesaplanamadı.');
        }

        $liste = $this->pg->calistir(['pg_restore', '--list', $dosya], $sure);
        if (! $liste->successful() || preg_match('/\bTABLE public migrations\b/', $liste->output()) !== 1) {
            throw new YedekAlmaHatasi(YedekAlmaHatasi::DOGRULAMA, 'pg_restore --list yedeği okuyamadı ya da beklenen tablolar yok.');
        }

        $okuma = $this->pg->calistir(['pg_restore', '--file=/dev/null', $dosya], $sure);
        if (! $okuma->successful()) {
            throw new YedekAlmaHatasi(YedekAlmaHatasi::DOGRULAMA, 'Yedeğin veri blokları okunamadı: '.Str::limit(trim($okuma->errorOutput()), 300));
        }

        return [$boyut, $ozet];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function hataBilgisi(Throwable $hata): array
    {
        return match (true) {
            $hata instanceof YedekAlmaHatasi => [$hata->kod, Str::limit($hata->getMessage(), 500)],
            $hata instanceof ImzaAnahtariYok, $hata instanceof YapilandirmaHatasi => [YedekAlmaHatasi::YAPILANDIRMA, Str::limit($hata->getMessage(), 500)],
            // Beklenmeyen hatanın iletisi veri taşıyabilir: yalnız sınıf adı
            default => ['YEDEK_BEKLENMEYEN_HATA', $hata::class],
        };
    }

    private function sureMs(int $baslangic): int
    {
        return (int) ((hrtime(true) - $baslangic) / 1_000_000);
    }
}
