<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\KesintiYuklemesi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KesintiYuklemesi
 */
final class KesintiYuklemesiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kaynak' => $this->kaynak,
            'dosya_adi' => $this->dosya_adi,
            'satir_sayisi' => $this->satir_sayisi,
            'yukleyen' => $this->whenLoaded('yukleyen', fn () => $this->yukleyen?->ad),
            'olusturma_zamani' => $this->created_at?->toISOString(),
        ];
    }
}
