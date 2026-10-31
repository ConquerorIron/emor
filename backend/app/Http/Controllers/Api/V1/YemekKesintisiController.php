<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\YemekKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\YemekKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\YemekKesintisiResource;
use App\Models\YemekKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\YemekIceAktarimi;
use App\Services\Kesinti\YemekKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Yemek Kesintisi (akış: IcmalSatiriController).
 * `yemek_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class YemekKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = YemekKesintisiResource::class;

    public function __construct(YemekKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(YemekKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(YemekKesintisiListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(YemekKesintisiListeRequest $request, YemekIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'Yemek Kesintisi', 'yemek-kesintisi.xlsx');
    }

    public function store(YemekKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new YemekKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(YemekKesintisiKaydetRequest $request, YemekKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(YemekKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
