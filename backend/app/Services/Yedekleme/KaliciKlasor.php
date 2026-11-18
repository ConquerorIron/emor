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
     * Klasörü (gerekirse üstleriyle) 0750 açar ve klasörden köke kadar her
     * girdinin üst klasörünü diske zorlar — klasör ZATEN VARSA da: önceki bir
     * deneme klasörü açıp eşitlemede kalmış olabilir; "var" demek "kalıcı"
     * demek değildir (BY-005-R9). Klasör fsync'i ucuzdur, kilit alımı ve
     * durum yazımı seyrektir.
     *
     * @throws RuntimeException Açılamadı ya da eşitlenemedi
     */
    public static function ac(string $klasor): void
    {
        if (! is_dir($klasor) && ! @mkdir($klasor, 0750, true) && ! is_dir($klasor)) {
            throw new RuntimeException("Yedekleme klasörü açılamadı: {$klasor}");
        }

        for ($k = $klasor; dirname($k) !== $k; $k = dirname($k)) {
            self::esitle(dirname($k));
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
