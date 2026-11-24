<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekKopyasi;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Ölen süreçten kalan `calisiyor` yedek kayıtlarını sonuçlandırır (BY-007-R1;
 * README §6.1, D3, D6). Yedek yalnız kilidi tutan süreçte alınır: kilidi yeni
 * alan için `calisiyor` kaydı, hata yakalayıcısına ulaşamadan durmuş (ör.
 * SIGKILL, elektrik) bir sürecindir. Kilit altında çağrılır: YedekAlici her
 * yedekten önce, dizin taraması kilidi tutuyorsa taramadan önce.
 *
 * - Doğrulanmış dosya + imzası geçerli, kimliği aynı manifest varsa yedek
 *   TAMAMDIR: YedekAlici manifesti yalnız 1. düzey doğrulamadan sonra yazar
 *   ve dosyayı ondan sonra asıl adına taşır; süreç taşımadan hemen sonra
 *   ölmüştür. Kayıt tamamlanır, doğrulama olayı yazılır.
 * - Yoksa `basarisiz` (`YEDEK_YARIDA_KALDI`): geçici dosyalar ve dosyası
 *   olmayan manifest silinir; son başarısız deneme saklamanın temkin
 *   kuralına görünür.
 * - `bekliyor` / `kopyalaniyor` kalmış kopyalar `basarisiz` olur
 *   (`KOPYA_YARIDA_KALDI`; YF-04).
 *
 * "Dosya yok" kararı ancak doğrulanmış depoda verilir (D3, D6; BY-011-R4):
 * hiçbir kayda dokunmadan önce yedek klasörü depo işaretiyle doğrulanır
 * (YedekDeposu). Disk ayrılmışken bağlama noktası boş görünür; manifesti ve
 * dump'ı diskte yayımlanmış yedek "yarıda kaldı" sayılırdı. Doğrulanamazsa
 * hiçbir kayıt değişmez; disk geri gelince sonraki çağrı yedeği tamamlar.
 */
final class SahipsizYedekler
{
    public const YARIDA_KALDI = 'YEDEK_YARIDA_KALDI';

    public const KOPYA_YARIDA_KALDI = 'KOPYA_YARIDA_KALDI';

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly ManifestDeposu $manifestler,
        private readonly OlayGunlugu $olaylar,
        private readonly YedekDenetimi $denetim,
        private readonly YedekDeposu $depo,
    ) {}

    /**
     * @return array{tamamlanan: int, yarida_kalan: int}
     *
     * @throws LogicException Kilit tutulmuyor (başka bir süreç o anda yedek alıyor olabilir)
     * @throws DepoIsaretiYok Yedek klasörü depo olarak doğrulanamadı (hiçbir kayıt değişmedi)
     */
    public function sonuclandir(YedekKilidi $kilit): array
    {
        if (! $kilit->dosyayiTutuyorMu($this->yapilandirma->durumKlasoru().'/kilit')) {
            throw new LogicException('Sahipsiz yedek yalnız yedekleme kilidini tutan süreçte sonuçlandırılır.');
        }

        $this->depo->hazirla();

        $sayac = ['tamamlanan' => 0, 'yarida_kalan' => 0, 'yarida_kalan_kopya' => 0];

        foreach (Yedek::query()->where('durum', YedekDurumu::Calisiyor->value)->orderBy('id')->get() as $yedek) {
            if ($this->tamamla($yedek)) {
                $sayac['tamamlanan']++;
            } else {
                $this->yaridaKaldi($yedek);
                $sayac['yarida_kalan']++;
            }
        }

        // Kopya da yalnız kilit altında yapılır: kilidi yeni alan için `bekliyor` /
        // `kopyalaniyor` kopya ölen sürecindir. Sonuçlanmazsa yedek kalıcı
        // "kullanımda" sayılır (silinemez, saklama dokunmaz) ve hedefin konum
        // alanları kilitli kalır (YF-04)
        $takilanlar = YedekKopyasi::query()
            ->whereIn('durum', [KopyaDurumu::Bekliyor->value, KopyaDurumu::Kopyalaniyor->value])
            ->with(['yedek', 'hedef'])
            ->orderBy('id')
            ->get();
        foreach ($takilanlar as $kopya) {
            $this->kopyaYaridaKaldi($kopya);
            $sayac['yarida_kalan_kopya']++;
        }

        return $sayac;
    }

    private function kopyaYaridaKaldi(YedekKopyasi $kopya): void
    {
        $mesaj = 'Kopyalayan süreç işi bitirmeden durdu; kopya tamamlanmadı.';
        $kopya->fill(['durum' => KopyaDurumu::Basarisiz, 'hata_kodu' => self::KOPYA_YARIDA_KALDI, 'hata_mesaji' => $mesaj])->save();
        $this->olaylar->ekle(YedekOlayi::kopya($kopya, $kopya->yedek->kimlik, $kopya->hedef->ad));
        $this->denetim->kaydet('kopya', IslemSonucu::Basarisiz, $kopya->yedek_id, null, null, [
            'hedef' => $kopya->hedef->ad, 'kod' => self::KOPYA_YARIDA_KALDI,
        ], $mesaj);
    }

    private function tamamla(Yedek $yedek): bool
    {
        $dump = $this->yapilandirma->klasor().'/'.$yedek->dosya_adi;
        $sonuc = $this->manifestler->oku(ManifestDeposu::jsonYolu($dump));

        if (! $sonuc->guvenilirMi() || $sonuc->manifest->kimlik !== $yedek->kimlik || ! ManifestDeposu::dosyaTutuyorMu($sonuc->manifest, $dump)) {
            return false;
        }

        $manifest = $sonuc->manifest;
        $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Dogrulandi, ['durum' => DogrulamaDurumu::OzetTamam->value]));
        $yedek->update([
            'durum' => YedekDurumu::Tamam,
            'dogrulama_durumu' => DogrulamaDurumu::OzetTamam,
            'imzali' => true,
            'boyut' => $manifest->boyut,
            'sha256' => $manifest->sha256,
            'alinma_zamani' => $manifest->alinmaZamani->utc(),
            'bitti' => CarbonImmutable::now(),
        ]);
        $this->denetim->kaydet('yedek_al', IslemSonucu::Basarili, $yedek->id, $yedek->alan_kullanici_id, null, [
            'kaynak' => $yedek->kaynak->value, 'dosya' => $yedek->dosya_adi, 'sonradan_tamamlandi' => true,
        ]);

        return true;
    }

    private function yaridaKaldi(Yedek $yedek): void
    {
        $klasor = $this->yapilandirma->klasor();
        $dump = $klasor.'/'.$yedek->dosya_adi;
        $json = ManifestDeposu::jsonYolu($dump);

        // Geçici adlar AtomikDosya::geciciAd kalıbında: .<ad>.<rastgele>.tmp
        foreach ([...(glob($klasor.'/.'.$yedek->dosya_adi.'.*.tmp') ?: []), ...(glob($klasor.'/.'.basename($json).'.*.tmp') ?: [])] as $gecici) {
            @unlink($gecici);
        }

        // Dosyası taşınmadan kalan manifest (yalnız bu yedeğinki) listede yarım yedek göstermesin
        $manifest = $this->manifestler->oku($json);
        if (! is_file($dump) && $manifest->guvenilirMi() && $manifest->manifest->kimlik === $yedek->kimlik) {
            @unlink($json);
        }

        $mesaj = 'Yedeği alan süreç işi bitirmeden durdu; yedek tamamlanmadı.';
        $yedek->update(['durum' => YedekDurumu::Basarisiz, 'hata_kodu' => self::YARIDA_KALDI, 'hata_mesaji' => $mesaj, 'bitti' => CarbonImmutable::now()]);
        $this->denetim->kaydet('yedek_al', IslemSonucu::Basarisiz, $yedek->id, $yedek->alan_kullanici_id, null, [
            'kaynak' => $yedek->kaynak->value, 'kod' => self::YARIDA_KALDI,
        ], $mesaj);
    }
}
