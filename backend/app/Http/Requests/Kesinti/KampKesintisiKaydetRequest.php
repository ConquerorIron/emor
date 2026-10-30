<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Models\KesintiYuklemesi;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kamp (oda) kesintisinin elle girilmesi / düzeltilmesi (tür ve tutar kuralları:
 * IcmalSatiriKaydi).
 */
final class KampKesintisiKaydetRequest extends FormRequest
{
    use IcmalSatiriKaydi;

    public function authorize(): bool
    {
        return $this->user()?->can('kamp_kesintileri.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $metin = fn (int $sinir, bool $zorunlu = false): array => [$zorunlu ? 'required' : 'nullable', 'string', "max:{$sinir}"];
        $sayi = fn (int $basamak, string $ust): array => ['required', 'numeric', "decimal:0,{$basamak}", 'min:0', "max:{$ust}"];

        return [
            'kesinti_turu_id' => $this->kesintiTuruKurali(KesintiYuklemesi::ICMAL_KAMP),
            'firma_id' => ['required', 'integer', Rule::exists('firmalar', 'id')],
            'yansitma_turu' => ['required', Rule::enum(YansitmaTuru::class)],
            'proje_id' => ['required', 'integer', Rule::exists('projeler', 'id')],
            'para_birimi_id' => ['required', 'integer', Rule::exists('para_birimleri', 'id')],
            'tarih' => ['required', 'date_format:Y-m-d'],
            'mahal' => $metin(200),
            'kaynak_firma' => $metin(200),
            'oda_tipi' => $metin(100, true),
            'toplam_oda' => $sayi(2, '9999999999.99'),
            'birim_fiyat' => $sayi(4, '99999999999999.9999'),
            'toplam_tutar' => $sayi(2, '9999999999999999.99'),
            'iptal_edilen_tutar' => $sayi(2, '9999999999999999.99'),
            'kesilen_tutar' => $sayi(2, '9999999999999999.99'),
        ];
    }
}
