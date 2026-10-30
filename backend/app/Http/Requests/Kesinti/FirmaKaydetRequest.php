<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Support\Metin;
use Illuminate\Foundation\Http\FormRequest;

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

        if ($this->has('firmamiz')) {
            $this->merge(['firmamiz' => $this->boolean('firmamiz')]);
        }
    }

    /**
     * Kod ve VKN tekrar edebilir (ERP'de aynı firmanın birden fazla kartı);
     * Excel eşleşmesinde kodun tek firmaya düşmesi aktarımda denetlenir.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'kod' => ['required', 'string', 'max:50'],
            'ad' => ['required', 'string', 'max:200'],
            'vergi_kimlik_no' => ['nullable', 'string', 'max:20'],
            'vergi_dairesi' => ['nullable', 'string', 'max:100'],
            'eposta' => ['nullable', 'string', 'max:255'],
            'gsm_no' => ['nullable', 'string', 'max:30'],
            // Gönderilmezse değişmez; işaretlenirse önceki "firmamız"ın işareti kalkar
            'firmamiz' => ['sometimes', 'boolean'],
        ];
    }
}
