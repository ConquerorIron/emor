<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\TumKesintilerSorgusu;

/**
 * Tüm Kesintiler listesi (kurallar: IcmalListeRequest; kesinti türleri çoklu).
 */
final class TumKesintilerListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return TumKesintilerSorgusu::class;
    }
}
