<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Models\ParaBirimi;
use App\Support\Metin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Para birimi oluşturma ve güncelleme. Kod büyük/küçük harf ayırmaz.
 */
final class ParaBirimiKaydetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('para_birimleri.guncelle') ?? false;
    }

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
        $para = $this->route('paraBirimi');

        return [
            'kod' => ['required', 'string', 'max:10', Rule::unique('para_birimleri', 'kod')->ignore($para instanceof ParaBirimi ? $para->id : null)],
            'ad' => ['required', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kod.unique' => __('hata.para_birimi_kod_tekrar'),
        ];
    }
}
