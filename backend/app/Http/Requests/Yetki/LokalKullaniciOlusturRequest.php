<?php

declare(strict_types=1);

namespace App\Http\Requests\Yetki;

use App\Yetki\YetkiSiniri;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class LokalKullaniciOlusturRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('kullanicilar.guncelle') ?? false;
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
            // Şifre serbest: uzunluk/karakter kuralı yok (kullanıcı isteği 2026-09-24)
            'sifre' => ['required', 'string', 'max:255'],
            'aktif_mi' => ['sometimes', 'boolean'],
            'rol_idleri' => ['sometimes', 'array'],
            'rol_idleri.*' => ['integer', 'distinct', 'exists:roller,id'],
        ];
    }

    /**
     * Atanan roller, oluşturanın izinleri içinde kalmalı (YetkiSiniri).
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var list<int> $roller */
                $roller = array_map('intval', (array) $this->input('rol_idleri', []));

                if (YetkiSiniri::asanRolIzinleri($this->user(), $roller) !== []) {
                    $validator->errors()->add('rol_idleri', __('hata.izin_verme_siniri'));
                }
            },
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
