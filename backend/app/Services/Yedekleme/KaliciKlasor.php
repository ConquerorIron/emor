<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use InvalidArgumentException;
use RuntimeException;

/**
 * Kesintiye dayanıklı klasör işlemleri (Dikkat_Edilecekler D3): yeni klasör
 * ya da dosya girdisi ancak üst klasörü diske zorlanınca kalıcıdır (fsync(2)).
 * Kilit ve işlem durumu aynı klasörü açabilir; ikisi de buradan açar ki ilk
 * açan hangisi olursa olsun üst girdiler eşitlensin (BY-005-R8).
 *
 * Kalıcı kök: kurulumun açıp diske eşitlediği klasör (sunucuda
 * `/var/lib/emor-yedek/durum` — BY-003). Eşitleme zinciri orada durur. Kökün
 * üstü uygulamanın işi değildir; yalnız geçilebilir (okumaya kapalı) olabilir
 * ve o zaman eşitleme için açılamaz (BY-005-R11). Kökün altı ise uygulamanın
 * açtığı girdilerdir ve her seferinde eşitlenir.
 */
final class KaliciKlasor
{
    /** Mutlak, sade yol: `//`, sonda `/`, `.` ya da `..` parçası yok */
    private const SADE_YOL = '#^(?:/(?!\.\.?(?:/|$))[^/]+)+$#D';

    /**
     * $klasor kalıcı kökün kendisi ya da altında mı; değilse açılan girdiyi
     * kimse eşitlemezdi.
     *
     * @throws InvalidArgumentException
     */
    public static function kokAltindaOlmali(string $klasor, string $kaliciKok): void
    {
        $sade = preg_match(self::SADE_YOL, $klasor) === 1 && preg_match(self::SADE_YOL, $kaliciKok) === 1;

        if (! $sade || ($klasor !== $kaliciKok && ! str_starts_with($klasor, $kaliciKok.'/'))) {
            throw new InvalidArgumentException("Yedekleme klasörü ({$klasor}) kalıcı kökün ({$kaliciKok}) altında, mutlak ve sade bir yol olmalı.");
        }
    }

    /**
     * Klasörü (gerekirse kalıcı köke kadar üstleriyle) 0750 açar ve klasörden
     * köke kadar her girdinin üst klasörünü diske zorlar — klasör ZATEN VARSA
     * da: önceki bir deneme klasörü açıp eşitlemede kalmış olabilir; "var"
     * demek "kalıcı" demek değildir (BY-005-R9). Kökün kendisi kurulmuş
     * olmalı: kökü burada açmak, girdisini eşitlenmemiş bırakırdı.
     *
     * @throws InvalidArgumentException Klasör kökün altında değil
     * @throws RuntimeException Kök yok, açılamadı ya da eşitlenemedi
     */
    public static function ac(string $klasor, string $kaliciKok): void
    {
        self::kokAltindaOlmali($klasor, $kaliciKok);

        if (! is_dir($kaliciKok)) {
            throw new RuntimeException("Yedekleme kalıcı kökü yok: {$kaliciKok} (kurulum açmalı).");
        }

        if (! is_dir($klasor) && ! @mkdir($klasor, 0750, true) && ! is_dir($klasor)) {
            throw new RuntimeException("Yedekleme klasörü açılamadı: {$klasor}");
        }

        for ($k = $klasor; $k !== $kaliciKok; $k = dirname($k)) {
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
