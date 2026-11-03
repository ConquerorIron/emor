<?php

declare(strict_types=1);

namespace App\Http\Requests\Rapor;

/**
 * Taşeron Hakedişleri → Excel'e Aktar: raporun parametreleri ve ekranda
 * süzülmüş hakedişlerin kimlikleri, ekrandaki sırayla (gövdede, POST —
 * Firmalar Excel'i gibi). Excel ekranla aynı satırları içerir.
 */
final class TaseronHakedisleriExcelRequest extends TaseronHakedisleriRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Canlıda tüm tarihler ~640 hakediş; üst sınır kötüye kullanıma karşı
            'hakedis_idler' => ['present', 'array', 'max:20000'],
            'hakedis_idler.*' => ['integer'],
        ];
    }

    /**
     * @return list<int>
     */
    public function hakedisIdleri(): array
    {
        return array_map(intval(...), $this->input('hakedis_idler', []));
    }
}
