<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rapor\ErpRaporExcelRequest;
use App\Http\Requests\Rapor\ErpRaporRequest;
use App\Services\Rapor\ErpRaporExcelAktarici;
use App\Services\Rapor\ErpRaporSecenekleri;
use App\Services\Rapor\ErpRaporu;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Raporlar menüsündeki ERP raporlarının ortak uçları (her rapor ince bir alt
 * sınıf; yetki rota middleware'inde `can:<rapor>.goruntule`). Ekran süzme,
 * sayfalama ve toplamları tarayıcıda yapar; Excel ekranda süzülen satırları
 * anahtarlarıyla alır.
 */
abstract class ErpRaporController extends Controller
{
    public function __construct(
        private readonly ErpRaporu $rapor,
    ) {}

    public function index(ErpRaporRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->rapor->satirlar($this->erpKullaniciId($request), $request->parametreler()),
        ]);
    }

    public function excel(ErpRaporExcelRequest $request, ErpRaporExcelAktarici $aktarici): BinaryFileResponse
    {
        $satirlar = $this->rapor->sec(
            $this->rapor->satirlar($this->erpKullaniciId($request), $request->parametreler()),
            $request->anahtarlar(),
        );

        return response()
            ->download($aktarici->olustur($this->rapor, $satirlar), $this->rapor::DOSYA_ADI, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    /** Parametre formunun proje kodu / firma adı seçenekleri */
    public function secenekler(ErpRaporSecenekleri $secenekler): JsonResponse
    {
        return response()->json(['data' => $secenekler->getir()]);
    }

    /**
     * Proc, kullanıcının ERP güvenlik yetkilerine göre süzer (ERP KULLANICI_ID;
     * girişte VOHOM_ARAMA_KULLANICI'dan yansıtılır). ERP kimliği olmayan lokal
     * admin -1 gönderir: proc satır döndürmez (SecenekController ile aynı kural).
     */
    private function erpKullaniciId(ErpRaporRequest $request): int
    {
        return $request->user()?->erp_kullanici_id ?? -1;
    }
}
