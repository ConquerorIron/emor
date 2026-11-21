<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use Carbon\CarbonImmutable;
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
 *   (KurtarmaPaketi; BY-024); onun hatası kopyayı başarısız saymaz ama uzak
 *   yaşam sinyali yalnız paket tamamlanınca gönderilir.
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
        $kopya->fill(['durum' => KopyaDurumu::Kopyalaniyor, 'hata_kodu' => null, 'hata_mesaji' => null])->save();
        $denemeSiniri = $this->yapilandirma->kopyaDenemeSayisi();
        $sonHata = null;

        for ($deneme = 1; $deneme <= $denemeSiniri; $deneme++) {
            $kopya->increment('deneme');

            try {
                [$uzakKimlik, $sifreli, $sifreliOzet] = $this->bir($yedek, $hedef);

                $kopya->fill([
                    'durum' => KopyaDurumu::Tamam,
                    'uzak_kimlik' => $uzakKimlik,
                    'sifreli' => $sifreli,
                    'sifreli_sha256' => $sifreliOzet,
                    'tamamlanma_zamani' => CarbonImmutable::now(),
                    'silinme_zamani' => null,
                ])->save();
                $this->planlariBagla($kopya, $hedef, $planIdleri);
                $this->olayYaz($yedek, $hedef, $kopya);
                $this->denetim->kaydet('kopya', IslemSonucu::Basarili, $yedek->id, $kullaniciId, $this->sureMs($baslangic), [
                    'hedef' => $hedef->ad, 'deneme' => $deneme, 'sifreli' => $sifreli,
                ]);
                // Kurtarma paketinin hatası dışarı taşmaz, kopyayı başarısız saymaz (BY-024).
                // Uzak yaşam sinyali yalnız paket de tamamlanıp doğrulanınca gider: boş
                // sunucuda kurtarma, yedekle birlikte paketin manifestlerini ister (BY-024-R1).
                if ($sifreli && $this->kurtarma->gonder($hedef, $yedek->id, $kullaniciId)) {
                    $this->sinyal->gonder(YasamSinyali::UZAK);
                }

                return $kopya;
            } catch (Throwable $hata) {
                $sonHata = $hata;

                // Yapılandırma / şifreleme anahtarı eksikliği yeniden denemeyle düzelmez (D8).
                // gpg süre aşımı (SifrelemeHatasi, gecici) kalıcı sayılmaz: sınırlı denenir (BY-019-R1).
                if ($hata instanceof HedefHatasi && in_array($hata->kod, [HedefHatasi::YAPILANDIRMA, HedefHatasi::KIMLIK, HedefHatasi::YENIDEN_BAGLAN, HedefHatasi::SUNUCU_PARMAK_IZI, HedefHatasi::SERTIFIKA, HedefHatasi::BAGLI_DEGIL, HedefHatasi::KOTA], true)) {
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
     *
     * @return array{0: string, 1: bool, 2: string|null} [uzak kimlik, şifreli mi, şifreli kopyanın özeti]
     */
    private function bir(Yedek $yedek, YedekHedefi $hedef): array
    {
        $kaynak = $this->yapilandirma->klasor().'/'.$yedek->dosya_adi;

        if (! is_file($kaynak)) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Yedeğin yerel dosyası yok; kopyalanamaz.');
        }

        if ($yedek->sha256 === null || ! hash_equals($yedek->sha256, (string) hash_file('sha256', $kaynak))) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Yerel yedek dosyası manifestteki özetle aynı değil; kopyalanmadı.');
        }

        $baglayici = $this->katalog->olustur($hedef);
        $sunucuDisi = $this->katalog->sunucuDisiMi($hedef->tur);
        $calisma = sys_get_temp_dir().'/emor-kopya-'.bin2hex(random_bytes(6));
        mkdir($calisma, 0700);

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

            $geri = $calisma.'/geri';
            $baglayici->oku($uzakKimlik, $geri);
            $okunanOzet = is_file($geri) ? hash_file('sha256', $geri) : false;

            if (! is_string($okunanOzet) || ! hash_equals($beklenenOzet, $okunanOzet)) {
                throw new HedefHatasi(HedefHatasi::OKUNAMADI, 'Hedeften geri okunan kopyanın özeti yazılanla aynı değil.');
            }

            return [$uzakKimlik, $sunucuDisi, $sifreliOzet];
        } finally {
            foreach (glob($calisma.'/*') ?: [] as $dosya) {
                @unlink($dosya);
            }
            @rmdir($calisma);
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
        $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Kopya, [
            'hedef_id' => $hedef->id,
            'hedef_ad' => $hedef->ad,
            'durum' => $kopya->durum->value,
            'uzak_kimlik' => $kopya->uzak_kimlik,
            'sifreli' => $kopya->sifreli,
            'sifreli_sha256' => $kopya->sifreli_sha256,
            'plan_idleri' => array_map('intval', $kopya->planlar()->pluck('yedek_planlari.id')->all()),
        ]));
    }

    private function sureMs(int $baslangic): int
    {
        return (int) ((hrtime(true) - $baslangic) / 1_000_000);
    }
}
