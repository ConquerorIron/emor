<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EFatura\EFaturaListeRequest;
use App\Services\Entegrator\EFaturaPanosu;
use App\Services\Entegrator\EFaturaSorgusu;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\PanoFirmaExcelAktarici;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * e-Fatura panosu (EK-09). Kapsam ve süzgeç Tüm Faturalar'la aynıdır:
 * panodaki sayıya tıklanınca açılan liste aynı toplamı gösterir. Yetki rota
 * middleware'indedir (`can:efatura_panosu.goruntule`).
 */
final class EFaturaPanosuController extends Controller
{
    public function __construct(
        private readonly EntegratorBaglantiServisi $baglantilar,
        private readonly EFaturaPanosu $pano,
        private readonly EFaturaSorgusu $sorgu,
    ) {}

    public function index(EFaturaListeRequest $request): JsonResponse
    {
        $filtre = $request->filtre();

        return response()->json([
            'data' => $this->pano->ozet($this->baglantilar->gelenTanimlari(), $filtre['baslangic'], $filtre['bitis']),
        ]);
    }

    /** Gönderen firma bazlı döküm: Tüm Faturalar süzgeci (portal, sınıf) ile. */
    public function firmalar(EFaturaListeRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->firmaDokumu($request)]);
    }

    public function firmalarExcel(EFaturaListeRequest $request, PanoFirmaExcelAktarici $aktarici): BinaryFileResponse
    {
        $filtre = $request->filtre();
        $yol = $aktarici->olustur($this->firmaDokumu($request));

        return response()
            ->download($yol, "efatura-firmalar-{$filtre['baslangic']}-{$filtre['bitis']}.xlsx")
            ->deleteFileAfterSend();
    }

    /**
     * @return list<array{vkn: string|null, unvan: string|null, para_birimi: string, adet: int, tutar: string}>
     */
    private function firmaDokumu(EFaturaListeRequest $request): array
    {
        return $this->pano->firmalar(
            $this->sorgu->filtrele($this->baglantilar->gelenTanimlari(), FaturaYonu::Gelen, $request->filtre()),
        );
    }
}
