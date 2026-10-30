<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\IcmalListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Services\Kesinti\IcmalSorgusu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Kesinti icmali satırları: liste (filtre, sıralama, sayfa, para birimine
 * göre toplamlar) ve elle satır girişi. Her icmal ince bir alt sınıfla kendi
 * sorgusunu, resource'unu ve (route binding için) tipli uçlarını verir;
 * içe aktarım IcmalYuklemeController'da.
 */
abstract class IcmalSatiriController extends Controller
{
    private const ILISKILER = ['kesintiTuru:id,kod,ad', 'firma:id,kod,ad', 'proje:id,kod,ad', 'paraBirimi:id,kod,ad'];

    /** @var class-string<IcmalSatiriResource> */
    protected const RESOURCE = IcmalSatiriResource::class;

    public function __construct(
        private readonly IcmalSorgusu $sorgu,
    ) {}

    protected function liste(IcmalListeRequest $request): JsonResponse
    {
        $sorgu = $this->sorgu->filtrele($request->filtre());
        $sayfa = $this->sorgu->sirala($sorgu->clone(), $request->input('sirala'), $request->input('yon'))
            ->with(self::ILISKILER)
            ->paginate($request->sayfaBoyutu());

        return (static::RESOURCE)::collection($sayfa)
            ->additional(['ozet' => $this->sorgu->ozet($sorgu)])
            ->response();
    }

    /**
     * @param  array<string, mixed>  $kayit  İsteğin kayit()'ı (hesaplanan tutarlarla)
     */
    protected function olustur(Model $satir, array $kayit, ?int $kaydedenId): JsonResponse
    {
        $satir->fill([...$kayit, 'kaydeden_id' => $kaydedenId])->save();

        return (static::RESOURCE)::make($satir->load(self::ILISKILER))->response()->setStatusCode(201);
    }

    /**
     * @param  array<string, mixed>  $kayit
     */
    protected function guncelle(Model $satir, array $kayit): IcmalSatiriResource
    {
        $satir->update($kayit);

        return (static::RESOURCE)::make($satir->load(self::ILISKILER));
    }

    protected function sil(Model $satir): Response
    {
        $satir->delete();

        return response()->noContent();
    }
}
