<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\Rapor\TaseronHakedisleriRaporu;

/**
 * Raporlar → Taşeron Hakedişleri (akış: ErpRaporController);
 * `taseron_hakedisleri.goruntule` izniyle korunur.
 */
final class TaseronHakedisleriController extends ErpRaporController
{
    public function __construct(TaseronHakedisleriRaporu $rapor)
    {
        parent::__construct($rapor);
    }
}
