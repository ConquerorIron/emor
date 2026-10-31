<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TutanakKesintisi;

/**
 * @mixin TutanakKesintisi
 */
final class TutanakKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [
            'kaynak_tutanak_no' => $this->kaynak_tutanak_no,
            'kaynak_sozlesme' => $this->kaynak_sozlesme,
            'kaynak_hakedis_no' => $this->kaynak_hakedis_no,
            'aktivite' => $this->aktivite,
        ];
    }
}
