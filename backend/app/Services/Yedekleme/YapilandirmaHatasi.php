<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/** Yedekleme yapılandırması geçersiz: işlem başlamaz (G9). */
final class YapilandirmaHatasi extends RuntimeException
{
    public function __construct(string $neden)
    {
        parent::__construct('Yedekleme yapılandırması geçersiz: '.$neden);
    }
}
