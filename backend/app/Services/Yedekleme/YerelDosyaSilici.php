<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekKopyasi;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Yedek klasöründeki dosyanın silinmesi (kullanıcı kararı F7; README §4.2).
 * Silme KARARI burada verilmez: otomatik silme yalnız saklamadadır
 * (`SaklamaPolitikasi` — zorunlu hedefler ve koruma kuralları; BY-010-R1),
 * elle silme `SilIsleyicisi`'ndedir. Bu sınıf kararın sonucunu uygular:
 *
 * - Yalnız `.dump` silinir; imzalı manifest (`.json`) yerinde KALIR: dizin
 *   yeniden kurulurken uzak kopyası olan yedek manifestinden bulunur (BY-011).
 * - Başka bir hedefte doğrulanmış (`tamam`) ya da yeri doğrulanamayan
 *   (`konum_belirsiz`: dosya bir yerde durabilir) kopya varsa yedek `silindi`
 *   olmaz ve "tamamen silindi" olayı yazılmaz, yalnız `yerel_silinme_zamani`
 *   dolar (BY-028-R7). Hiçbir kopya kalmıyorsa `silindi`. Yedek klasörünün
 *   kendisi olan yerel hedefin kopyası aynı dosyadır; çağıran onu önce
 *   `silindi` yapar (KopyaSilmeKaydi).
 */
final class YerelDosyaSilici
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly OlayGunlugu $olaylar,
    ) {}

    public function sil(Yedek $yedek): void
    {
        $uzakKopyaKaldi = YedekKopyasi::query()
            ->where('yedek_id', $yedek->id)
            ->whereIn('durum', KopyaDurumu::yedegiTutanlar())
            ->whereNotNull('uzak_kimlik')
            ->exists();

        $yol = $this->yapilandirma->klasor().'/'.$yedek->dosya_adi;
        if (file_exists($yol) && ! @unlink($yol)) {
            throw new RuntimeException("Yerel yedek dosyası silinemedi: {$yedek->dosya_adi}");
        }

        $simdi = CarbonImmutable::now();
        $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::YerelSilindi));
        $yedek->update(['yerel_silinme_zamani' => $yedek->yerel_silinme_zamani ?? $simdi]);

        if (! $uzakKopyaKaldi) {
            $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Silindi));
            $yedek->update(['durum' => YedekDurumu::Silindi, 'silinme_zamani' => $simdi]);
        }
    }
}
