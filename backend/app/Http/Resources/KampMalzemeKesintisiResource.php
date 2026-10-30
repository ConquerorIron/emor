<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KampMalzemeKesintisi;

/**
 * @mixin KampMalzemeKesintisi
 */
final class KampMalzemeKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'urun_tanimi' => $this->urun_tanimi,
            'birim_fiyat' => $this->birim_fiyat,
            'miktar' => $this->miktar,
            'birim' => $this->birim,
        ];
    }
}
