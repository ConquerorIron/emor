<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TetkikKesintisi;

/**
 * @mixin TetkikKesintisi
 */
final class TetkikKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'adi_soyadi' => $this->adi_soyadi,
        ];
    }
}
