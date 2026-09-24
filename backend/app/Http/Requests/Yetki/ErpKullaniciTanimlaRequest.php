<?php

declare(strict_types=1);

namespace App\Http\Requests\Yetki;

use App\Yetki\YetkiSiniri;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ERP kullanıcısını uygulamaya tanımlama: giriş izni + roller. Kişinin ERP'de
 * var olduğu servis katmanında ERP'den denetlenir.
 */
final class ErpKullaniciTanimlaRequest extends FormRequest
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
            'erp_kullanici_id' => ['required', 'integer', 'min:1'],
            'aktif_mi' => ['sometimes', 'boolean'],
            'rol_idleri' => ['sometimes', 'array'],
            'rol_idleri.*' => ['integer', 'distinct', 'exists:roller,id'],
        ];
    }

    /**
     * Atanan roller, tanımlayanın izinleri içinde kalmalı (YetkiSiniri).
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
}
