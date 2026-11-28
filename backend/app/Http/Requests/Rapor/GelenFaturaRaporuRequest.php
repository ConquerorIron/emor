<?php

declare(strict_types=1);

namespace App\Http\Requests\Rapor;

/**
 * Gelen Fatura Raporu'nun parametreleri: aralık e-Fatura listeleri gibi en çok
 * `efatura.liste_azami_gun` (bütün satırlar tek yanıtta; tam yıl ~12.000 fatura).
 */
final class GelenFaturaRaporuRequest extends ErpRaporRequest
{
    protected function azamiGun(): int
    {
        return (int) config('efatura.liste_azami_gun');
    }
}
