<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KartBasimKesintisi;

/**
 * @mixin KartBasimKesintisi
 */
final class KartBasimKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'personel_adi_soyadi' => $this->personel_adi_soyadi,
            'urun_tanimi' => $this->urun_tanimi,
            'kart_basim_nedeni' => $this->kart_basim_nedeni,
            'malzeme_birim_fiyati' => $this->malzeme_birim_fiyati,
            'miktar' => $this->miktar,
            'birim' => $this->birim,
        ];
    }
}
