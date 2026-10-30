<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\IsgucuKesintisiSorgusu;

/**
 * İşgücü Kesintileri listesi (kurallar: IcmalListeRequest).
 */
final class IsgucuKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return IsgucuKesintisiSorgusu::class;
    }
}
