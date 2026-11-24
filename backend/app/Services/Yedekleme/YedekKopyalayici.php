<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\YerelDiskBaglayicisi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Bir yedeği bir hedefe kopyalar (README §4.6, §6.1 6. adım; BY-008).
 *
 * - Bir yedeğin bir hedefte TEK fiziksel kopyası olur (yedek + hedef
 *   tekil); aynı hedefi paylaşan planlar o kopyaya bağlanır (F6). Kopya
 *   zaten tamamsa yeniden yüklenmez, yalnız plan bağı eklenir.
 * - Yazılan dosya hedeften geri okunup özeti karşılaştırılır; tutmazsa kopya
 *   başarısızdır. Başarısız kopya en çok `kopya_deneme` kez denenir.
 * - Sunucu dışı hedefe kopya şifrelenmeden GİTMEZ (README §4.7; KopyaSifreleyici).
 *   Başarılı sunucu dışı kopyadan sonra kurtarma paketi de o hedefe gider
 *   (KurtarmaPaketi; BY-024); onun hatası kopyayı başarısız saymaz. Kopya
 *   `tamam` olur (aktarım doğrulandı) ama ancak yedeğin geçerli manifestini
 *   taşıyan paket doğrulanınca KURTARMAYA HAZIR olur (`kurtarma_hazir`;
 *   BY-010-R2): saklama yerel arşivi buna göre siler. Uzak yaşam sinyali
 *   yalnız paket tamamlanınca VE kopya kurtarmaya hazırsa gider (BY-024-R7).
 * - Aynı fiziksel depoyu gösteren başka bir hedefte (aynı klasör; uzakta aynı
 *   sunucu + kullanıcı + klasör ya da S3 uç noktası + kova + önek) kopyası
 *   duran yedek bu hedefe yazılmaz: kopya `basarisiz`, kod `HEDEF_AYNI_DEPO`
 *   (HedefKonumu::ayniDepoMu; BY-010-R7, R8).
 * - Bir hedefin hatası istisna olarak dışarı taşmaz: kopya `basarisiz`
 *   olur, diğer hedefler ve yedeğin kendisi etkilenmez.
 * - Sonuç olay günlüğüne ve denetim kaydına yazılır.
 */
final class YedekKopyalayici
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly HedefKatalogu $katalog,
        private readonly OlayGunlugu $olaylar,
        private readonly YedekDenetimi $denetim,
        private readonly KopyaSifreleyici $sifreleyici,
        private readonly YedekBildirimcisi $bildirimci,
        private readonly YasamSinyali $sinyal,
        private readonly KurtarmaPaketi $kurtarma,
        private readonly HedefKonumu $konum,
    ) {}

    /**
     * @param  list<int>  $planIdleri  Kopyayı tutacak planlar (bu hedefi kullanan)
     */
    public function kopyala(Yedek $yedek, YedekHedefi $hedef, array $planIdleri = [], ?int $kullaniciId = null): YedekKopyasi
    {
        $kopya = YedekKopyasi::query()->firstOrNew(
            ['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id],
            ['durum' => KopyaDurumu::Bekliyor, 'deneme' => 0],
        );

        if ($kopya->exists && $kopya->durum === KopyaDurumu::Tamam) {
            $this->planlariBagla($kopya, $hedef, $planIdleri);

            return $kopya;
        }

        $baslangic = hrtime(true);
        // Kopya, hedef güncellemesinin konum denetimiyle aynı satır kilidi altında "kopyalanıyor" olur
        // ve hedefin o anki (kayıtlı) konumuna gider: denetim ile kayıt arasında başlayamaz (BY-016-R3)
        DB::transaction(function () use ($kopya, $hedef): void {
            $guncel = YedekHedefi::query()->whereKey($hedef->getKey())->lockForUpdate()->first();
            if ($guncel !== null) {
                $hedef->setRawAttributes($guncel->getAttributes(), true);
            }
            // Yeniden yazılan kopya, onu taşıyan yeni bir paket doğrulanana kadar kurtarmaya hazır değildir (BY-010-R2)
            $kopya->fill(['durum' => KopyaDurumu::Kopyalaniyor, 'hata_kodu' => null, 'hata_mesaji' => null, 'kurtarma_hazir' => false])->save();
        });
        $denemeSiniri = $this->yapilandirma->kopyaDenemeSayisi();
        $sonHata = null;

        for ($deneme = 1; $deneme <= $denemeSiniri; $deneme++) {
            $kopya->increment('deneme');

            try {
                [$uzakKimlik, $sifreli, $sifreliOzet, $konumIzi] = $this->bir($yedek, $hedef);

                $kopya->fill([
                    'durum' => KopyaDurumu::Tamam,
                    'uzak_kimlik' => $uzakKimlik,
                    'sifreli' => $sifreli,
                    'sifreli_sha256' => $sifreliOzet,
                    'konum_izi' => $konumIzi,
                    'tamamlanma_zamani' => CarbonImmutable::now(),
                    'silinme_zamani' => null,
                ])->save();
                $this->planlariBagla($kopya, $hedef, $planIdleri);
                $this->olayYaz($yedek, $hedef, $kopya);
                $this->denetim->kaydet('kopya', IslemSonucu::Basarili, $yedek->id, $kullaniciId, $this->sureMs($baslangic), [
                    'hedef' => $hedef->ad, 'deneme' => $deneme, 'sifreli' => $sifreli,
                ]);
                // Kurtarma paketinin hatası dışarı taşmaz, kopyayı başarısız saymaz (BY-024).
                // Uzak yaşam sinyali yalnız paket tamamlanıp doğrulandıktan sonra (BY-024-R1)
                // ve BU kopya paketin güncellediği hazırlıkla kurtarılabiliyorsa gider: başarılı
                // paket, yedeğin manifesti geçersizse onu doğrulamaz (BY-024-R7; nedeni paketin
                // denetim kaydında `yedek_manifesti_dogrulandi`, ekranda "kurtarma paketi bekleniyor").
                if ($sifreli && $this->kurtarma->gonder($hedef, $yedek->id, $kullaniciId) && $kopya->refresh()->kurtarma_hazir) {
                    $this->sinyal->gonder(YasamSinyali::UZAK);
                }

                return $kopya;
            } catch (Throwable $hata) {
                $sonHata = $hata;

                // Yapılandırma / şifreleme anahtarı eksikliği yeniden denemeyle düzelmez (D8).
                // gpg süre aşımı (SifrelemeHatasi, gecici) kalıcı sayılmaz: sınırlı denenir (BY-019-R1).
                if ($hata instanceof HedefHatasi && in_array($hata->kod, [HedefHatasi::YAPILANDIRMA, HedefHatasi::KIMLIK, HedefHatasi::YENIDEN_BAGLAN, HedefHatasi::SUNUCU_PARMAK_IZI, HedefHatasi::SERTIFIKA, HedefHatasi::BAGLI_DEGIL, HedefHatasi::KOTA, HedefHatasi::AYNI_DEPO, HedefHatasi::KLASOR_SECILMEDI, HedefHatasi::KLASOR_GECERSIZ], true)) {
                    break;
                }

                if ($deneme < $denemeSiniri) {
                    Sleep::for($deneme * 2)->seconds();
                }
            }
        }

        [$kod, $mesaj] = $sonHata instanceof HedefHatasi || $sonHata instanceof SifrelemeHatasi
            ? [$sonHata->kod, Str::limit($sonHata->getMessage(), 500)]
            : ['KOPYA_BEKLENMEYEN_HATA', $sonHata === null ? '' : $sonHata::class];

        $kopya->fill(['durum' => KopyaDurumu::Basarisiz, 'hata_kodu' => $kod, 'hata_mesaji' => $mesaj])->save();
        $this->olayYaz($yedek, $hedef, $kopya);
        $this->denetim->kaydet('kopya', IslemSonucu::Basarisiz, $yedek->id, $kullaniciId, $this->sureMs($baslangic), [
            'hedef' => $hedef->ad, 'kod' => $kod,
        ], $mesaj);
        $this->bildirimci->bildir('kopya_basarisiz', ["{$hedef->ad}: {$yedek->dosya_adi}", "{$kod}: {$mesaj}"], 'kopya:'.$hedef->id);

        return $kopya;
    }

    /**
     * Bir deneme: (gerekirse şifrele) → yaz → geri oku → özeti karşılaştır.
     * Kopya hedefin kilit altında okunmuş konumuna gider; o konumun izi kopyayla
     * birlikte saklanır. Konumu bilinemeyen hedefe (hesabı okunmamış Drive
     * bağlantısı) kopya gönderilmez: yeri doğrulanamayan kopya kullanılamaz
     * (BY-028-R5, BY-021-R3).
     *
     * @return array{0: string, 1: bool, 2: string|null, 3: string} [uzak kimlik, şifreli mi, şifreli kopyanın özeti, konum izi]
     */
    private function bir(Yedek $yedek, YedekHedefi $hedef): array
    {
        $konumIzi = $this->konum->iz($hedef)
            ?? throw ($hedef->tur === HedefTuru::GoogleDrive && is_string($hedef->baglanti['eposta'] ?? null)
                ? new HedefHatasi(HedefHatasi::KLASOR_SECILMEDI, 'Google Drive klasörü seçilmedi; Yedek Hedefleri\'nde "Klasör seç" ile seçin. Kopyalanmadı.')
                : new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Hedefin konumu belirlenemiyor (bağlı hesap bilinmiyor); hedefi yeniden bağlayın. Kopyalanmadı.'));
        $this->ayniDepoyaYazma($yedek, $hedef);
        $kaynak = $this->yapilandirma->klasor().'/'.$yedek->dosya_adi;

        if (! is_file($kaynak)) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Yedeğin yerel dosyası yok; kopyalanamaz.');
        }

        if ($yedek->sha256 === null || ! hash_equals($yedek->sha256, (string) hash_file('sha256', $kaynak))) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Yerel yedek dosyası manifestteki özetle aynı değil; kopyalanmadı.');
        }

        $baglayici = $this->katalog->olustur($hedef);
        $sunucuDisi = $this->katalog->sunucuDisiMi($hedef->tur);
        // Şifreli dosya ve geri okunan kopya döküm boyutundadır: bellekteki /tmp'ye değil yedek diskine (YF-28)
        $calisma = GeciciKlasor::ac($this->yapilandirma->calismaKlasoru(), 'emor-kopya-', $this->yapilandirma->artikKlasorYasi());

        try {
            $yuklenecek = $kaynak;
            $ad = $yedek->dosya_adi;
            $sifreliOzet = null;

            if ($sunucuDisi) {
                $yuklenecek = $this->sifreleyici->sifrele($kaynak, $calisma.'/'.$ad.'.gpg');
                $ad .= '.gpg';
                $sifreliOzet = (string) hash_file('sha256', $yuklenecek);
            }

            $beklenenOzet = $sifreliOzet ?? $yedek->sha256;
            $uzakKimlik = $baglayici->yaz($yuklenecek, $ad);

            try {
                $geri = $calisma.'/geri';
                $baglayici->oku($uzakKimlik, $geri);
                $okunanOzet = is_file($geri) ? hash_file('sha256', $geri) : false;

                if (! is_string($okunanOzet) || ! hash_equals($beklenenOzet, $okunanOzet)) {
                    throw new HedefHatasi(HedefHatasi::OKUNAMADI, 'Hedeften geri okunan kopyanın özeti yazılanla aynı değil.');
                }
            } catch (Throwable $hata) {
                $this->dogrulanmayaniSil($baglayici, $uzakKimlik);

                throw $hata;
            }

            return [$uzakKimlik, $sunucuDisi, $sifreliOzet, $konumIzi];
        } finally {
            GeciciKlasor::sil($calisma);
        }
    }

    /**
     * Doğrulanamayan yükleme hedefte kayıtsız kalmasın (YF-08): kimliği hiçbir
     * kayda yazılmaz, saklama ona hiç ulaşamaz; her yazımda yeni kimlik açan
     * hedefte (Drive) her deneme bir tam kopya bırakırdı. En iyi çaba: silme
     * hatası asıl hatayı gizlemez. Ana klasör Yerel hedefinde silinmez — oradaki
     * "kopya" yedeğin yerel dosyasının kendisidir.
     */
    private function dogrulanmayaniSil(HedefBaglayicisi $baglayici, string $uzakKimlik): void
    {
        if ($baglayici instanceof YerelDiskBaglayicisi && $baglayici->anaKlasorMu()) {
            return;
        }

        try {
            $baglayici->sil($uzakKimlik);
        } catch (Throwable) {
            // Silinemeyen (yetki yok, erişilemiyor) dosya kalır; asıl hata yine bildirilir
        }
    }

    /**
     * Aynı fiziksel depoya yazan başka bir hedefte bu yedeğin kopyası duruyorsa
     * (ya da yazılıyorsa) ikinci kopya yazılmaz (BY-010-R7): aynı dosyanın
     * üstüne yazılır ve iki bağımsız kopya sanılırdı. Kalıcı hatadır; hedef
     * tanımı düzeltilene kadar yeniden denenmez. Yeni çakışan hedef API'de
     * reddedilir, bu eskiden kalmış (ya da geri yüklenmiş) tanımlar içindir.
     *
     * @throws HedefHatasi
     */
    private function ayniDepoyaYazma(Yedek $yedek, YedekHedefi $hedef): void
    {
        $ortak = YedekKopyasi::query()->with('hedef')
            ->where('yedek_id', $yedek->id)
            ->where('yedek_hedefi_id', '!=', $hedef->id)
            ->whereIn('durum', [...KopyaDurumu::yedegiTutanlar(), KopyaDurumu::Kopyalaniyor->value])
            ->get()
            ->first(fn (YedekKopyasi $k): bool => $k->hedef !== null && $this->konum->ayniDepoMu($hedef, $k->hedef));

        if ($ortak !== null) {
            throw new HedefHatasi(HedefHatasi::AYNI_DEPO, "Hedef \"{$ortak->hedef->ad}\" hedefiyle aynı depoya yazıyor ve yedeğin kopyası orada; ikinci kopya sayılmaz, yazılmadı.");
        }
    }

    /**
     * @param  list<int>  $planIdleri
     */
    private function planlariBagla(YedekKopyasi $kopya, YedekHedefi $hedef, array $planIdleri): void
    {
        if ($planIdleri === []) {
            return;
        }

        $kopya->planlar()->syncWithoutDetaching(array_fill_keys($planIdleri, ['yedek_hedefi_id' => $hedef->id]));
    }

    private function olayYaz(Yedek $yedek, YedekHedefi $hedef, YedekKopyasi $kopya): void
    {
        $this->olaylar->ekle(YedekOlayi::kopya($kopya, $yedek->kimlik, $hedef->ad));
    }

    private function sureMs(int $baslangic): int
    {
        return (int) ((hrtime(true) - $baslangic) / 1_000_000);
    }
}
