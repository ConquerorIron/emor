<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * Malzeme Kesintisi içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class MalzemeIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'malzeme_kesintileri.guncelle';
    }
}
