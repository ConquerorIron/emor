<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Proje;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Proje
 */
final class ProjeResource extends JsonResource
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
            // Doluysa proje ERP'den aktarılmıştır
            'erp_guid' => $this->erp_guid,
        ];
    }
}
