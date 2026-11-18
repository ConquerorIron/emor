<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/** Denetim kaydındaki işlemin sonucu (README §4.9). */
enum IslemSonucu: string
{
    case Basarili = 'basarili';
    case Basarisiz = 'basarisiz';
}
