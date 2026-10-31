<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\MalzemeKesintisiSorgusu;

/**
 * Malzeme Kesintisi listesi (kurallar: IcmalListeRequest).
 */
final class MalzemeKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return MalzemeKesintisiSorgusu::class;
    }
}
