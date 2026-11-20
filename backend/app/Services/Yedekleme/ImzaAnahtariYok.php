<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Manifest imza anahtarı yok ya da okunamıyor (README §5.3). Yedek imzasız
 * alınmaz; anahtar `php artisan yedek:imza-anahtari` ile üretilir.
 */
final class ImzaAnahtariYok extends RuntimeException
{
    public static function yol(string $yol, string $neden): self
    {
        return new self("Yedek imza anahtarı kullanılamıyor ({$yol}): {$neden}. Anahtarı `php artisan yedek:imza-anahtari` ile üretin; kurtarma paketinde saklayın (README §9).");
    }
}
