<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\IsgucuKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\IsgucuKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\IsgucuKesintisiResource;
use App\Models\IsgucuKesintisi;
use App\Services\Kesinti\IsgucuKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Kesinti Yönetimi → İşgücü Kesintileri (akış: IcmalSatiriController).
 * `isgucu_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class IsgucuKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = IsgucuKesintisiResource::class;

    public function __construct(IsgucuKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(IsgucuKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function store(IsgucuKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new IsgucuKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(IsgucuKesintisiKaydetRequest $request, IsgucuKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(IsgucuKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
