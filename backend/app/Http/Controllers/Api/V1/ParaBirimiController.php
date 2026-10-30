<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\ParaBirimiKaydetRequest;
use App\Http\Resources\ParaBirimiResource;
use App\Models\ParaBirimi;
use App\Support\KayitSilme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Kesinti Yönetimi → Para Birimleri. `para_birimleri.goruntule` /
 * `para_birimleri.guncelle` izinleriyle korunur (routes/api.php).
 */
final class ParaBirimiController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ParaBirimiResource::collection(ParaBirimi::query()->orderBy('kod')->get());
    }

    public function store(ParaBirimiKaydetRequest $request): JsonResponse
    {
        $para = ParaBirimi::query()->create($request->validated());

        return ParaBirimiResource::make($para)->response()->setStatusCode(201);
    }

    public function update(ParaBirimiKaydetRequest $request, ParaBirimi $paraBirimi): ParaBirimiResource
    {
        $paraBirimi->update($request->validated());

        return ParaBirimiResource::make($paraBirimi);
    }

    public function destroy(ParaBirimi $paraBirimi): Response
    {
        KayitSilme::sil($paraBirimi);

        return response()->noContent();
    }
}
