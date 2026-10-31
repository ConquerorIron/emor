<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * Yemek Kesintisi içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class YemekIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'yemek_kesintileri.guncelle';
    }
}
