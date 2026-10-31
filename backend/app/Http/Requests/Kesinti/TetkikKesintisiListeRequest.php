<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\TetkikKesintisiSorgusu;

/**
 * Tetkik Kesintisi listesi (kurallar: IcmalListeRequest).
 */
final class TetkikKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return TetkikKesintisiSorgusu::class;
    }
}
