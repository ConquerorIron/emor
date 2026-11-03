<?php

declare(strict_types=1);

namespace App\Http\Requests\Rapor;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Taşeron Hakedişleri raporunun parametreleri (ERP proc'unun girdileri).
 * Yetki rota middleware'indedir (`can:taseron_hakedisleri.goruntule`).
 */
class TaseronHakedisleriRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'ilk_tarih' => ['required', 'date_format:Y-m-d'],
            'son_tarih' => ['required', 'date_format:Y-m-d', 'after_or_equal:ilk_tarih'],
            'proje_kodu' => ['nullable', 'string', 'max:100'],
            'firma_adi' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Boş proje / firma proc'a NULL gider (= hepsi).
     *
     * @return array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}
     */
    public function parametreler(): array
    {
        return [
            'ilk_tarih' => $this->string('ilk_tarih')->value(),
            'son_tarih' => $this->string('son_tarih')->value(),
            'proje_kodu' => $this->filled('proje_kodu') ? $this->string('proje_kodu')->trim()->value() : null,
            'firma_adi' => $this->filled('firma_adi') ? $this->string('firma_adi')->trim()->value() : null,
        ];
    }
}
