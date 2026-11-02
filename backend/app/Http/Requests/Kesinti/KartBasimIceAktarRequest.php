<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * Personel Kart Basım Kesintisi içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class KartBasimIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'kart_basim_kesintileri.guncelle';
    }
}
