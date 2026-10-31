<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\YemekKesintisiSorgusu;

/**
 * Yemek Kesintisi listesi (kurallar: IcmalListeRequest).
 */
final class YemekKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return YemekKesintisiSorgusu::class;
    }
}
