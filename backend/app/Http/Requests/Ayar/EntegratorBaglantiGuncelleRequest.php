<?php

declare(strict_types=1);

namespace App\Http\Requests\Ayar;

use App\Rules\EntegratorApiAdresi;
use Illuminate\Foundation\Http\FormRequest;

/**
 * API adresi isteğe bağlıdır: boş = ortamın varsayılanı; dolu adres yalnız
 * https + izinli alan adı (EntegratorApiAdresi). Adres değişirse servis
 * şifreyi yeniden ister.
 */
final class EntegratorBaglantiGuncelleRequest extends FormRequest
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
        return self::kurallar();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::mesajlar();
    }

    /**
     * Ekran ve tek seferlik içe aktarma komutu aynı kuralları kullanır.
     *
     * @return array<string, list<mixed>>
     */
    public static function kurallar(): array
    {
        return [
            'api_url' => ['nullable', 'string', 'max:255', new EntegratorApiAdresi],
            'kullanici_adi' => ['required', 'string', 'max:128'],
            // İlk kayıtta zorunlu (servis denetler); güncellemede boş = değişmesin
            'sifre' => ['nullable', 'string', 'max:255'],
            // VKN 10, TCKN 11 hane — metin olarak saklanır
            'vkn' => ['required', 'string', 'regex:/^\d{10,11}$/'],
            'posta_kutusu' => ['nullable', 'string', 'max:255', 'starts_with:urn:mail:'],
            'gonderici_birim' => ['nullable', 'string', 'max:255', 'starts_with:urn:mail:'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function mesajlar(): array
    {
        return [
            'vkn.regex' => __('hata.entegrator_vkn_gecersiz'),
            'posta_kutusu.starts_with' => __('hata.entegrator_urn_gecersiz'),
            'gonderici_birim.starts_with' => __('hata.entegrator_urn_gecersiz'),
        ];
    }
}
