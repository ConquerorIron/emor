<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IsgucuKesintisi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tutarlar decimal metin olarak döner (ondalık kaybı olmasın).
 *
 * @mixin IsgucuKesintisi
 */
final class IsgucuKesintisiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Yalnız yüklenmiş ilişki yazılır (listede tembel yükleme / N+1 olmasın)
        $tanim = function (string $iliski): ?array {
            $kayit = $this->resource->relationLoaded($iliski) ? $this->resource->getRelation($iliski) : null;

            return $kayit === null ? null : ['id' => $kayit->id, 'kod' => $kayit->kod, 'ad' => $kayit->ad];
        };

        return [
            'id' => $this->id,
            'yukleme_id' => $this->yukleme_id,
            'kesinti_turu' => $tanim('kesintiTuru'),
            'firma' => $tanim('firma'),
            'proje' => $tanim('proje'),
            'para_birimi' => $tanim('paraBirimi'),
            'mahal' => $this->mahal,
            'tarih' => $this->tarih->format('Y-m-d'),
            'kaynak_firma' => $this->kaynak_firma,
            'kaynak_fis_no' => $this->kaynak_fis_no,
            'adi_soyadi' => $this->adi_soyadi,
            'vasif' => $this->vasif->value,
            'mesai_turu' => $this->mesai_turu->value,
            'yansitma_turu' => $this->yansitma_turu->value,
            'tanim' => $this->tanim,
            'gorev' => $this->gorev,
            'departman' => $this->departman,
            'aktivite' => $this->aktivite,
            'miktar_saat' => $this->miktar_saat,
            'miktar_yevmiye' => $this->miktar_yevmiye,
            'birim_fiyat' => $this->birim_fiyat,
            'toplam_tutar' => $this->toplam_tutar,
            'iptal_edilen_tutar' => $this->iptal_edilen_tutar,
            'yansitilan_tutar' => $this->yansitilan_tutar,
            'kesilen_tutar' => $this->kesilen_tutar,
            'kalan_tutar' => $this->kalan_tutar,
        ];
    }
}
