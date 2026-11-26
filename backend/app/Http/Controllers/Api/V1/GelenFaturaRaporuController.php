<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Rapor\ErpRaporExcelRequest;
use App\Http\Requests\Rapor\ErpRaporRequest;
use App\Services\Kesinti\IceAktarimTablosu;
use App\Services\Rapor\ErpRaporExcelAktarici;
use App\Services\Rapor\ErpRaporSecenekleri;
use App\Services\Rapor\GelenFaturaRaporu;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Raporlar → Gelen Fatura Raporu (akış: ErpRaporController; satırlar ERP'den
 * değil uygulamanın tablolarından); `gelen_fatura_raporu.goruntule` izniyle.
 *
 * Tam yıl ~12.000 fatura (canlı ölçüm 2026-11-26): liste ~3 sn / ~140 MB,
 * Excel ~34 sn / ~380 MB — php-fpm'in 30 sn / 128 MB sınırı aşılır. Bu raporda
 * sınırlar kodda yükseltilir (bellek 512 MB; süre nginx'in 60 sn'sinin altında).
 */
final class GelenFaturaRaporuController extends ErpRaporController
{
    private const LISTE_SURESI_SN = 30;

    private const EXCEL_SURESI_SN = 55;

    public function __construct(GelenFaturaRaporu $rapor)
    {
        parent::__construct($rapor);
    }

    public function index(ErpRaporRequest $request): JsonResponse
    {
        $this->sinirlariYukselt(self::LISTE_SURESI_SN);

        return parent::index($request);
    }

    public function excel(ErpRaporExcelRequest $request, ErpRaporExcelAktarici $aktarici): BinaryFileResponse
    {
        $this->sinirlariYukselt(self::EXCEL_SURESI_SN);

        return parent::excel($request, $aktarici);
    }

    /** Proje / firma parametre seçenekleri ERP'nindir; bu raporda yok (ekran parametre süzgecini çizmez) */
    public function secenekler(ErpRaporSecenekleri $secenekler): JsonResponse
    {
        return response()->json(['data' => ['projeler' => [], 'firmalar' => []]]);
    }

    private function sinirlariYukselt(int $sure): void
    {
        IceAktarimTablosu::bellekSiniriniYukselt();
        set_time_limit($sure);
    }
}
