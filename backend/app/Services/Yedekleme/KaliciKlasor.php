<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Kesintiye dayanıklı klasör işlemleri (Dikkat_Edilecekler D3): yeni klasör
 * ya da dosya girdisi ancak üst klasörü diske zorlanınca kalıcıdır (fsync(2)).
 * Kilit ve işlem durumu aynı klasörü açabilir; ikisi de buradan açar ki ilk
 * açan hangisi olursa olsun üst girdiler eşitlensin (BY-005-R8).
 */
final class KaliciKlasor
{
    /**
     * Klasörü (gerekirse üstleriyle) 0750 açar; yeni açılan her klasörün üst
     * klasörünü diske zorlar. Klasör zaten varsa hiçbir şey yapmaz.
     *
     * @throws RuntimeException Açılamadı ya da eşitlenemedi
     */
    public static function ac(string $klasor): void
    {
        if (is_dir($klasor)) {
            return;
        }

        $yeniler = [];
        for ($k = $klasor; ! is_dir($k) && dirname($k) !== $k; $k = dirname($k)) {
            $yeniler[] = $k;
        }

        if (! @mkdir($klasor, 0750, true) && ! is_dir($klasor)) {
            throw new RuntimeException("Yedekleme klasörü açılamadı: {$klasor}");
        }

        foreach (array_reverse($yeniler) as $yeni) {
            self::esitle(dirname($yeni));
        }
    }

    /**
     * Klasör girdilerini diske zorlar; olmazsa başarı sayılmaz.
     *
     * @throws RuntimeException
     */
    public static function esitle(string $klasor): void
    {
        $tutamac = @fopen($klasor, 'r');
        $esitlendi = $tutamac !== false && fsync($tutamac);

        if ($tutamac !== false) {
            fclose($tutamac);
        }

        if (! $esitlendi) {
            throw new RuntimeException("Yedekleme klasörü diske eşitlenemedi: {$klasor} (yazılan kesintide kaybolabilir).");
        }
    }
}
