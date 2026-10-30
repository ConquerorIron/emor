<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Kesinti icmali satırının elle girilmesi: yansıtılan ve kalan tutar
 * HESAPLANIR (kullanıcı kararı 2026-10-30), istemcinin gönderdiği değerler
 * kullanılmaz. Excel'den aktarımda ise tutarlar olduğu gibi alınır.
 *
 * @mixin FormRequest
 */
trait HesaplananTutarlar
{
    /**
     * Doğrulanmış alanlar + hesaplanan tutarlar: yansıtılan = toplam − iptal,
     * kalan = yansıtılan − kesilen (decimal aritmetiği, float yuvarlaması yok).
     *
     * @return array<string, mixed>
     */
    public function kayit(): array
    {
        $veri = $this->validated();
        $tutar = fn (string $alan): string => number_format((float) $veri[$alan], 2, '.', '');

        $yansitilan = bcsub($tutar('toplam_tutar'), $tutar('iptal_edilen_tutar'), 2);

        return [
            ...$veri,
            'yansitilan_tutar' => $yansitilan,
            'kalan_tutar' => bcsub($yansitilan, $tutar('kesilen_tutar'), 2),
        ];
    }
}
