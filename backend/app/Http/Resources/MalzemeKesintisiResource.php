<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MalzemeKesintisi;

/**
 * @mixin MalzemeKesintisi
 */
final class MalzemeKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'kaynak_irsaliye_no' => $this->kaynak_irsaliye_no,
            'urun_tanimi' => $this->urun_tanimi,
            'malzeme_birim_fiyati' => $this->malzeme_birim_fiyati,
            'miktar' => $this->miktar,
            'birim' => $this->birim,
        ];
    }
}
