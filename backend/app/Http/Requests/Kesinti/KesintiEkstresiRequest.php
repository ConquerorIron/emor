<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Firma Özeti'nde tıklanan hücrenin ekstresi: raporun tarihi ve para birimi +
 * hücrenin taşeronu ve projesi; kesinti türü boşsa (Toplam Kalan) bütün türler.
 * Erişim rotadaki `firma_ozeti.goruntule` izniyle.
 */
final class KesintiEkstresiRequest extends FormRequest
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
            'firma_id' => ['required', 'integer', Rule::exists('firmalar', 'id')],
            'proje_id' => ['required', 'integer', Rule::exists('projeler', 'id')],
            'kesinti_turu_id' => ['nullable', 'integer', Rule::exists('kesinti_turleri', 'id')],
        ];
    }

    /**
     * @return array{tarih: string, para_birimi_id: int, firma_id: int, proje_id: int, kesinti_turu_id: int|null}
     */
    public function parametreler(): array
    {
        return [
            'tarih' => (string) $this->validated('tarih'),
            'para_birimi_id' => $this->integer('para_birimi_id'),
            'firma_id' => $this->integer('firma_id'),
            'proje_id' => $this->integer('proje_id'),
            'kesinti_turu_id' => $this->filled('kesinti_turu_id') ? $this->integer('kesinti_turu_id') : null,
        ];
    }
}
