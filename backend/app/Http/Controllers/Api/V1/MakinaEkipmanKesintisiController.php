<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Kesinti\MakinaEkipmanKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\MakinaEkipmanKesintisiListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Http\Resources\MakinaEkipmanKesintisiResource;
use App\Models\MakinaEkipmanKesintisi;
use App\Services\Kesinti\MakinaEkipmanKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Kesinti Yönetimi → Makina Ekipman Kesintileri (akış: IcmalSatiriController).
 * `makina_ekipman_kesintileri.goruntule` / `.guncelle` izinleriyle korunur.
 */
final class MakinaEkipmanKesintisiController extends IcmalSatiriController
{
    protected const RESOURCE = MakinaEkipmanKesintisiResource::class;

    public function __construct(MakinaEkipmanKesintisiSorgusu $sorgu)
    {
        parent::__construct($sorgu);
    }

    public function index(MakinaEkipmanKesintisiListeRequest $request): JsonResponse
    {
        return $this->liste($request);
    }

    public function store(MakinaEkipmanKesintisiKaydetRequest $request): JsonResponse
    {
        return $this->olustur(new MakinaEkipmanKesintisi, $request->kayit(), $request->user()?->id);
    }

    public function update(MakinaEkipmanKesintisiKaydetRequest $request, MakinaEkipmanKesintisi $kesinti): IcmalSatiriResource
    {
        return $this->guncelle($kesinti, $request->kayit());
    }

    public function destroy(MakinaEkipmanKesintisi $kesinti): Response
    {
        return $this->sil($kesinti);
    }
}
