<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Şifreli alanların hangi `APP_KEY` ile yazıldığını gösteren parmak izi
 * (README §5.3, §7.5). Anahtarın kendisi değil: tek yönlü, alan ayrımlı
 * HMAC'in ilk 16 hex karakteri — parmak izinden anahtar elde edilemez ve
 * kısaltılmış olduğu için anahtarı denemeye yarayacak tam özet de yoktur.
 */
final class AppKeyParmakIzi
{
    private const ALAN = 'emor-yedek-app-key-parmak-izi-v1';

    public static function hesapla(mixed $anahtar = null): ?string
    {
        $anahtar ??= config('app.key');

        if (! is_string($anahtar) || $anahtar === '') {
            return null;
        }

        $ham = str_starts_with($anahtar, 'base64:')
            ? base64_decode(substr($anahtar, 7), true)
            : $anahtar;

        if (! is_string($ham) || $ham === '') {
            return null;
        }

        return substr(hash_hmac('sha256', self::ALAN, $ham), 0, 16);
    }

    /**
     * Parmak izi bugünkü `APP_KEY`'in ya da `APP_PREVIOUS_KEYS`'ten birinin mi
     * (Laravel şifreli alanı bunlarla çözer — README §7.5; YF-13). Parmak izi
     * olmayan manifest (eski / içe alınmış yedek) denetlenemez: true.
     */
    public static function bilinenMi(?string $parmakIzi): bool
    {
        if ($parmakIzi === null) {
            return true;
        }

        $onceki = config('app.previous_keys');
        foreach ([config('app.key'), ...(is_array($onceki) ? $onceki : [])] as $anahtar) {
            $iz = self::hesapla($anahtar);
            if ($iz !== null && hash_equals($iz, $parmakIzi)) {
                return true;
            }
        }

        return false;
    }
}
