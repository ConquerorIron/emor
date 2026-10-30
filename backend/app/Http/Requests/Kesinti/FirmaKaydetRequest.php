<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Models\Firma;
use App\Support\Metin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Firmayı elle oluşturma ve güncelleme. ERP bağı (erp_guid) yalnız ERP
 * aktarımıyla kurulur, formdan değiştirilemez.
 */
final class FirmaKaydetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('firmalar.guncelle') ?? false;
    }

    /**
     * Kod büyük/küçük harf ayırmaz; VKN'deki boşluklar (ERP'den kopyalananlarda
     * görünmeyen boşluk da) atılır — eşleştirme bu biçim üzerinden yapılır.
     */
    protected function prepareForValidation(): void
    {
        $kod = $this->input('kod');
        $vkn = $this->input('vergi_kimlik_no');

        if (is_string($kod)) {
            $this->merge(['kod' => Metin::buyukHarf($kod)]);
        }

        if (is_string($vkn)) {
            $vkn = Metin::bosluksuz($vkn);
            $this->merge(['vergi_kimlik_no' => $vkn === '' ? null : $vkn]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $firma = $this->route('firma');
        $haric = $firma instanceof Firma ? $firma->id : null;

        return [
            'kod' => ['required', 'string', 'max:50', Rule::unique('firmalar', 'kod')->ignore($haric)],
            'ad' => ['required', 'string', 'max:200'],
            'vergi_kimlik_no' => ['nullable', 'string', 'max:20', Rule::unique('firmalar', 'vergi_kimlik_no')->ignore($haric)],
            'vergi_dairesi' => ['nullable', 'string', 'max:100'],
            'eposta' => ['nullable', 'string', 'max:255'],
            'gsm_no' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kod.unique' => __('hata.firma_kod_tekrar'),
            'vergi_kimlik_no.unique' => __('hata.firma_vkn_tekrar'),
        ];
    }
}
