<?php

declare(strict_types=1);

namespace App\Http\Requests\Ayar;

use App\Models\AlarmKurali;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alarm kuralı güncelleme (EFAT-13). Parametre kuralları türe göre değişir;
 * tür rotadan gelir (whereIn ile sınırlı).
 *
 * Alıcılar: `alicilar` (To) her istekte gelir ve aktif kuralda boş olamaz;
 * `alicilar_cc` / `alicilar_bcc` isteğe bağlıdır — gönderilmezse kayıtlı
 * liste korunur (servis).
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
        // Tekrarlar reddedilmez; servis küçük harfe çevirip tekilleştirir
        $adres = ['required', 'string', 'email', 'max:255'];
        $ortak = [
            'aktif' => ['required', 'boolean'],
            'alicilar' => ['present', 'array', 'max:20'],
            'alicilar.*' => $adres,
            'alicilar_cc' => ['sometimes', 'array', 'max:20'],
            'alicilar_cc.*' => $adres,
            'alicilar_bcc' => ['sometimes', 'array', 'max:20'],
            'alicilar_bcc.*' => $adres,
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
            AlarmKurali::ISLENMEMIS_HATIRLATMA => [
                'parametreler.gun' => ['required', 'integer', 'min:1', 'max:60'],
                'parametreler.aralik_gun' => ['required', 'integer', 'min:1', 'max:30'],
                'parametreler.saat' => ['required', 'string', self::SAAT],
                // En az bir portal; yalnız bilinen adlar, virgülle
                'parametreler.portallar' => ['required', 'string', 'regex:/^(logo|protel|izibiz|earsiv)(,(logo|protel|izibiz|earsiv)){0,3}$/D'],
                // En az bir durum; yalnız AlarmKurali::ISLENDI_SECENEKLERI, virgülle
                'parametreler.islendi_sayilanlar' => ['required', 'string', self::listeKurali(AlarmKurali::ISLENDI_SECENEKLERI)],
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
            'parametreler.portallar.required' => __('hata.alarm_portal_zorunlu'),
            'parametreler.portallar.regex' => __('hata.alarm_portal_zorunlu'),
            'parametreler.islendi_sayilanlar.required' => __('hata.alarm_islendi_zorunlu'),
            'parametreler.islendi_sayilanlar.regex' => __('hata.alarm_islendi_zorunlu'),
            'alicilar.*.email' => __('hata.alarm_alici_gecersiz'),
            'alicilar_cc.*.email' => __('hata.alarm_alici_gecersiz'),
            'alicilar_bcc.*.email' => __('hata.alarm_alici_gecersiz'),
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

    /**
     * Virgüllü, en az bir öğeli liste; öğeler yalnız verilen değerler.
     *
     * @param  list<string>  $degerler
     */
    private static function listeKurali(array $degerler): string
    {
        $secenek = '('.implode('|', array_map(fn (string $d): string => preg_quote($d, '/'), $degerler)).')';

        return 'regex:/^'.$secenek.'(,'.$secenek.'){0,'.(count($degerler) - 1).'}$/D';
    }
}
