<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * OSGB Kesintisi içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class OsgbIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'osgb_kesintileri.guncelle';
    }
}
