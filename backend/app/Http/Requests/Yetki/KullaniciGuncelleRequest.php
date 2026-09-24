<?php

declare(strict_types=1);

namespace App\Http\Requests\Yetki;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Ad/e-posta/şifre yalnız lokal kullanıcıda değişir (servis denetler);
 * aktiflik ve roller her kullanıcıda.
 */
final class KullaniciGuncelleRequest extends FormRequest
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
        $hedef = $this->route('kullanici');

        return [
            'ad' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($hedef instanceof User ? $hedef->id : null)],
            'sifre' => ['sometimes', 'nullable', 'string', 'max:255', Password::min(10)->letters()->numbers()],
            'aktif_mi' => ['sometimes', 'boolean'],
            'rol_idleri' => ['sometimes', 'array'],
            'rol_idleri.*' => ['integer', 'distinct', 'exists:roller,id'],
        ];
    }
}
