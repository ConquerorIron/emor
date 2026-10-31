<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * İSG Ceza Kesintisi içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class IsgCezaIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'isg_ceza_kesintileri.guncelle';
    }
}
