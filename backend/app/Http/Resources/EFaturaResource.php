<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EFatura;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * e-Fatura özeti (EFAT-11). Tutarlar metin döner (numeric; float yuvarlaması
 * yok). `kaynak_id` İzibiz iç kimliğidir; ETTN ayrı alandır.
 *
 * @mixin EFatura
 */
final class EFaturaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'yon' => $this->yon,
            'kaynak_id' => $this->kaynak_id,
            'ettn' => $this->ettn,
            'belge_no' => $this->belge_no,
            'belge_tarihi' => $this->belge_tarihi->toDateString(),
            'belge_saati' => $this->getAttribute('belge_saati'),
            'olusturma_zamani' => $this->getAttribute('olusturma_zamani')?->toIso8601String(),
            'fatura_tipi' => $this->getAttribute('fatura_tipi'),
            'senaryo' => $this->getAttribute('senaryo'),
            'para_birimi' => $this->getAttribute('para_birimi'),
            'tutar' => $this->tutar,
            'vergi_tutari' => $this->getAttribute('vergi_tutari'),
            'satir_sayisi' => $this->getAttribute('satir_sayisi'),
            'gonderici_vkn' => $this->getAttribute('gonderici_vkn'),
            'gonderici_unvan' => $this->getAttribute('gonderici_unvan'),
            'alici_vkn' => $this->getAttribute('alici_vkn'),
            'alici_unvan' => $this->getAttribute('alici_unvan'),
            'durum' => $this->getAttribute('durum'),
            'durum_aciklamasi' => $this->getAttribute('durum_aciklamasi'),
            'gib_durum_kodu' => $this->getAttribute('gib_durum_kodu'),
            'gib_durum_aciklamasi' => $this->getAttribute('gib_durum_aciklamasi'),
            'erp_okundu' => $this->erp_okundu,
            'okundu' => $this->getAttribute('okundu'),
            'yanit_aciklamasi' => $this->getAttribute('yanit_aciklamasi'),
            'son_gorulme' => $this->getAttribute('son_gorulme')?->toIso8601String(),
        ];
    }
}
