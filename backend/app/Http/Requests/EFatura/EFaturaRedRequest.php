<?php

declare(strict_types=1);

namespace App\Http\Requests\EFatura;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Yetkili kullanıcının faturayı RED'e çekmesi / RED'i geri alması (EK-04).
 * RED'e çekerken neden zorunludur (noter kanalı, iptal/itiraz portalı…).
 * Yetki rota middleware'indedir (`can:efatura.red`, `can:protel_faturalari.red`, …).
 */
final class EFaturaRedRequest extends FormRequest
{
    public const NEDEN_AZAMI = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'red' => ['required', 'boolean'],
            'neden' => ['nullable', 'required_if:red,true,1', 'string', 'max:'.self::NEDEN_AZAMI],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'neden.required_if' => __('hata.red_nedeni_zorunlu'),
            'neden.max' => __('hata.red_nedeni_uzun', ['azami' => self::NEDEN_AZAMI]),
        ];
    }

    public function neden(): ?string
    {
        $neden = trim((string) $this->validated('neden'));

        return $neden === '' ? null : $neden;
    }
}
