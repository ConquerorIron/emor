<?php

declare(strict_types=1);

namespace App\Http\Requests\Ayar;

use Illuminate\Foundation\Http\FormRequest;

final class SqlBaglantiGuncelleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Rota da `can:sql_baglantilari.guncelle` ile korunur; istek başka bir rotada
        // kullanılırsa yetkisiz kalmasın diye burada da denetlenir
        return $this->user()?->can('sql_baglantilari.guncelle') ?? false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'sunucu' => ['required', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'veritabani' => ['required', 'string', 'max:128'],
            'kullanici_adi' => ['required', 'string', 'max:128'],
            // İlk kayıtta zorunlu (servis denetler); güncellemede boş = değişmesin
            'sifre' => ['nullable', 'string', 'max:255'],
        ];
    }
}
