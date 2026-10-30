<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Concerns\IcmalSatiri;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Kesinti icmali satırı: ortak kolonlar (tanımlar, tarih, yansıtma, tutarlar)
 * burada, icmale özgü kolonlar alt sınıfın alanlar()'ında. Tutarlar decimal
 * metin olarak döner (ondalık kaybı olmasın).
 *
 * @mixin Model
 * @mixin IcmalSatiri
 */
abstract class IcmalSatiriResource extends JsonResource
{
    /**
     * İcmale özgü kolonlar.
     *
     * @return array<string, mixed>
     */
    abstract protected function alanlar(): array;

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
            'yansitma_turu' => $this->yansitma_turu->value,
            'proje' => $tanim('proje'),
            'para_birimi' => $tanim('paraBirimi'),
            'mahal' => $this->mahal,
            'tarih' => $this->tarih->format('Y-m-d'),
            'kaynak_firma' => $this->kaynak_firma,
            ...$this->alanlar(),
            'birim_fiyat' => $this->birim_fiyat,
            'toplam_tutar' => $this->toplam_tutar,
            'iptal_edilen_tutar' => $this->iptal_edilen_tutar,
            'yansitilan_tutar' => $this->yansitilan_tutar,
            'kesilen_tutar' => $this->kesilen_tutar,
            'kalan_tutar' => $this->kalan_tutar,
        ];
    }
}
