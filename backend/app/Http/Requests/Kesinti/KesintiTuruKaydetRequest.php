<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Support\Metin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kesinti türü oluşturma ve güncelleme. Boş açıklama, ConvertEmptyStringsToNull
 * ile null gelir.
 */
final class KesintiTuruKaydetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kesinti_turleri.guncelle') ?? false;
    }

    /**
     * Kod büyük/küçük harf ayırmaz (kullanıcı kararı 2026-10-30: "kmp" ile
     * "KMP" aynıdır): Türkçe kurallı büyük harfle saklanır, benzersizlik de
     * bu biçim üzerinden denetlenir.
     */
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
        $tur = $this->route('kesintiTuru');

        return [
            'kod' => ['required', 'string', 'max:20', Rule::unique('kesinti_turleri', 'kod')->ignore($tur instanceof KesintiTuru ? $tur->id : null)],
            'ad' => ['required', 'string', 'max:100'],
            'kdv_orani' => ['required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'aciklama' => ['nullable', 'string', 'max:500'],
            // Bağlı icmal ekranı; boşsa tür hiçbir ekranda kullanılamaz
            'icmal' => ['nullable', 'string', Rule::in(KesintiYuklemesi::ICMALLER)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kod.unique' => __('hata.kesinti_turu_kod_tekrar'),
        ];
    }
}
