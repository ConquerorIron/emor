<?php

declare(strict_types=1);

namespace App\Http\Requests\Ayar;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Protel bağlantı tanımı (PRT-03): yalnız kullanıcı (e-posta), şifre ve VKN.
 * Adres ekrandan girilmez (config'teki sabit adresler); İzibiz'e özgü alanlar
 * (posta kutusu, gönderici birim, sayfa boyutu) yoktur. Gönderilse de
 * doğrulanmış veriye girmez.
 */
final class ProtelBaglantiGuncelleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('entegrator_baglantilari.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'kullanici_adi' => ['required', 'string', 'email', 'max:128'],
            // İlk kayıtta zorunlu (servis denetler); güncellemede boş = değişmesin
            'sifre' => ['nullable', 'string', 'max:255'],
            'vkn' => ['required', 'string', 'regex:/^\d{10,11}$/D'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'vkn.regex' => __('hata.entegrator_vkn_gecersiz'),
        ];
    }
}
