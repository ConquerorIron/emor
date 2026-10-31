<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\MalzemeKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\MalzemeKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\MalzemeKesintisiResource;
use App\Models\MalzemeKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\MalzemeIceAktarimi;
use App\Services\Kesinti\MalzemeKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Malzeme Kesintisi (akış: IcmalSatiriController).
 * `malzeme_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class MalzemeKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = MalzemeKesintisiResource::class;

    public function __construct(MalzemeKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(MalzemeKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(MalzemeKesintisiListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(MalzemeKesintisiListeRequest $request, MalzemeIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'Malzeme Kesintisi', 'malzeme-kesintisi.xlsx');
    }

    public function store(MalzemeKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new MalzemeKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(MalzemeKesintisiKaydetRequest $request, MalzemeKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(MalzemeKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
