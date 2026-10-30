<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MakinaEkipmanKesintisi;

/**
 * @mixin MakinaEkipmanKesintisi
 */
final class MakinaEkipmanKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'kaynak_fis_no' => $this->kaynak_fis_no,
            'makina_tanimi' => $this->makina_tanimi,
            'aktivite' => $this->aktivite,
            'disiplin' => $this->disiplin,
        ];
    }
}
