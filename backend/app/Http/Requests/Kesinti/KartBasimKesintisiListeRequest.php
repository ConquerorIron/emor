<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\KartBasimKesintisiSorgusu;

/**
 * Personel Kart Basım Kesintisi listesi (kurallar: IcmalListeRequest).
 */
final class KartBasimKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return KartBasimKesintisiSorgusu::class;
    }
}
