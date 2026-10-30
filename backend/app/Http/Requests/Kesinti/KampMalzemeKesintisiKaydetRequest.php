<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kamp malzeme kesintisinin elle girilmesi / düzeltilmesi (tutar hesabı:
 * HesaplananTutarlar).
 */
final class KampMalzemeKesintisiKaydetRequest extends FormRequest
{
    use HesaplananTutarlar;

    public function authorize(): bool
    {
        return $this->user()?->can('kamp_malzeme_kesintileri.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $metin = fn (int $sinir, bool $zorunlu = false): array => [$zorunlu ? 'required' : 'nullable', 'string', "max:{$sinir}"];
        $sayi = fn (int $basamak, string $ust): array => ['required', 'numeric', "decimal:0,{$basamak}", 'min:0', "max:{$ust}"];

        return [
            'kesinti_turu_id' => ['required', 'integer', Rule::exists('kesinti_turleri', 'id')],
            'firma_id' => ['required', 'integer', Rule::exists('firmalar', 'id')],
            'yansitma_turu' => ['required', Rule::enum(YansitmaTuru::class)],
            'proje_id' => ['required', 'integer', Rule::exists('projeler', 'id')],
            'para_birimi_id' => ['required', 'integer', Rule::exists('para_birimleri', 'id')],
            'tarih' => ['required', 'date_format:Y-m-d'],
            'mahal' => $metin(200),
            'kaynak_firma' => $metin(200),
            'urun_tanimi' => $metin(200, true),
            'birim_fiyat' => $sayi(4, '99999999999999.9999'),
            'miktar' => $sayi(2, '9999999999.99'),
            'birim' => $metin(20),
            'toplam_tutar' => $sayi(2, '9999999999999999.99'),
            'iptal_edilen_tutar' => $sayi(2, '9999999999999999.99'),
            'kesilen_tutar' => $sayi(2, '9999999999999999.99'),
        ];
    }
}
