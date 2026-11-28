<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EFaturaIslemi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * İşlem geçmişi kaydı (EK-03). Kullanıcı adı, belge no, sağlayıcı ve ortam
 * kayıt anındaki kopyadır: fatura ya da kullanıcı silinse de okunur. Ortam eski
 * kayıtlarda boştur (inceleme A-9). Belge kimliği (ETTN / belge anahtarı)
 * dönmez: ekran kullanmıyor, eski e-Arşiv / e-SMM kayıtlarında kimlik no
 * taşıyabilir (inceleme A-8).
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
            'ortam' => $this->ortam,
            'belge_no' => $this->belge_no,
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
