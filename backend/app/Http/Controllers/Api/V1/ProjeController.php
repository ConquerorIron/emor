<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\ProjeKaydetRequest;
use App\Http\Resources\ProjeResource;
use App\Models\Proje;
use App\Support\KayitSilme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Kesinti Yönetimi → Projeler. `projeler.goruntule` / `projeler.guncelle`
 * izinleriyle korunur (routes/api.php). ERP'den aktarım ErpProjeController'da.
 */
final class ProjeController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ProjeResource::collection(Proje::query()->orderBy('kod')->get());
    }

    public function store(ProjeKaydetRequest $request): JsonResponse
    {
        $proje = Proje::query()->create($request->validated());

        return ProjeResource::make($proje)->response()->setStatusCode(201);
    }

    public function update(ProjeKaydetRequest $request, Proje $proje): ProjeResource
    {
        $proje->update($request->validated());

        return ProjeResource::make($proje);
    }

    public function destroy(Proje $proje): Response
    {
        KayitSilme::sil($proje);

        return response()->noContent();
    }
}
