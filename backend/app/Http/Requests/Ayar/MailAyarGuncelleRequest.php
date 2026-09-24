<?php

declare(strict_types=1);

namespace App\Http\Requests\Ayar;

use App\Models\MailAyari;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MailAyarGuncelleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('mail_ayarlari.guncelle') ?? false;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            // Sunucu adına şema/yol/boşluk girilmez (DSN'e doğrudan girer)
            'sunucu' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'sifreleme' => ['required', Rule::in(MailAyari::SIFRELEMELER)],
            'kullanici_adi' => ['nullable', 'string', 'max:255'],
            // Boş = kayıtlı şifre korunur (hedef değişmediyse)
            'sifre' => ['nullable', 'string', 'max:255'],
            'gonderen_adres' => ['required', 'email', 'max:255'],
            'gonderen_ad' => ['required', 'string', 'max:120'],
            'yonlendirme_adresi' => ['nullable', 'email', 'max:255'],
        ];
    }
}
