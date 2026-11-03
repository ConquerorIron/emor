<?php

declare(strict_types=1);

namespace App\Http\Requests\Rapor;

/**
 * Rapor → Excel'e Aktar: raporun parametreleri ve ekranda süzülmüş
 * satırların anahtarları (ErpRaporu::ANAHTAR), ekrandaki sırayla (gövdede,
 * POST — Firmalar Excel'i gibi). Excel ekranla aynı satırları içerir.
 */
final class ErpRaporExcelRequest extends ErpRaporRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            // Canlıda tüm tarihler birkaç yüz satır; üst sınır kötüye kullanıma karşı
            'anahtarlar' => ['present', 'array', 'max:20000'],
            'anahtarlar.*' => ['string', 'max:100'],
        ];
    }

    /**
     * @return list<string>
     */
    public function anahtarlar(): array
    {
        return array_map(strval(...), $this->input('anahtarlar', []));
    }
}
