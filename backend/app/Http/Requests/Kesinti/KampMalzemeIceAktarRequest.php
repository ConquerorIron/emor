<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * Kamp Malzeme Kesintileri içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class KampMalzemeIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'kamp_malzeme_kesintileri.guncelle';
    }
}
