<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rapor\TaseronHakedisleriExcelRequest;
use App\Http\Requests\Rapor\TaseronHakedisleriRequest;
use App\Services\Rapor\TaseronHakedisleriExcelAktarici;
use App\Services\Rapor\TaseronHakedisleriRaporu;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Raporlar → Taşeron Hakedişleri: ERP proc'unun hakedişleri
 * (TaseronHakedisleriRaporu). Ekran süzme, sayfalama ve toplamları
 * tarayıcıda yapar; Excel ekranda süzülen hakedişleri alır. Yalnız rapor;
 * `taseron_hakedisleri.goruntule` izniyle korunur.
 */
final class TaseronHakedisleriController extends Controller
{
    public function __construct(
        private readonly TaseronHakedisleriRaporu $rapor,
    ) {}

    public function index(TaseronHakedisleriRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->rapor->hakedisler($this->erpKullaniciId($request), $request->parametreler()),
        ]);
    }

    public function excel(TaseronHakedisleriExcelRequest $request, TaseronHakedisleriExcelAktarici $aktarici): BinaryFileResponse
    {
        $satirlar = $this->rapor->sec(
            $this->rapor->hakedisler($this->erpKullaniciId($request), $request->parametreler()),
            $request->hakedisIdleri(),
        );

        return response()
            ->download($aktarici->olustur($satirlar, $this->rapor->toplamlar($satirlar)), 'taseron-hakedisleri.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    public function secenekler(): JsonResponse
    {
        return response()->json(['data' => $this->rapor->secenekler()]);
    }

    /**
     * Proc, kullanıcının ERP güvenlik yetkilerine göre süzer. ERP kimliği
     * olmayan lokal admin -1 gönderir: proc satır döndürmez (SecenekController
     * ile aynı kural).
     */
    private function erpKullaniciId(TaseronHakedisleriRequest $request): int
    {
        return $request->user()?->erp_kullanici_id ?? -1;
    }
}
