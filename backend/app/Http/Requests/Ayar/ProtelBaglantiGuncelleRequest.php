<?php

declare(strict_types=1);

namespace App\Http\Requests\Ayar;

use App\Models\EntegratorBaglanti;
use App\Rules\EntegratorApiAdresi;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Protel bağlantı tanımı (PRT-03): giriş ve fatura adresleri (boş = ortamın
 * varsayılanı; yalnız https ve `protel.com.tr`), kullanıcı (e-posta), şifre,
 * VKN ve senkron aralığı — İzibiz formundaki gibi. İzibiz'e özgü alanlar
 * (posta kutusu, gönderici birim, sayfa boyutu) yoktur; gönderilse de
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
            'auth_url' => ['nullable', 'string', 'max:255', new EntegratorApiAdresi(EntegratorBaglanti::SAGLAYICI_PROTEL)],
            'api_url' => ['nullable', 'string', 'max:255', new EntegratorApiAdresi(EntegratorBaglanti::SAGLAYICI_PROTEL)],
            'kullanici_adi' => ['required', 'string', 'email', 'max:128'],
            // İlk kayıtta zorunlu (servis denetler); güncellemede boş = değişmesin
            'sifre' => ['nullable', 'string', 'max:255'],
            'vkn' => ['required', 'string', 'regex:/^\d{10,11}$/D'],
            'senkron_araligi_dakika' => ['sometimes', 'integer', 'min:'.EntegratorBaglanti::ENAZ_SENKRON_ARALIGI, 'max:'.EntegratorBaglanti::ENCOK_SENKRON_ARALIGI],
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
