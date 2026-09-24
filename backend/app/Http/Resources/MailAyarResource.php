<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MailAyari;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MailAyari
 */
final class MailAyarResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sunucu' => $this->sunucu,
            'port' => $this->port,
            'sifreleme' => $this->sifreleme,
            'kullanici_adi' => $this->kullanici_adi,
            // Şifre asla dönmez; form "kayıtlı" durumunu bununla gösterir
            'sifre_dolu' => $this->sifre !== null && $this->sifre !== '',
            'gonderen_adres' => $this->gonderen_adres,
            'gonderen_ad' => $this->gonderen_ad,
            'yonlendirme_adresi' => $this->yonlendirme_adresi,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
