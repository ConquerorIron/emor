<?php

declare(strict_types=1);

namespace App\Http\Requests\Ayar;

use App\Models\AlarmKurali;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alarm kuralı güncelleme (EFAT-13). Parametre kuralları türe göre değişir;
 * tür rotadan gelir (whereIn ile sınırlı).
 */
final class AlarmKuraliGuncelleRequest extends FormRequest
{
    private const SAAT = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    public function authorize(): bool
    {
        return $this->user()?->can('alarm_kurallari.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $ortak = [
            'aktif' => ['required', 'boolean'],
            'alicilar' => ['present', 'array', 'max:20'],
            // Tekrarlar reddedilmez; servis küçük harfe çevirip tekilleştirir
            'alicilar.*' => ['required', 'string', 'email', 'max:255'],
            'parametreler' => ['required', 'array'],
        ];

        return $ortak + match ((string) $this->route('tur')) {
            AlarmKurali::SENKRON_ARIZASI => [
                'parametreler.ardisik_hata' => ['required', 'integer', 'min:1', 'max:20'],
                'parametreler.gecikme_saat' => ['required', 'integer', 'min:1', 'max:72'],
            ],
            AlarmKurali::GUNLUK_OZET => [
                'parametreler.saat' => ['required', 'string', self::SAAT],
            ],
            AlarmKurali::ERP_OKUMADI => [
                'parametreler.gun' => ['required', 'integer', 'min:1', 'max:30'],
                'parametreler.saat' => ['required', 'string', self::SAAT],
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parametreler.saat.regex' => __('hata.alarm_saat_bicimi'),
            'alicilar.*.email' => __('hata.alarm_alici_gecersiz'),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->boolean('aktif') && $this->input('alicilar') === []) {
                    $validator->errors()->add('alicilar', __('hata.alarm_alici_zorunlu'));
                }
            },
        ];
    }
}
