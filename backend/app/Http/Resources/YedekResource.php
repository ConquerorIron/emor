<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Yedek;
use App\Models\YedekKopyasi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Yedekler listesinin satırı (BY-014). Dosya YOLU, özet dışı iç bilgi ve
 * serbest hata metni dönmez (hata metni yol / komut çıktısı taşıyabilir):
 * yalnız makine okunur hata kodu; ekran onu çevirir.
 *
 * @mixin Yedek
 */
final class YedekResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'kimlik' => $this->kimlik,
            'dosya_adi' => $this->dosya_adi,
            'kaynak' => $this->kaynak->value,
            'durum' => $this->durum->value,
            'dogrulama_durumu' => $this->dogrulama_durumu->value,
            'imzali' => $this->imzali,
            'ice_alindi' => $this->ice_alindi,
            'geri_yuklenebilir' => $this->geriYuklenebilirMi(),
            'boyut' => $this->boyut,
            'alinma_zamani' => $this->alinma_zamani?->toIso8601String(),
            'sabit' => $this->sabit,
            'aciklama' => $this->aciklama,
            'alan_kullanici' => $this->whenLoaded('alanKullanici', fn () => $this->alanKullanici?->ad),
            'hata_kodu' => $this->hata_kodu,
            'yerel_dosya_var' => $this->yerel_silinme_zamani === null && $this->durum->value !== 'silindi',
            'kopyalar' => $this->whenLoaded('kopyalar', fn () => $this->kopyalar->map(fn (YedekKopyasi $k): array => [
                'hedef_id' => $k->yedek_hedefi_id,
                'hedef_ad' => $k->hedef?->ad,
                'hedef_tur' => $k->hedef?->tur->value,
                'durum' => $k->durum->value,
                'sifreli' => $k->sifreli,
                'tamamlanma_zamani' => $k->tamamlanma_zamani?->toIso8601String(),
                'hata_kodu' => $k->hata_kodu,
            ])->values()->all()),
        ];
    }
}
