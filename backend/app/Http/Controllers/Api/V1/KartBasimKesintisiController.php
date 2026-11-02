<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\KartBasimKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\KartBasimKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\KartBasimKesintisiResource;
use App\Models\KartBasimKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\KartBasimIceAktarimi;
use App\Services\Kesinti\KartBasimKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Personel Kart Basım Kesintisi (akış: IcmalSatiriController).
 * `kart_basim_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class KartBasimKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = KartBasimKesintisiResource::class;

    public function __construct(KartBasimKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(KartBasimKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function pivot(KartBasimKesintisiListeRequest $request, string $pivot): JsonResponse
    {
        return $this->pivotla($request, $pivot);
    }

    public function excel(KartBasimKesintisiListeRequest $request, KartBasimIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'Personel Kart Basım Kesintisi', 'personel-kart-basim-kesintisi.xlsx');
    }

    public function store(KartBasimKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new KartBasimKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(KartBasimKesintisiKaydetRequest $request, KartBasimKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(KartBasimKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
