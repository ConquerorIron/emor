<?php

declare(strict_types=1);

namespace App\Http\Requests\Ayar;

use App\Models\EntegratorBaglanti;
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
            // İzibiz kuralları: zamanlayıcı en az 15 dk, tek çağrıda en çok 100 fatura
            'senkron_araligi_dakika' => ['sometimes', 'integer', 'min:'.EntegratorBaglanti::ENAZ_SENKRON_ARALIGI, 'max:'.EntegratorBaglanti::ENCOK_SENKRON_ARALIGI],
            'sayfa_boyutu' => ['sometimes', 'integer', 'min:'.EntegratorBaglanti::ENAZ_SAYFA_BOYUTU, 'max:'.EntegratorBaglanti::ENCOK_SAYFA_BOYUTU],
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
