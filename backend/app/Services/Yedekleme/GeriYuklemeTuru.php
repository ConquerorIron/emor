<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * README §4.5: kopya = farklı ada (canlıya dokunmaz); ustune = mevcut
 * veritabanının yerine (yıkıcı, yalnız sistem yöneticisi, izinle verilmez).
 */
enum GeriYuklemeTuru: string
{
    case Kopya = 'kopya';
    case Ustune = 'ustune';
}
