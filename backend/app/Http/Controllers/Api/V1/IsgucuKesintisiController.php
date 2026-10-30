<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\IsgucuKesintisiKaydetRequest;
use App\Http\Requests\Kesinti\IsgucuKesintisiListeRequest;
use App\Http\Resources\IsgucuKesintisiResource;
use App\Models\IsgucuKesintisi;
use App\Services\Kesinti\IsgucuKesintisiSorgusu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Kesinti Yönetimi → İşgücü Kesintileri: liste (filtre, sıralama, sayfa,
 * toplamlar) ve elle satır girişi. `isgucu_kesintileri.goruntule` /
 * `.guncelle` izinleriyle korunur; içe aktarım IsgucuYuklemeController'da.
 */
final class IsgucuKesintisiController extends Controller
{
    private const ILISKILER = ['kesintiTuru:id,kod,ad', 'firma:id,kod,ad', 'proje:id,kod,ad', 'paraBirimi:id,kod,ad'];

    public function __construct(
        private readonly IsgucuKesintisiSorgusu $sorgu,
    ) {}

    public function index(IsgucuKesintisiListeRequest $request): JsonResponse
    {
        $sorgu = $this->sorgu->filtrele($request->filtre());
        $sayfa = $this->sorgu->sirala($sorgu->clone(), $request->input('sirala'), $request->input('yon'))
            ->with(self::ILISKILER)
            ->paginate($request->sayfaBoyutu());

        return IsgucuKesintisiResource::collection($sayfa)
            ->additional(['ozet' => $this->sorgu->ozet($sorgu)])
            ->response();
    }

    public function store(IsgucuKesintisiKaydetRequest $request): JsonResponse
    {
        $kesinti = IsgucuKesintisi::query()->create([...$request->kayit(), 'kaydeden_id' => $request->user()?->id]);

        return IsgucuKesintisiResource::make($kesinti->load(self::ILISKILER))->response()->setStatusCode(201);
    }

    public function update(IsgucuKesintisiKaydetRequest $request, IsgucuKesintisi $kesinti): IsgucuKesintisiResource
    {
        $kesinti->update($request->kayit());

        return IsgucuKesintisiResource::make($kesinti->load(self::ILISKILER));
    }

    public function destroy(IsgucuKesintisi $kesinti): Response
    {
        $kesinti->delete();

        return response()->noContent();
    }
}
