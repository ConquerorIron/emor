<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\KampMalzemeKesintisiSorgusu;

/**
 * Kamp Malzeme Kesintileri listesi (kurallar: IcmalListeRequest).
 */
final class KampMalzemeKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return KampMalzemeKesintisiSorgusu::class;
    }
}
