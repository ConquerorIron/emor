<?php

declare(strict_types=1);

namespace App\Http\Requests\Kesinti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Firmalar → Excel'e Aktar: ekranda aranıp sıralanmış firmaların kimlikleri,
 * ekrandaki sırayla (arama / sıralama tarayıcıda yapılır). Yetki rotada
 * (`firmalar.goruntule`).
 */
final class FirmaExcelRequest extends FormRequest
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
            'idler' => ['present', 'array', 'max:50000'],
            'idler.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function idler(): array
    {
        return array_values(array_map(intval(...), $this->validated('idler')));
    }
}
