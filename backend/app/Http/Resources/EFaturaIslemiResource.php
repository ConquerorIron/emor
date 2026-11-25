<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EFaturaIslemi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * İşlem geçmişi kaydı (EK-03). Kullanıcı adı, belge no ve sağlayıcı kayıt
 * anındaki kopyadır: fatura ya da kullanıcı silinse de okunur.
 *
 * @mixin EFaturaIslemi
 */
final class EFaturaIslemiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fatura_id' => $this->fatura_id,
            'yukleme_id' => $this->yukleme_id,
            'saglayici' => $this->saglayici,
            'belge_no' => $this->belge_no,
            'belge_kimligi' => $this->belge_kimligi,
            'islem' => $this->islem->value,
            'onceki' => $this->onceki,
            'yeni' => $this->yeni,
            'neden' => $this->neden,
            'ayrinti' => $this->ayrinti,
            'kullanici_adi' => $this->kullanici_adi,
            'zaman' => $this->created_at->toIso8601String(),
        ];
    }
}
