<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use JsonException;
use RuntimeException;

/**
 * Yedek klasöründeki salt eklemeli olay günlüğü (`olaylar.jsonl`; README
 * §5.3): satır başına `{"olay": {...}, "imza": "<hex>"}`. Veritabanı geri
 * yüklense de buradaki sabitleme, doğrulama, kopya ve silinme bilgisi kalır;
 * dizin yeniden kurulurken manifestle birlikte okunur (BY-011).
 *
 * Ekleme `flock` altında, tek `fwrite` ve `fsync` ile yapılır: eşzamanlı iki
 * yazar satırları karıştırmaz, eklenen satır elektrik kesintisinde kaybolmaz.
 * Okumada imzası tutmayan ya da bozuk satır atlanır ve sayılır; günlüğün geri
 * kalanı okunur. Sınıf satır silmez / değiştirmez (salt eklemeli, K3).
 */
final class OlayGunlugu
{
    public const DOSYA = 'olaylar.jsonl';

    public function __construct(
        private readonly YedekImzasi $imza,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    public function yol(): string
    {
        return $this->yapilandirma->klasor().'/'.self::DOSYA;
    }

    /**
     * @throws ImzaAnahtariYok
     * @throws RuntimeException
     */
    public function ekle(YedekOlayi $olay): void
    {
        $veri = $olay->diziye();
        $satir = json_encode(
            ['olay' => $veri, 'imza' => $this->imza->imzala(json_decode(json_encode($veri, JSON_THROW_ON_ERROR), true))],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )."\n";

        $yol = $this->yol();
        $yeni = ! file_exists($yol);
        $tutamac = @fopen($yol, 'ab');

        if ($tutamac === false) {
            throw new RuntimeException("Olay günlüğü açılamadı: {$yol}");
        }

        try {
            if ($yeni) {
                @chmod($yol, 0640);
            }

            $yazildi = flock($tutamac, LOCK_EX)
                && fwrite($tutamac, $satir) === strlen($satir)
                && fflush($tutamac)
                && fsync($tutamac);

            if (! $yazildi) {
                throw new RuntimeException("Olay günlüğüne yazılamadı: {$yol}");
            }
        } finally {
            flock($tutamac, LOCK_UN);
            fclose($tutamac);
        }

        if ($yeni) {
            KaliciKlasor::esitle(dirname($yol));
        }
    }

    /**
     * @return array{olaylar: list<YedekOlayi>, gecersiz: int}
     */
    public function oku(): array
    {
        $yol = $this->yol();

        if (! is_file($yol)) {
            return ['olaylar' => [], 'gecersiz' => 0];
        }

        $tutamac = @fopen($yol, 'rb');
        if ($tutamac === false) {
            throw new RuntimeException("Olay günlüğü okunamadı: {$yol}");
        }

        $olaylar = [];
        $gecersiz = 0;

        try {
            flock($tutamac, LOCK_SH);

            while (($satir = fgets($tutamac)) !== false) {
                if (trim($satir) === '') {
                    continue;
                }

                $olay = $this->satiriCoz($satir);
                if ($olay === null) {
                    $gecersiz++;
                } else {
                    $olaylar[] = $olay;
                }
            }
        } finally {
            flock($tutamac, LOCK_UN);
            fclose($tutamac);
        }

        return ['olaylar' => $olaylar, 'gecersiz' => $gecersiz];
    }

    /**
     * Yedek kimliğine göre gruplanmış güncel durumlar.
     *
     * @return array<string, GuncelDurum>
     */
    public function durumlar(): array
    {
        $gruplar = [];
        foreach ($this->oku()['olaylar'] as $olay) {
            $gruplar[$olay->yedekKimligi][] = $olay;
        }

        return array_map(GuncelDurum::hesapla(...), $gruplar);
    }

    private function satiriCoz(string $satir): ?YedekOlayi
    {
        try {
            $veri = json_decode($satir, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($veri) || ! is_array($veri['olay'] ?? null) || count($veri) !== 2) {
            return null;
        }

        try {
            if (! $this->imza->dogrula($veri['olay'], $veri['imza'] ?? null)) {
                return null;
            }
        } catch (ImzaAnahtariYok) {
            return null;
        }

        return YedekOlayi::diziden($veri['olay']);
    }
}
