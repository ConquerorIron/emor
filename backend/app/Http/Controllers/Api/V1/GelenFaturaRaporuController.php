<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Rapor\ErpRaporSecenekleri;
use App\Services\Rapor\GelenFaturaRaporu;
use Illuminate\Http\JsonResponse;

/**
 * Raporlar → Gelen Fatura Raporu (akış: ErpRaporController; satırlar ERP'den
 * değil uygulamanın tablolarından); `gelen_fatura_raporu.goruntule` izniyle.
 */
final class GelenFaturaRaporuController extends ErpRaporController
{
    public function __construct(GelenFaturaRaporu $rapor)
    {
        parent::__construct($rapor);
    }

    /** Proje / firma parametre seçenekleri ERP'nindir; bu raporda yok (ekran parametre süzgecini çizmez) */
    public function secenekler(ErpRaporSecenekleri $secenekler): JsonResponse
    {
        return response()->json(['data' => ['projeler' => [], 'firmalar' => []]]);
    }
}
