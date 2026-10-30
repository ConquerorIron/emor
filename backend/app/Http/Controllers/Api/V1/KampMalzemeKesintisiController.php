<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\KampMalzemeKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\KampMalzemeKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\KampMalzemeKesintisiResource;
use App\Models\KampMalzemeKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\KampMalzemeIceAktarimi;
use App\Services\Kesinti\KampMalzemeKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Kamp Malzeme Kesintileri (akış: IcmalSatiriController).
 * `kamp_malzeme_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class KampMalzemeKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = KampMalzemeKesintisiResource::class;

    public function __construct(KampMalzemeKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(KampMalzemeKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(KampMalzemeKesintisiListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(KampMalzemeKesintisiListeRequest $request, KampMalzemeIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'Kamp Malzeme Kesintileri', 'kamp-malzeme-kesintileri.xlsx');
    }

    public function store(KampMalzemeKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new KampMalzemeKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(KampMalzemeKesintisiKaydetRequest $request, KampMalzemeKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(KampMalzemeKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
