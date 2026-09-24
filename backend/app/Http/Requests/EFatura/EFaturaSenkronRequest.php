<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Elle e-Fatura senkronu (EFAT-11). Aralık ilk tarama tarihinden önce
 * başlayamaz, bugünü aşamaz ve config('efatura.manuel_azami_gun') ile sınırlıdır.
 */
final class EFaturaSenkronRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('efatura.senkron') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'baslangic' => ['required', 'date_format:Y-m-d'],
            'bitis' => ['required', 'date_format:Y-m-d', 'after_or_equal:baslangic'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $baslangic = CarbonImmutable::parse((string) $this->input('baslangic'));
                $bitis = CarbonImmutable::parse((string) $this->input('bitis'));
                $ilk = (string) config('entegrator.izibiz.ilk_tarama_tarihi');
                $bugun = CarbonImmutable::now((string) config('entegrator.izibiz.saat_dilimi'))->toDateString();
                $azami = (int) config('efatura.manuel_azami_gun');

                if ($baslangic->toDateString() < $ilk) {
                    $validator->errors()->add('baslangic', __('hata.efatura_ilk_tarih_oncesi', [
                        'tarih' => CarbonImmutable::parse($ilk)->format('d.m.Y'),
                    ]));
                }

                if ($bitis->toDateString() > $bugun) {
                    $validator->errors()->add('bitis', __('hata.efatura_bitis_gelecekte'));
                }

                if ($baslangic->diffInDays($bitis) + 1 > $azami) {
                    $validator->errors()->add('bitis', __('hata.efatura_aralik_cok_genis', ['gun' => $azami]));
                }
            },
        ];
    }
}
