<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\KesintiRaporuRequest;
use App\Services\Kesinti\FirmaOzeti;
use App\Services\Kesinti\FirmaOzetiExcelAktarici;
use App\Services\Kesinti\TumKesintilerSorgusu;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Firma Özeti: taşeron + proje başına kesinti türlerinin
 * kalanı yan yana (Tüm Kesintiler'den; ERP okunmaz). Yalnız rapor;
 * `firma_ozeti.goruntule` izniyle korunur.
 */
final class FirmaOzetiController extends Controller
{
    public function index(KesintiRaporuRequest $request, FirmaOzeti $ozet): JsonResponse
    {
        return response()->json(['data' => $ozet->hesapla($request->parametreler())]);
    }

    /** Ekrandaki tablo + toplam satırı */
    public function excel(KesintiRaporuRequest $request, FirmaOzeti $ozet, FirmaOzetiExcelAktarici $aktarici): BinaryFileResponse
    {
        return response()
            ->download($aktarici->olustur($ozet->hesapla($request->parametreler())), 'firma-ozeti.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    public function secenekler(TumKesintilerSorgusu $kesintiler): JsonResponse
    {
        return response()->json(['data' => $kesintiler->raporSecenekleri()]);
    }
}
