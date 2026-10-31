<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\TetkikKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\TetkikKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\TetkikKesintisiResource;
use App\Models\TetkikKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\TetkikIceAktarimi;
use App\Services\Kesinti\TetkikKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Tetkik Kesintisi (akış: IcmalSatiriController).
 * `tetkik_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class TetkikKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = TetkikKesintisiResource::class;

    public function __construct(TetkikKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(TetkikKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(TetkikKesintisiListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(TetkikKesintisiListeRequest $request, TetkikIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'Tetkik Kesintisi', 'tetkik-kesintisi.xlsx');
    }

    public function store(TetkikKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new TetkikKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(TetkikKesintisiKaydetRequest $request, TetkikKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(TetkikKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
