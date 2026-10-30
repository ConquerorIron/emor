<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\FirmaKaydetRequest;
use App\Http\Resources\FirmaResource;
use App\Models\Firma;
use App\Support\KayitSilme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Kesinti Yönetimi → Firmalar (taşeronlar). `firmalar.goruntule` /
 * `firmalar.guncelle` izinleriyle korunur (routes/api.php). ERP'den aktarım
 * ErpFirmaController'da.
 */
final class FirmaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FirmaResource::collection(Firma::query()->orderBy('ad')->get());
    }

    public function store(FirmaKaydetRequest $request): JsonResponse
    {
        $firma = Firma::query()->create($request->validated());

        return FirmaResource::make($firma)->response()->setStatusCode(201);
    }

    public function update(FirmaKaydetRequest $request, Firma $firma): FirmaResource
    {
        $firma->update($request->validated());

        return FirmaResource::make($firma);
    }

    public function destroy(Firma $firma): Response
    {
        KayitSilme::sil($firma);

        return response()->noContent();
    }
}
