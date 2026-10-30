<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ParaBirimi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ParaBirimi
 */
final class ParaBirimiResource extends JsonResource
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
        ];
    }
}
