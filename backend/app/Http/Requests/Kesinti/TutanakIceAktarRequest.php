<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * Tutanak Kesintisi içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class TutanakIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'tutanak_kesintileri.guncelle';
    }
}
