<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Kesinti icmal formlarının seçim listeleri (tür, taşeron, proje, para
 * birimi). Tanım ekranlarına izni olmayan icmal kullanıcısı da formu
 * doldurabilsin diye ayrı uç: `kesinti-secenekleri` Gate'i (AppServiceProvider).
 */
final class KesintiSecenekController extends Controller
{
    public function index(): JsonResponse
    {
        /** @param Builder<Model> $sorgu */
        $liste = fn (Builder $sorgu): array => $sorgu->orderBy('kod')->get(['id', 'kod', 'ad'])
            ->map(fn (Model $kayit): array => ['id' => $kayit->getKey(), 'kod' => $kayit->getAttribute('kod'), 'ad' => $kayit->getAttribute('ad')])
            ->all();

        return response()->json(['data' => [
            'kesinti_turleri' => $liste(KesintiTuru::query()),
            'firmalar' => $liste(Firma::query()),
            'projeler' => $liste(Proje::query()),
            'para_birimleri' => $liste(ParaBirimi::query()),
        ]]);
    }
}
