<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * İşgücü Kesintileri içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class IsgucuIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'isgucu_kesintileri.guncelle';
    }
}
