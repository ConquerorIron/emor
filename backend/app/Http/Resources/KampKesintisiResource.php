<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KampKesintisi;

/**
 * @mixin KampKesintisi
 */
final class KampKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'oda_tipi' => $this->oda_tipi,
            'toplam_oda' => $this->toplam_oda,
        ];
    }
}
