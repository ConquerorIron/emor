<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\IsgCezaKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\IsgCezaKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\IsgCezaKesintisiResource;
use App\Models\IsgCezaKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\IsgCezaIceAktarimi;
use App\Services\Kesinti\IsgCezaKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → İSG Ceza Kesintisi (akış: IcmalSatiriController).
 * `isg_ceza_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class IsgCezaKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = IsgCezaKesintisiResource::class;

    public function __construct(IsgCezaKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(IsgCezaKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(IsgCezaKesintisiListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(IsgCezaKesintisiListeRequest $request, IsgCezaIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'İSG Ceza Kesintisi', 'isg-ceza-kesintisi.xlsx');
    }

    public function store(IsgCezaKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new IsgCezaKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(IsgCezaKesintisiKaydetRequest $request, IsgCezaKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(IsgCezaKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
