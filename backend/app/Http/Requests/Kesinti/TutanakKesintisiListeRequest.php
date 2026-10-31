<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\TutanakKesintisiSorgusu;

/**
 * Tutanak Kesintisi listesi (kurallar: IcmalListeRequest).
 */
final class TutanakKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return TutanakKesintisiSorgusu::class;
    }
}
