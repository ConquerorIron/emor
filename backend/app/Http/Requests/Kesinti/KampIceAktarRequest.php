<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * Kamp (oda) Kesintileri içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class KampIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'kamp_kesintileri.guncelle';
    }
}
