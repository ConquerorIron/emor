<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IsgucuKesintisi;

/**
 * @mixin IsgucuKesintisi
 */
final class IsgucuKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'kaynak_fis_no' => $this->kaynak_fis_no,
            'adi_soyadi' => $this->adi_soyadi,
            'vasif' => $this->vasif->value,
            'mesai_turu' => $this->mesai_turu->value,
            'tanim' => $this->tanim,
            'gorev' => $this->gorev,
            'departman' => $this->departman,
            'aktivite' => $this->aktivite,
            'miktar_saat' => $this->miktar_saat,
            'miktar_yevmiye' => $this->miktar_yevmiye,
            'birim_fiyat' => $this->birim_fiyat,
        ];
    }
}
