<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\KesintiOzetiRequest;
use App\Services\Kesinti\KesintiOzeti;
use App\Services\Kesinti\KesintiOzetiExcelAktarici;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Kesinti Özeti: Tüm Kesintiler'den özet, proje / kesinti
 * türü dağılımı ve en yüksek kesintili taşeronlar (ERP okunmaz). Yalnız rapor;
 * `kesinti_ozeti.goruntule` izniyle korunur.
 */
final class KesintiOzetiController extends Controller
{
    public function index(KesintiOzetiRequest $request, KesintiOzeti $ozet): JsonResponse
    {
        return response()->json(['data' => $ozet->hesapla($request->parametreler())]);
    }

    /** Ekrandaki raporun aynısı, tek sayfa */
    public function excel(KesintiOzetiRequest $request, KesintiOzeti $ozet, KesintiOzetiExcelAktarici $aktarici): BinaryFileResponse
    {
        return response()
            ->download($aktarici->olustur($ozet->hesapla($request->parametreler())), 'kesinti-ozeti.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    public function secenekler(KesintiOzeti $ozet): JsonResponse
    {
        return response()->json(['data' => $ozet->secenekler()]);
    }
}
