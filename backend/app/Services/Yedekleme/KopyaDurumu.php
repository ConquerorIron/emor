<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Bir yedeğin bir hedefteki kopyasının durumu (README §4.6). Başarısız kopya
 * yeniden denenir; yedeğin kendisi yine geçerlidir.
 */
enum KopyaDurumu: string
{
    case Bekliyor = 'bekliyor';
    case Kopyalaniyor = 'kopyalaniyor';
    case Tamam = 'tamam';
    case Basarisiz = 'basarisiz';
    case Silindi = 'silindi';
}
