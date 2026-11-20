<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekKopyasi;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Yedek klasöründeki dosyanın silinmesi (kullanıcı kararı F7; README §4.2):
 *
 * - Yalnız `.dump` silinir; imzalı manifest (`.json`) yerinde KALIR: dizin
 *   yeniden kurulurken uzak kopyası olan yedek manifestinden bulunur (BY-011).
 * - Başka bir hedefte doğrulanmış (`tamam`) kopya varsa yedek `silindi`
 *   olmaz, `yerel_silinme_zamani` dolar. Hiçbir kopya kalmıyorsa `silindi`.
 *   Yedek klasörünün kendisi olan yerel hedefin kopyası aynı dosyadır;
 *   `haricHedefId` ile sayılmaz.
 * - `uzakKopyaSart` true iken (planın hedefinde yerel disk yok) uzakta
 *   doğrulanmış kopya yoksa yerel dosya SİLİNMEZ.
 */
final class YerelDosyaSilici
{
    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly OlayGunlugu $olaylar,
    ) {}

    /**
     * @return bool Dosya silindi mi
     */
    public function sil(Yedek $yedek, bool $uzakKopyaSart, ?int $haricHedefId = null): bool
    {
        $uzakTamam = YedekKopyasi::query()
            ->where('yedek_id', $yedek->id)
            ->where('durum', KopyaDurumu::Tamam->value)
            ->when($haricHedefId !== null, fn ($q) => $q->where('yedek_hedefi_id', '!=', $haricHedefId))
            ->whereNotNull('uzak_kimlik')
            ->exists();

        if ($uzakKopyaSart && ! $uzakTamam) {
            return false;
        }

        $yol = $this->yapilandirma->klasor().'/'.$yedek->dosya_adi;
        if (file_exists($yol) && ! @unlink($yol)) {
            throw new RuntimeException("Yerel yedek dosyası silinemedi: {$yedek->dosya_adi}");
        }

        $simdi = CarbonImmutable::now();
        $yedek->update(['yerel_silinme_zamani' => $yedek->yerel_silinme_zamani ?? $simdi]);
        $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::YerelSilindi));

        if (! $uzakTamam) {
            $yedek->update(['durum' => YedekDurumu::Silindi, 'silinme_zamani' => $simdi]);
            $this->olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Silindi));
        }

        return true;
    }
}
