<?php

declare(strict_types=1);

namespace App\Http\Requests\Rapor;

/**
 * Gelen Fatura Raporu → Excel'e Aktar: listeyle aynı aralık sınırı
 * (GelenFaturaRaporuRequest) + ekranda süzülen satırların anahtarları.
 */
final class GelenFaturaRaporuExcelRequest extends ErpRaporExcelRequest
{
    protected function azamiGun(): int
    {
        return (int) config('efatura.liste_azami_gun');
    }
}
