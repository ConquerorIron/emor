<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kesinti icmali satırının elle girilmesi (kullanıcı kararları 2026-10-30):
 *
 * - Kesinti türü bu ekrana bağlı olmalıdır (Kesinti Türleri → Ekran).
 * - Yansıtılan ve kalan tutar HESAPLANIR, istemcinin gönderdiği değerler
 *   kullanılmaz. Excel'den aktarımda ise tutarlar olduğu gibi alınır.
 *
 * @mixin FormRequest
 */
trait IcmalSatiriKaydi
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kesinti_turu_id.exists' => __('hata.kesinti_turu_baska_ekranin'),
        ];
    }

    /**
     * Yalnız bu ekrana bağlı kesinti türü seçilebilir.
     *
     * @return list<mixed>
     */
    protected function kesintiTuruKurali(string $icmal): array
    {
        return ['required', 'integer', Rule::exists('kesinti_turleri', 'id')->where('icmal', $icmal)];
    }
}
