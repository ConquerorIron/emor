<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\KesintiTuruKaydetRequest;
use App\Http\Resources\KesintiTuruResource;
use App\Models\KesintiTuru;
use App\Support\KayitSilme;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Kesinti Yönetimi → Kesinti Türleri. `kesinti_turleri.goruntule` /
 * `kesinti_turleri.guncelle` izinleriyle korunur (routes/api.php).
 */
final class KesintiTuruController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return KesintiTuruResource::collection(KesintiTuru::query()->orderBy('kod')->get());
    }

    public function store(KesintiTuruKaydetRequest $request): JsonResponse
    {
        $tur = KesintiTuru::query()->create($request->validated());

        return KesintiTuruResource::make($tur)->response()->setStatusCode(201);
    }

    public function update(KesintiTuruKaydetRequest $request, KesintiTuru $kesintiTuru): KesintiTuruResource
    {
        $kesintiTuru->update($request->validated());

        return KesintiTuruResource::make($kesintiTuru);
    }

    public function destroy(KesintiTuru $kesintiTuru): Response
    {
        KayitSilme::sil($kesintiTuru);

        return response()->noContent();
    }
}
