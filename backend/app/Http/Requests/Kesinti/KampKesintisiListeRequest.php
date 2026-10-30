<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\KampKesintisiSorgusu;

/**
 * Kamp (oda) Kesintileri listesi (kurallar: IcmalListeRequest).
 */
final class KampKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return KampKesintisiSorgusu::class;
    }
}
