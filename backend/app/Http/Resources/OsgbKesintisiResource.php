<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OsgbKesintisi;

/**
 * @mixin OsgbKesintisi
 */
final class OsgbKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'toplam_kisi' => $this->toplam_kisi,
            'birim_fiyat' => $this->birim_fiyat,
        ];
    }
}
