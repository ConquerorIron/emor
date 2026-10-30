<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

/**
 * Makina Ekipman Kesintileri içe aktarımı (kurallar: IcmalIceAktarRequest).
 */
final class MakinaEkipmanIceAktarRequest extends IcmalIceAktarRequest
{
    protected function izin(): string
    {
        return 'makina_ekipman_kesintileri.guncelle';
    }
}
