<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\OsgbKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\OsgbKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\OsgbKesintisiResource;
use App\Models\OsgbKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\OsgbIceAktarimi;
use App\Services\Kesinti\OsgbKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → OSGB Kesintisi (akış: IcmalSatiriController).
 * `osgb_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class OsgbKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = OsgbKesintisiResource::class;

    public function __construct(OsgbKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(OsgbKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(OsgbKesintisiListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(OsgbKesintisiListeRequest $request, OsgbIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'OSGB Kesintisi', 'osgb-kesintisi.xlsx');
    }

    public function store(OsgbKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new OsgbKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(OsgbKesintisiKaydetRequest $request, OsgbKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(OsgbKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
