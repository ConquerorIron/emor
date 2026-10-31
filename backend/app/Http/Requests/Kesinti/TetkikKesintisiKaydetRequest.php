<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Models\KesintiYuklemesi;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tetkik kesintisinin elle girilmesi / düzeltilmesi (tür ve tutar kuralları:
 * IcmalSatiriKaydi).
 */
final class TetkikKesintisiKaydetRequest extends FormRequest
{
    use IcmalSatiriKaydi;

    public function authorize(): bool
    {
        return $this->user()?->can('tetkik_kesintileri.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $metin = fn (int $sinir, bool $zorunlu = false): array => [$zorunlu ? 'required' : 'nullable', 'string', "max:{$sinir}"];
        $tutar = ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999999.99'];

        return [
            'kesinti_turu_id' => $this->kesintiTuruKurali(KesintiYuklemesi::ICMAL_TETKIK),
            'firma_id' => ['required', 'integer', Rule::exists('firmalar', 'id')],
            'yansitma_turu' => ['required', Rule::enum(YansitmaTuru::class)],
            'proje_id' => ['required', 'integer', Rule::exists('projeler', 'id')],
            'para_birimi_id' => ['required', 'integer', Rule::exists('para_birimleri', 'id')],
            'tarih' => ['required', 'date_format:Y-m-d'],
            'mahal' => $metin(200),
            'kaynak_firma' => $metin(200),
            'kaynak_hakedis_no' => $metin(100),
            'adi_soyadi' => $metin(200, true),
            'toplam_tutar' => $tutar,
            'iptal_edilen_tutar' => $tutar,
            'kesilen_tutar' => $tutar,
        ];
    }
}
