<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Yedek kaydının durumu (README §5.4): kuyrukta → calisiyor → tamam | basarisiz.
 * `silindi`: yedeğin hiçbir yerde (yerel ve uzak) kopyası kalmadı; kayıt kalır
 * (geçmiş kaybolmaz). Yalnız yerel dosya gittiyse durum değişmez,
 * `yerel_silinme_zamani` dolar.
 */
enum YedekDurumu: string
{
    case Kuyrukta = 'kuyrukta';
    case Calisiyor = 'calisiyor';
    case Tamam = 'tamam';
    case Basarisiz = 'basarisiz';
    case Silindi = 'silindi';
}
