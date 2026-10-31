<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\IsgCezaKesintisiSorgusu;

/**
 * İSG Ceza Kesintisi listesi (kurallar: IcmalListeRequest).
 */
final class IsgCezaKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return IsgCezaKesintisiSorgusu::class;
    }
}
