<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\TumKesintilerListeRequest;
use App\Http\Resources\TumKesintiResource;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\TumKesintilerKolonlari;
use App\Services\Kesinti\TumKesintilerSorgusu;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Tüm Kesintiler: bütün icmallerin ortak kolonları tek
 * listede (akış: IcmalSatiriController). Yalnız rapor — satır girilmez,
 * düzeltilmez; `tum_kesintiler.goruntule` izniyle korunur.
 */
final class TumKesintilerController extends IcmalSatiriController
{
    protected const RESOURCE = TumKesintiResource::class;

    public function __construct(TumKesintilerSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(TumKesintilerListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(TumKesintilerListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(TumKesintilerListeRequest $request, TumKesintilerKolonlari $kolonlar, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $kolonlar, $aktarici, 'Tüm Kesintiler', 'tum-kesintiler.xlsx');
    }
}
