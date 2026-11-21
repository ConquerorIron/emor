<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\GeriYukleme;
use App\Models\Yedek;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Geri yükleme çekirdeği (BY-026): farklı ada geri yükleme (README §4.5-A,
 * §6.3) ve sınama geri yüklemesi (§4.4 2. düzey). Üstüne geri yükleme
 * (§6.4, BY-028) bunun üstüne kurulur.
 *
 * 1. Kilit çağıranda; açık bir üstüne geri yükleme varken başlamaz.
 * 2. Yalnız imzalı, tamamlanmış yedek. İmza veritabanındaki bayraktan değil
 *    yedek klasöründeki manifestten yeniden doğrulanır (web süreci
 *    veritabanını değiştirebilir, imza anahtarını okuyamaz — README §5.2).
 * 3. Dosya: yerel dosyanın boyutu ve SHA-256'sı manifestle aynı olmalı. Yerel
 *    dosya yoksa ŞİFRESİZ bir kopyadan (ör. ikinci yerel disk) indirilip
 *    aynı denetimden geçer. Yalnız şifreli kopya varsa özel anahtar gerekir;
 *    ekrandan yapılamaz.
 * 4. Hedef ad sistemce üretilir (VeritabaniAdi); varsa dokunulmaz.
 * 5. Veritabanı manifestteki kodlama / sıralama / ayarlarla, sahibi uygulama
 *    rolü olarak açılır; `pg_restore --exit-on-error --single-transaction
 *    --no-owner --role=<uygulama rolü>`.
 * 6. Satır sayıları, migration listesi ve tablo sahibi manifestle
 *    karşılaştırılır (içe alınmış eski yedekte manifest bunları bilmez:
 *    karşılaştırma atlanır, yükleme yapılır).
 * 7. Herhangi bir hatada yarım veritabanı silinir; geçici dosya kalmaz.
 */
final class GeriYukleyici
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly PgAraclari $pg,
        private readonly GeriYuklemeVeritabani $vt,
        private readonly ManifestDeposu $manifestler,
        private readonly HedefKatalogu $katalog,
        private readonly OlayGunlugu $olaylar,
        private readonly YedekDenetimi $denetim,
        private readonly YedekBildirimcisi $bildirimci,
        private readonly YasamSinyali $sinyal,
    ) {}

    /**
     * Farklı ada geri yükler. Hata istisna olarak taşmaz: kayıt `basarisiz`
     * olur, yarım veritabanı silinir, bildirim gider.
     *
     * @param  YedekKilidi  $kilit  Durum klasörünün tutulan kilidi
     *
     * @throws LogicException Kilit tutulmuyor
     * @throws GeriYuklemeHatasi İşlem başlamadı, kayıt açılmadı (ek kalıp dışı, yedek geri yüklenemez, açık üstüne geri yükleme)
     */
    public function kopyaya(YedekKilidi $kilit, Yedek $yedek, ?string $ek = null, ?int $kullaniciId = null): GeriYukleme
    {
        $this->onDenetim($kilit, $yedek);
        $zaman = CarbonImmutable::now('UTC');
        $ad = VeritabaniAdi::kopya($zaman, $ek);

        $geri = GeriYukleme::query()->create([
            'kimlik' => (string) Str::uuid(),
            'yedek_id' => $yedek->id,
            'tur' => GeriYuklemeTuru::Kopya,
            'hedef_veritabani' => $ad,
            'isteyen_kullanici_id' => $kullaniciId,
            'durum' => GeriYuklemeDurumu::Calisiyor,
            'adimlar' => [],
            'basladi' => $zaman,
        ]);

        $durumYaz = $this->durumYazici($kilit, IslemTuru::GeriYuklemeKopya, $geri->kimlik, $zaman, $yedek, $ad, $kullaniciId);
        $adim = function (GeriYuklemeAdimi $a, string $d) use ($geri, $durumYaz): void {
            $geri->adimEkle($a, $d);
            if ($d === 'basladi') {
                $durumYaz($a);
            }
        };
        $baslangic = hrtime(true);

        try {
            $durumYaz(GeriYuklemeAdimi::Basladi);
            $this->vt->parolaylaCalis(fn () => $this->yukle($yedek, $ad, $adim, herDurumdaSil: false));

            $geri->update(['durum' => GeriYuklemeDurumu::Tamam, 'bitti' => CarbonImmutable::now()]);
            $durumYaz(GeriYuklemeAdimi::Bitti);
            $this->denetim->kaydet('geri_yukle_kopya', IslemSonucu::Basarili, $yedek->id, $kullaniciId, $this->sureMs($baslangic), [
                'hedef' => $ad, 'yedek' => $yedek->dosya_adi,
            ], geriYuklemeId: $geri->id);
        } catch (Throwable $hata) {
            [$kod, $mesaj] = $this->hataBilgisi($hata);
            $geri->update([
                'durum' => GeriYuklemeDurumu::Basarisiz,
                'hata_kodu' => $kod,
                'hata_mesaji' => $mesaj,
                'bitti' => CarbonImmutable::now(),
                // Sütun "veritabanının silindiği an"dır (SaklamaPolitikasi de böyle okur)
                'veritabani_silinme_zamani' => $geri->adimDurumu(GeriYuklemeAdimi::Silme) === 'tamam' ? CarbonImmutable::now() : null,
            ]);

            try {
                $durumYaz(GeriYuklemeAdimi::Basarisiz, ['kod' => $kod, 'mesaj' => $mesaj]);
            } catch (Throwable) {
                // Durum yazılamasa da kayıt ve denetim kaydı hatayı taşır
            }

            $this->denetim->kaydet('geri_yukle_kopya', IslemSonucu::Basarisiz, $yedek->id, $kullaniciId, $this->sureMs($baslangic), [
                'hedef' => $ad, 'kod' => $kod,
            ], $mesaj, $geri->id);
            $this->bildirimci->bildir('geri_yukleme_basarisiz', [
                __('yedekleme.mail.geri_yukleme_basarisiz').": {$yedek->dosya_adi} → {$ad}", "{$kod}: {$mesaj}",
            ]);
        }

        return $geri->refresh();
    }

    /**
     * Sınama geri yüklemesi: geçici veritabanına yükle, karşılaştır, HER
     * DURUMDA sil. Sonuç yedeğin doğrulama durumuna, olay günlüğüne, yaşam
     * sinyaline (geçti) ya da bildirime (başarısız) yazılır. Yedeğin
     * kendisinden kaynaklanmayan hata (bağlantı, yetki) doğrulama durumunu
     * değiştirmez, yalnız bildirilir.
     *
     * @return string|null Hata kodu; geçtiyse null
     *
     * @throws LogicException Kilit tutulmuyor
     * @throws GeriYuklemeHatasi Başlamadı (yedek geri yüklenemez, açık üstüne geri yükleme)
     */
    public function sina(YedekKilidi $kilit, Yedek $yedek, ?int $kullaniciId = null): ?string
    {
        $this->onDenetim($kilit, $yedek);
        $zaman = CarbonImmutable::now('UTC');
        $ad = VeritabaniAdi::sinama($zaman);
        $durumYaz = $this->durumYazici($kilit, IslemTuru::SinamaGeriYuklemesi, (string) Str::uuid(), $zaman, $yedek, $ad, $kullaniciId);
        $adim = function (GeriYuklemeAdimi $a, string $d) use ($durumYaz): void {
            if ($d === 'basladi') {
                $durumYaz($a);
            }
        };
        $baslangic = hrtime(true);

        try {
            $durumYaz(GeriYuklemeAdimi::Basladi);
            $this->vt->parolaylaCalis(function () use ($yedek, $ad, $adim): void {
                $this->sinamaArtiklariniSil();
                $this->yukle($yedek, $ad, $adim, herDurumdaSil: true);
            });

            $this->dogrulamaYaz($yedek, DogrulamaDurumu::SinamaGecti, $kullaniciId);
            $durumYaz(GeriYuklemeAdimi::Bitti);
            $this->denetim->kaydet('sinama_geri_yukleme', IslemSonucu::Basarili, $yedek->id, $kullaniciId, $this->sureMs($baslangic), ['yedek' => $yedek->dosya_adi]);
            $this->sinyal->gonder(YasamSinyali::SINAMA);

            return null;
        } catch (Throwable $hata) {
            [$kod, $mesaj] = $this->hataBilgisi($hata);

            try {
                if (in_array($kod, GeriYuklemeHatasi::YEDEK_KAYNAKLI, true)) {
                    $this->dogrulamaYaz($yedek, DogrulamaDurumu::Basarisiz, $kullaniciId);
                }
                $durumYaz(GeriYuklemeAdimi::Basarisiz, ['kod' => $kod, 'mesaj' => $mesaj]);
            } catch (Throwable $yazmaHatasi) {
                report($yazmaHatasi);
            }

            $this->denetim->kaydet('sinama_geri_yukleme', IslemSonucu::Basarisiz, $yedek->id, $kullaniciId, $this->sureMs($baslangic), [
                'yedek' => $yedek->dosya_adi, 'kod' => $kod,
            ], $mesaj);
            $this->bildirimci->bildir('dogrulama_basarisiz', [
                __('yedekleme.mail.dogrulama_basarisiz').": {$yedek->dosya_adi}", "{$kod}: {$mesaj}",
            ]);

            return $kod;
        }
    }

    /**
     * parolaylaCalis() içinde çağrılır. Hata yolunda (ve sınamada her
     * durumda) açılan veritabanı silinir, indirilen geçici dosya kalmaz (T8).
     *
     * @param  Closure(GeriYuklemeAdimi, string): void  $adim
     */
    private function yukle(Yedek $yedek, string $ad, Closure $adim, bool $herDurumdaSil): void
    {
        $gecici = null;
        $olusturuldu = false;

        try {
            $adim(GeriYuklemeAdimi::Hazirlik, 'basladi');
            [$manifest, $dosya, $gecici] = $this->dosyaHazirla($yedek);
            $ozellikler = $manifest->veritabaniOzellikleri ?? $this->vt->kaynakOzellikleri();
            $ayarlar = GeriYuklemeVeritabani::ozellikleriDenetle($ozellikler);

            if ($this->vt->varMi($ad)) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::HEDEF_VAR, "{$ad} adlı veritabanı zaten var; ona dokunulmadı.");
            }
            $adim(GeriYuklemeAdimi::Hazirlik, 'tamam');

            // "Açılıyor" komuttan önce yazılır: süreç burada ölürse temizlik yarım veritabanını bulur
            $adim(GeriYuklemeAdimi::Veritabani, 'basladi');
            try {
                $this->vt->olustur($ad, $ozellikler);
            } catch (Throwable $hata) {
                // Açılmadı (ör. aynı adla başkası açtı): silinmez
                $adim(GeriYuklemeAdimi::Veritabani, 'basarisiz');

                throw $hata;
            }
            $olusturuldu = true;
            $this->vt->ayarla($ad, $ayarlar);
            $adim(GeriYuklemeAdimi::Veritabani, 'tamam');

            $adim(GeriYuklemeAdimi::PgRestore, 'basladi');
            $this->vt->geriYukle($ad, $dosya);
            $adim(GeriYuklemeAdimi::PgRestore, 'tamam');

            if ($manifest->semasiBiliniyor()) {
                $adim(GeriYuklemeAdimi::Karsilastirma, 'basladi');
                $this->karsilastir($manifest, $this->vt->icerik($ad));
                $adim(GeriYuklemeAdimi::Karsilastirma, 'tamam');
            }
        } catch (Throwable $hata) {
            if ($olusturuldu) {
                $this->sil($ad, $adim);
                $olusturuldu = false;
            }

            throw $hata;
        } finally {
            if ($herDurumdaSil && $olusturuldu) {
                $this->sil($ad, $adim);
            }
            if ($gecici !== null) {
                $this->geciciSil($gecici);
            }
        }
    }

    /**
     * İmzalı manifest + manifestle tutan dosya. Yerel dosya yoksa şifresiz
     * kopyadan geçici klasöre indirilir. Üstüne geri yükleme de bunu kullanır
     * (BY-028); geçici klasörü çağıran geciciSil() ile siler.
     *
     * @return array{0: YedekManifesti, 1: string, 2: string|null} [manifest, dosya yolu, silinecek geçici klasör]
     *
     * @throws GeriYuklemeHatasi
     */
    public function dosyaHazirla(Yedek $yedek): array
    {
        $dosyaAdi = $yedek->dosya_adi;
        if (basename($dosyaAdi) !== $dosyaAdi || ! str_ends_with($dosyaAdi, '.dump')) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::IMZA_GECERSIZ, 'Yedek kaydının dosya adı geçersiz.');
        }

        $klasor = $this->yapilandirma->klasor();
        $okuma = $this->manifestler->oku(ManifestDeposu::jsonYolu($klasor.'/'.$dosyaAdi));
        $manifest = $okuma->manifest;

        if ($manifest === null || $manifest->kimlik !== $yedek->kimlik || $manifest->dosyaAdi !== $dosyaAdi) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::IMZA_GECERSIZ, 'Yedeğin imzalı manifesti yok ya da imzası tutmuyor ('.($okuma->neden ?? 'kayıtla eşleşmiyor').'); geri yüklenmez.');
        }

        $yerel = $klasor.'/'.$dosyaAdi;
        if (file_exists($yerel) || is_link($yerel)) {
            if (is_link($yerel) || ! ManifestDeposu::dosyaTutuyorMu($manifest, $yerel)) {
                throw new GeriYuklemeHatasi(GeriYuklemeHatasi::OZET_TUTMUYOR, 'Yerel yedek dosyası manifestteki boyut / SHA-256 ile aynı değil; geri yüklenmez.');
            }

            return [$manifest, $yerel, null];
        }

        $kopyalar = YedekKopyasi::query()
            ->with('hedef')
            ->where('yedek_id', $yedek->id)
            ->where('durum', KopyaDurumu::Tamam->value)
            ->whereNotNull('uzak_kimlik')
            ->orderBy('id')
            ->get();
        $sifreliVar = false;

        foreach ($kopyalar as $kopya) {
            if ($kopya->sifreli) {
                $sifreliVar = true;

                continue;
            }

            $gecici = $this->geciciKlasor();
            $hedefYol = $gecici.'/'.$dosyaAdi;

            try {
                $this->katalog->olustur($kopya->hedef)->oku((string) $kopya->uzak_kimlik, $hedefYol);

                if (ManifestDeposu::dosyaTutuyorMu($manifest, $hedefYol)) {
                    return [$manifest, $hedefYol, $gecici];
                }

                Log::channel('yedekleme')->warning('Kopya manifestle tutmuyor; geri yüklemede kullanılmadı', ['yedek_id' => $yedek->id, 'hedef_id' => $kopya->yedek_hedefi_id]);
            } catch (Throwable $hata) {
                Log::channel('yedekleme')->warning('Kopya okunamadı; geri yüklemede kullanılmadı', ['yedek_id' => $yedek->id, 'hedef_id' => $kopya->yedek_hedefi_id, 'hata' => $hata::class]);
            }

            $this->geciciSil($gecici);
        }

        throw $sifreliVar
            ? new GeriYuklemeHatasi(GeriYuklemeHatasi::OZEL_ANAHTAR_GEREKLI, 'Yedeğin yalnız şifreli kopyası var; çözmek için özel anahtar gerekir (komut satırından yapılır).')
            : new GeriYuklemeHatasi(GeriYuklemeHatasi::YEDEK_DOSYASI_YOK, 'Yedeğin yerel dosyası da okunabilir bir kopyası da yok.');
    }

    /**
     * Geri yüklenen veritabanı manifestle aynı mı (üstüne geri yükleme de
     * migration'dan önce kullanır — BY-028).
     *
     * @param  array{tablolar: array<string, int>, migrationlar: list<string>, sahipler: list<string>}  $icerik
     *
     * @throws GeriYuklemeHatasi
     */
    public function karsilastir(YedekManifesti $manifest, array $icerik): void
    {
        $beklenen = $manifest->satirSayilari ?? [];
        $farklar = [];

        foreach (array_unique([...array_map('strval', array_keys($beklenen)), ...array_map('strval', array_keys($icerik['tablolar']))]) as $tablo) {
            if (($beklenen[$tablo] ?? null) !== ($icerik['tablolar'][$tablo] ?? null)) {
                $farklar[] = $tablo;
            }
        }

        if ($icerik['migrationlar'] !== $manifest->migrationlar) {
            $farklar[] = 'migration listesi';
        }

        // Nesnelerin sahibi uygulama rolü kalmalı (README §5.2)
        if (array_diff($icerik['sahipler'], [$this->pg->baglanti()->uygulamaRolu]) !== []) {
            $farklar[] = 'tablo sahibi';
        }

        if ($farklar !== []) {
            sort($farklar);
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI, 'Geri yüklenen veritabanı manifestle tutmuyor: '.implode(', ', array_slice($farklar, 0, 10)).(count($farklar) > 10 ? ' …' : ''));
        }
    }

    /**
     * Hata yolunda çağrılır: kendisi istisna fırlatmaz. Silinemezse adım
     * `basarisiz` kalır; kopya kaydı temizlikte yeniden denenir, sınama
     * artığı bir sonraki sınamada silinir.
     *
     * @param  Closure(GeriYuklemeAdimi, string): void  $adim
     */
    private function sil(string $ad, Closure $adim): void
    {
        $this->sessiz(fn () => $adim(GeriYuklemeAdimi::Silme, 'basladi'));

        try {
            $this->vt->dusur($ad);
        } catch (Throwable $hata) {
            $this->sessiz(fn () => $adim(GeriYuklemeAdimi::Silme, 'basarisiz'));
            Log::channel('yedekleme')->error('Geri yüklemenin açtığı veritabanı silinemedi', ['veritabani' => $ad, 'hata' => $hata::class]);

            return;
        }

        $this->sessiz(fn () => $adim(GeriYuklemeAdimi::Silme, 'tamam'));
    }

    /** Çökmüş bir sınamadan kalmış veritabanları (kilit tutulurken başka sınama çalışamaz). */
    private function sinamaArtiklariniSil(): void
    {
        try {
            foreach ($this->vt->sinamaVeritabanlari() as $artik) {
                $this->vt->dusur($artik);
                Log::channel('yedekleme')->warning('Önceki sınamadan kalan veritabanı silindi', ['veritabani' => $artik]);
            }
        } catch (Throwable $hata) {
            Log::channel('yedekleme')->warning('Sınama artıkları temizlenemedi', ['hata' => $hata::class]);
        }
    }

    private function dogrulamaYaz(Yedek $yedek, DogrulamaDurumu $durum, ?int $kullaniciId): void
    {
        $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Dogrulandi, ['durum' => $durum->value], $kullaniciId));
        $yedek->update(['dogrulama_durumu' => $durum]);
    }

    /**
     * @throws LogicException
     * @throws GeriYuklemeHatasi
     */
    private function onDenetim(YedekKilidi $kilit, Yedek $yedek): void
    {
        if (! $kilit->dosyayiTutuyorMu($this->yapilandirma->durumKlasoru().'/kilit')) {
            throw new LogicException('Geri yükleme yalnız yedekleme kilidini tutan süreç tarafından yapılır.');
        }

        try {
            $acik = $this->yapilandirma->islemDurumu()->acikGeriYuklemeVarMi();
        } catch (IslemDurumuBozuk) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ISLEM_DURUMU_OKUNAMADI, 'İşlem durumu okunamadı; geri yükleme başlamadı.');
        }

        if ($acik) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::ACIK_GERI_YUKLEME, 'Açık bir üstüne geri yükleme var; geri yükleme başlamadı.');
        }

        if (! $yedek->geriYuklenebilirMi()) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::YEDEK_GERI_YUKLENEMEZ, 'Yalnız bu sistemin imzaladığı, tamamlanmış yedek geri yüklenir.');
        }
    }

    /**
     * @return Closure(GeriYuklemeAdimi, array{kod: string, mesaj: string}|null=): void
     */
    private function durumYazici(YedekKilidi $kilit, IslemTuru $tur, string $kimlik, CarbonImmutable $baslangic, Yedek $yedek, string $ad, ?int $kullaniciId): Closure
    {
        $durum = $this->yapilandirma->islemDurumu();

        return function (GeriYuklemeAdimi $adim, ?array $hata = null) use ($durum, $kilit, $tur, $kimlik, $baslangic, $yedek, $ad, $kullaniciId): void {
            $durum->yaz(new IslemKaydi(
                kimlik: $kimlik,
                tur: $tur,
                adim: $adim->value,
                acik: false,
                baslangic: $baslangic,
                sonGuncelleme: CarbonImmutable::now('UTC'),
                hata: $hata,
                erisimAnahtariOzeti: null,
                yedekKimligi: $yedek->kimlik,
                hedefVeritabani: $ad,
                isteyenKullaniciId: $kullaniciId,
            ), $kilit);
        };
    }

    private function geciciKlasor(): string
    {
        $klasor = sys_get_temp_dir().'/emor-geri-'.bin2hex(random_bytes(6));

        if (! @mkdir($klasor, 0700)) {
            throw new RuntimeException('Geri yükleme için geçici klasör açılamadı.');
        }

        return $klasor;
    }

    public function geciciSil(string $klasor): void
    {
        foreach (array_diff(@scandir($klasor) ?: [], ['.', '..']) as $ad) {
            @unlink($klasor.'/'.$ad);
        }
        @rmdir($klasor);
    }

    private function sessiz(Closure $is): void
    {
        try {
            $is();
        } catch (Throwable $hata) {
            report($hata);
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function hataBilgisi(Throwable $hata): array
    {
        return match (true) {
            $hata instanceof GeriYuklemeHatasi => [$hata->kod, Str::limit($hata->getMessage(), 500)],
            $hata instanceof YapilandirmaHatasi, $hata instanceof ImzaAnahtariYok => [GeriYuklemeHatasi::YAPILANDIRMA, Str::limit($hata->getMessage(), 500)],
            // Beklenmeyen hatanın iletisi veri taşıyabilir: yalnız sınıf adı
            default => ['GERI_YUKLEME_BEKLENMEYEN_HATA', $hata::class],
        };
    }

    private function sureMs(int $baslangic): int
    {
        return (int) ((hrtime(true) - $baslangic) / 1_000_000);
    }
}
