<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Models\KesintiYuklemesi;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Personel kart basım kesintisinin elle girilmesi / düzeltilmesi (tür ve
 * tutar kuralları: IcmalSatiriKaydi).
 */
final class KartBasimKesintisiKaydetRequest extends FormRequest
{
    use IcmalSatiriKaydi;

    public function authorize(): bool
    {
        return $this->user()?->can('kart_basim_kesintileri.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $metin = fn (int $sinir, bool $zorunlu = false): array => [$zorunlu ? 'required' : 'nullable', 'string', "max:{$sinir}"];
        $sayi = fn (int $basamak, string $ust): array => ['required', 'numeric', "decimal:0,{$basamak}", 'min:0', "max:{$ust}"];

        return [
            'kesinti_turu_id' => $this->kesintiTuruKurali(KesintiYuklemesi::ICMAL_KART_BASIM),
            'firma_id' => ['required', 'integer', Rule::exists('firmalar', 'id')],
            'yansitma_turu' => ['required', Rule::enum(YansitmaTuru::class)],
            'proje_id' => ['required', 'integer', Rule::exists('projeler', 'id')],
            'para_birimi_id' => ['required', 'integer', Rule::exists('para_birimleri', 'id')],
            'tarih' => ['required', 'date_format:Y-m-d'],
            'mahal' => $metin(200),
            'kaynak_firma' => $metin(200),
            'personel_adi_soyadi' => $metin(100, true),
            'urun_tanimi' => $metin(100, true),
            'kart_basim_nedeni' => $metin(100),
            'malzeme_birim_fiyati' => $sayi(4, '99999999999999.9999'),
            'miktar' => $sayi(2, '9999999999.99'),
            'birim' => $metin(20),
            'toplam_tutar' => $sayi(2, '9999999999999999.99'),
            'iptal_edilen_tutar' => $sayi(2, '9999999999999999.99'),
            'kesilen_tutar' => $sayi(2, '9999999999999999.99'),
        ];
    }
}
