<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekKopyasi;
use Carbon\CarbonImmutable;

/**
 * Bir kopyanın hedefte artık tutulmadığının kaydı (olay + dizin) — elle silme
 * (`SilIsleyicisi`) ve saklama (`SaklamaPolitikasi`) aynı adımı kullanır
 * (BY-025-R3, K10). Silme KARARI çağıranda kalır; burası yalnız sonucu yazar.
 *
 * - Önce imzalı `kopya_silindi` olayı (doğruluk kaynağı), sonra dizin: dizin
 *   yeniden kurulunca (BY-011) kopya geri gelmez. Olay silinen nesnenin uzak
 *   kimliğini ve konum izini taşır: yeniden kurmada yalnız o nesneye
 *   uygulanır — kayıt konum iziyle bu hedefe bağlanmış başka bir olay
 *   hedefinin kopyasıysa ona, aynı hedefteki farklı nesneye değil
 *   (GuncelDurum::silinenler; BY-028-R10).
 * - `hataKodu` doluysa kopya fiziksel olarak silinmemiş, bizden çıkmış
 *   sayılmıştır (ör. `HEDEF_SILME_YETKISI_YOK`: hedefin kendi saklama kuralı
 *   temizler — BY-023-R1). Kod olayda da durur; yeniden kurulan kopya onu taşır.
 * - Yerel dosyası da gitmiş ve başka tamamlanmış ya da yeri doğrulanamayan
 *   (`konum_belirsiz`) kopyası kalmamış yedek `silindi` olur (F7; BY-028-R7).
 */
final class KopyaSilmeKaydi
{
    public function __construct(private readonly OlayGunlugu $olaylar) {}

    public function kaydet(YedekKopyasi $kopya, ?string $hataKodu = null): void
    {
        $yedek = Yedek::query()->findOrFail($kopya->yedek_id);

        $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::KopyaSilindi, array_filter([
            'hedef_id' => $kopya->yedek_hedefi_id,
            'hedef_ad' => $kopya->hedef?->ad,
            'uzak_kimlik' => $kopya->uzak_kimlik,
            'konum_izi' => $kopya->konum_izi,
            'hata_kodu' => $hataKodu,
        ], fn (mixed $deger): bool => $deger !== null)));
        $kopya->update(['durum' => KopyaDurumu::Silindi, 'hata_kodu' => $hataKodu, 'silinme_zamani' => CarbonImmutable::now()]);

        $kalan = YedekKopyasi::query()->where('yedek_id', $yedek->id)->whereIn('durum', KopyaDurumu::yedegiTutanlar())->exists();
        if (! $kalan && $yedek->yerel_silinme_zamani !== null && $yedek->durum !== YedekDurumu::Silindi) {
            $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Silindi));
            $yedek->update(['durum' => YedekDurumu::Silindi, 'silinme_zamani' => CarbonImmutable::now()]);
        }
    }
}
