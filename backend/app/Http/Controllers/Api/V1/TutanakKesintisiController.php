<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\TutanakKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\TutanakKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\TutanakKesintisiResource;
use App\Models\TutanakKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\TutanakIceAktarimi;
use App\Services\Kesinti\TutanakKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Tutanak Kesintisi (akış: IcmalSatiriController).
 * `tutanak_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class TutanakKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = TutanakKesintisiResource::class;

    public function __construct(TutanakKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(TutanakKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(TutanakKesintisiListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(TutanakKesintisiListeRequest $request, TutanakIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'Tutanak Kesintisi', 'tutanak-kesintisi.xlsx');
    }

    public function store(TutanakKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new TutanakKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(TutanakKesintisiKaydetRequest $request, TutanakKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(TutanakKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
