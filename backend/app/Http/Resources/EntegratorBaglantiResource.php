<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EntegratorBaglanti;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EntegratorBaglanti
 */
final class EntegratorBaglantiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'saglayici' => $this->saglayici,
            'ortam' => $this->ortam,
            // Kullanılan adres; `api_url_ozel` false ise ortamın varsayılanıdır
            'api_url' => $this->apiUrl(),
            'api_url_ozel' => $this->api_url !== null,
            'portal_url' => $this->portalUrl(),
            'kullanici_adi' => $this->kullanici_adi,
            'vkn' => $this->vkn,
            'posta_kutusu' => $this->posta_kutusu,
            'gonderici_birim' => $this->gonderici_birim,
            'senkron_araligi_dakika' => $this->senkron_araligi_dakika,
            'sayfa_boyutu' => $this->sayfa_boyutu,
            'aktif' => $this->aktif,
            // Şifre asla dönmez; form "kayıtlı" durumunu bununla gösterir
            'sifre_dolu' => $this->sifre !== '',
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
