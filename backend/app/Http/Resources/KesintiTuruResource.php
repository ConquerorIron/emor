<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KesintiTuru;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KesintiTuru
 */
final class KesintiTuruResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kod' => $this->kod,
            'ad' => $this->ad,
            // Metin olarak döner ("20.00"): ondalık kaybı olmasın
            'kdv_orani' => $this->kdv_orani,
            'aciklama' => $this->aciklama,
            'icmal' => $this->icmal,
        ];
    }
}
