<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Rapor\GiderYansitmaFaturalariRaporu;

/**
 * Raporlar → Gider Yansıtma Faturaları (akış: ErpRaporController);
 * `gider_yansitma_faturalari.goruntule` izniyle korunur.
 */
final class GiderYansitmaFaturalariController extends ErpRaporController
{
    public function __construct(GiderYansitmaFaturalariRaporu $rapor)
    {
        parent::__construct($rapor);
    }
}
