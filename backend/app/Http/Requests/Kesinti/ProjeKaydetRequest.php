<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Models\Proje;
use App\Support\Metin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Projeyi elle oluşturma ve güncelleme. ERP bağı (erp_guid) yalnız ERP
 * aktarımıyla kurulur, formdan değiştirilemez.
 */
final class ProjeKaydetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('projeler.guncelle') ?? false;
    }

    /** Kod büyük/küçük harf ayırmaz: Türkçe kurallı büyük harfle saklanır. */
    protected function prepareForValidation(): void
    {
        $kod = $this->input('kod');

        if (is_string($kod)) {
            $this->merge(['kod' => Metin::buyukHarf($kod)]);
        }
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $proje = $this->route('proje');

        return [
            'kod' => ['required', 'string', 'max:50', Rule::unique('projeler', 'kod')->ignore($proje instanceof Proje ? $proje->id : null)],
            'ad' => ['required', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kod.unique' => __('hata.proje_kod_tekrar'),
        ];
    }
}
