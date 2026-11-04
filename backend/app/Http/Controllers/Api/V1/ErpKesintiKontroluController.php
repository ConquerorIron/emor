<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Rapor\ErpKesintiKontroluRaporu;

/**
 * Raporlar → ERP Kesinti Kontrolü (akış: ErpRaporController);
 * `erp_kesinti_kontrolu.goruntule` izniyle korunur.
 */
final class ErpKesintiKontroluController extends ErpRaporController
{
    public function __construct(ErpKesintiKontroluRaporu $rapor)
    {
        parent::__construct($rapor);
    }
}
