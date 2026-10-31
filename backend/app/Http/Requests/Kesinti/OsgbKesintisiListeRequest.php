<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\OsgbKesintisiSorgusu;

/**
 * OSGB Kesintisi listesi (kurallar: IcmalListeRequest).
 */
final class OsgbKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return OsgbKesintisiSorgusu::class;
    }
}
