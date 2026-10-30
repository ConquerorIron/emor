<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\MakinaEkipmanKesintisiSorgusu;

/**
 * Makina Ekipman Kesintileri listesi (kurallar: IcmalListeRequest).
 */
final class MakinaEkipmanKesintisiListeRequest extends IcmalListeRequest
{
    protected function sorgu(): string
    {
        return MakinaEkipmanKesintisiSorgusu::class;
    }
}
