<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kesinti raporlarının (Kesinti Özeti, Firma Özeti) ortak parametreleri:
 * tarih (o güne kadarki kesintiler), tek para birimi (kur çevrimi yok),
 * isteğe bağlı projeler ve firmalar (çoklu; boş = hepsi). Erişim rotadaki
 * raporun `<rapor>.goruntule` izniyle.
 */
final class KesintiRaporuRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'tarih' => ['required', 'date_format:Y-m-d'],
            'para_birimi_id' => ['required', 'integer', Rule::exists('para_birimleri', 'id')],
            'proje_id' => ['nullable', 'array', 'max:100'],
            'proje_id.*' => ['integer', 'distinct'],
            'firma_id' => ['nullable', 'array', 'max:100'],
            'firma_id.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array{tarih: string, para_birimi_id: int, proje_id: list<int>, firma_id: list<int>}
     */
    public function parametreler(): array
    {
        return [
            'tarih' => (string) $this->validated('tarih'),
            'para_birimi_id' => $this->integer('para_birimi_id'),
            'proje_id' => array_values(array_map('intval', $this->validated('proje_id') ?? [])),
            'firma_id' => array_values(array_map('intval', $this->validated('firma_id') ?? [])),
        ];
    }
}
