<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\YemekKesintisi;

/**
 * @mixin YemekKesintisi
 */
final class YemekKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'kaynak_hakedis_no' => $this->kaynak_hakedis_no,
        ];
    }
}
