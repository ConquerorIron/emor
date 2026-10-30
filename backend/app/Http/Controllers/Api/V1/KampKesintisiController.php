<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\KampKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\KampKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\KampKesintisiResource;
use App\Models\KampKesintisi;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\KampIceAktarimi;
use App\Services\Kesinti\KampKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti Yönetimi → Kamp Kesintileri (oda; akış: IcmalSatiriController).
 * `kamp_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class KampKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = KampKesintisiResource::class;

    public function __construct(KampKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(KampKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function excel(KampKesintisiListeRequest $request, KampIceAktarimi $aktarim, IcmalExcelAktarici $aktarici): BinaryFileResponse|JsonResponse
    {
        return $this->excelAktar($request, $aktarim, $aktarici, 'Kamp Kesintileri', 'kamp-kesintileri.xlsx');
    }

    public function store(KampKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new KampKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(KampKesintisiKaydetRequest $request, KampKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(KampKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
