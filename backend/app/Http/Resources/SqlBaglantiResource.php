<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SqlBaglanti;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SqlBaglanti
 */
final class SqlBaglantiResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ortam' => $this->ortam,
            'sunucu' => $this->sunucu,
            'port' => $this->port,
            'veritabani' => $this->veritabani,
            'kullanici_adi' => $this->kullanici_adi,
            'aktif' => $this->aktif,
            // Şifre asla dönmez; form "kayıtlı" durumunu bununla gösterir
            'sifre_dolu' => $this->sifre !== '',
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
