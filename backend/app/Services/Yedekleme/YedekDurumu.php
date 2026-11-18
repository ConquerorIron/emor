<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Yedek kaydının durumu (README §5.4): kuyrukta → calisiyor → tamam | basarisiz.
 * `silindi`: dosya gitti, kayıt kalır (geçmiş kaybolmaz).
 */
enum YedekDurumu: string
{
    case Kuyrukta = 'kuyrukta';
    case Calisiyor = 'calisiyor';
    case Tamam = 'tamam';
    case Basarisiz = 'basarisiz';
    case Silindi = 'silindi';
}
