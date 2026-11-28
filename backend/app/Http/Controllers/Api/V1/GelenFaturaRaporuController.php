<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rapor\GelenFaturaRaporuExcelRequest;
use App\Http\Requests\Rapor\GelenFaturaRaporuRequest;
use App\Services\Kesinti\IceAktarimTablosu;
use App\Services\Rapor\ErpRaporExcelAktarici;
use App\Services\Rapor\GelenFaturaRaporu;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Raporlar → Gelen Fatura Raporu; `gelen_fatura_raporu.goruntule` izniyle.
 * Uçlar ve yanıtlar ERP raporlarınınkiyle aynı (ErpRaporController; ekran
 * ortak), ama satırlar ERP'den değil uygulamanın tablolarından gelir ve
 * istekler kendi sınıflarıyla doğrulanır: aralık en çok
 * `efatura.liste_azami_gun` (ERP raporlarını proc sınırlar). Bu yüzden
 * ErpRaporController'dan türemez — alt sınıf istek türünü daraltamaz.
 *
 * Tam yıl ~12.000 fatura (canlı ölçüm 2026-11-26): liste ~3 sn / ~140 MB,
 * Excel ~34 sn / ~380 MB — php-fpm'in 30 sn / 128 MB sınırı aşılır. Bu raporda
 * sınırlar kodda yükseltilir (bellek 512 MB; süre nginx'in 60 sn'sinin altında).
 * Eşzamanlı Excel için ayrı kilit yok (kullanıcı kararı 2026-11-27): kullanıcı
 * başına `throttle:erp-rapor` yeter.
 */
final class GelenFaturaRaporuController extends Controller
{
    private const LISTE_SURESI_SN = 30;

    private const EXCEL_SURESI_SN = 55;

    /** ERP'ye gidilmez: satırlar kullanıcının ERP yetkisiyle süzülmez (GelenFaturaRaporu::satirlar) */
    private const ERP_KULLANICISI_YOK = -1;

    public function __construct(
        private readonly GelenFaturaRaporu $rapor,
    ) {}

    public function index(GelenFaturaRaporuRequest $request): JsonResponse
    {
        $this->sinirlariYukselt(self::LISTE_SURESI_SN);

        return response()->json([
            'data' => $this->rapor->satirlar(self::ERP_KULLANICISI_YOK, $request->parametreler()),
        ]);
    }

    public function excel(GelenFaturaRaporuExcelRequest $request, ErpRaporExcelAktarici $aktarici): BinaryFileResponse
    {
        $this->sinirlariYukselt(self::EXCEL_SURESI_SN);

        return $aktarici->indir($this->rapor, $this->rapor->sec(
            $this->rapor->satirlar(self::ERP_KULLANICISI_YOK, $request->parametreler()),
            $request->anahtarlar(),
        ));
    }

    /** Proje / firma parametre seçenekleri ERP'nindir; bu raporda yok (ekran parametre süzgecini çizmez) */
    public function secenekler(): JsonResponse
    {
        return response()->json(['data' => ['projeler' => [], 'firmalar' => []]]);
    }

    private function sinirlariYukselt(int $sure): void
    {
        IceAktarimTablosu::bellekSiniriniYukselt();
        set_time_limit($sure);
    }
}
