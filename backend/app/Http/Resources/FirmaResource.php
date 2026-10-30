<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Firma;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Firma
 */
final class FirmaResource extends JsonResource
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
            'vergi_kimlik_no' => $this->vergi_kimlik_no,
            'vergi_dairesi' => $this->vergi_dairesi,
            'eposta' => $this->eposta,
            'gsm_no' => $this->gsm_no,
            // Doluysa firma ERP'den aktarılmıştır
            'erp_guid' => $this->erp_guid,
            'firmamiz' => $this->firmamiz,
        ];
    }
}
