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
 *   yeniden kurulunca (BY-011) kopya geri gelmez.
 * - `hataKodu` doluysa kopya fiziksel olarak silinmemiş, bizden çıkmış
 *   sayılmıştır (ör. `HEDEF_SILME_YETKISI_YOK`: hedefin kendi saklama kuralı
 *   temizler — BY-023-R1). Kod olayda da durur; yeniden kurulan kopya onu taşır.
 * - Yerel dosyası da gitmiş ve başka tamamlanmış kopyası kalmamış yedek
 *   `silindi` olur (F7).
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
            'hata_kodu' => $hataKodu,
        ], fn (mixed $deger): bool => $deger !== null)));
        $kopya->update(['durum' => KopyaDurumu::Silindi, 'hata_kodu' => $hataKodu, 'silinme_zamani' => CarbonImmutable::now()]);

        $kalan = YedekKopyasi::query()->where('yedek_id', $yedek->id)->where('durum', KopyaDurumu::Tamam->value)->exists();
        if (! $kalan && $yedek->yerel_silinme_zamani !== null && $yedek->durum !== YedekDurumu::Silindi) {
            $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Silindi));
            $yedek->update(['durum' => YedekDurumu::Silindi, 'silinme_zamani' => CarbonImmutable::now()]);
        }
    }
}
