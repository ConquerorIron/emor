<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * Tetkik Kesintisi içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class TetkikIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'tetkik_kesintileri.guncelle';
    }
}
