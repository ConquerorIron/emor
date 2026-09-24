<?php

declare(strict_types=1);

namespace App\Http\Requests\Yetki;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class LokalKullaniciOlusturRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sistem-yonetimi') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Tekillik tüm kullanıcılar (ERP yansımaları dahil) arasında; ERP'deki
            // henüz giriş yapmamış kullanıcılar serviste ERP'den denetlenir
            'kullanici_adi' => ['required', 'string', 'regex:/^[A-Za-z0-9._-]{3,64}$/', 'unique:users,kullanici_adi'],
            'ad' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'sifre' => ['required', 'string', 'max:255', Password::min(10)->letters()->numbers()],
            'aktif_mi' => ['sometimes', 'boolean'],
            'rol_idleri' => ['sometimes', 'array'],
            'rol_idleri.*' => ['integer', 'distinct', 'exists:roller,id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'kullanici_adi.regex' => __('hata.kullanici_adi_bicimi'),
        ];
    }
}
