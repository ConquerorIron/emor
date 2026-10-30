<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\IcmalListeRequest;
use App\Http\Resources\IcmalSatiriResource;
use App\Services\Kesinti\IcmalExcelAktarici;
use App\Services\Kesinti\IcmalIceAktarimi;
use App\Services\Kesinti\IcmalSorgusu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Kesinti icmali satırları: liste (filtre, sıralama, sayfa, para birimine
 * göre toplamlar) ve elle satır girişi. Her icmal ince bir alt sınıfla kendi
 * sorgusunu, resource'unu ve (route binding için) tipli uçlarını verir;
 * içe aktarım IcmalYuklemeController'da.
 */
abstract class IcmalSatiriController extends Controller
{
    private const ILISKILER = ['kesintiTuru:id,kod,ad', 'firma:id,kod,ad', 'proje:id,kod,ad', 'paraBirimi:id,kod,ad'];

    /** Excel'e aktarımda en fazla satır (bellek; ~2.400 satırlık İşgücü icmalinin katları) */
    private const EXCEL_SINIRI = 50000;

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

    /** Özet (pivot) sekmesi: listeyle aynı filtre, sekmenin alanlarına göre gruplanmış toplamlar */
    protected function pivotla(IcmalListeRequest $request, string $pivot): JsonResponse
    {
        abort_unless(in_array($pivot, $this->sorgu::pivotlar(), true), 404);

        return response()->json(['data' => $this->sorgu->pivot($this->sorgu->filtrele($request->filtre()), $pivot)]);
    }

    /**
     * Filtredeki tüm satırlar, tüm kolonlar (gizliler dahil), listedeki sırayla
     * .xlsx olarak; ekranın özet sekmeleri de aynı filtreyle sekme başına ayrı
     * sayfa (kullanıcı istekleri 2026-10-30).
     */
    protected function excelAktar(IcmalListeRequest $request, IcmalIceAktarimi $aktarim, IcmalExcelAktarici $aktarici, string $baslik, string $dosyaAdi): BinaryFileResponse|JsonResponse
    {
        $sorgu = $this->sorgu->filtrele($request->filtre());
        $adet = $sorgu->count();

        if ($adet > self::EXCEL_SINIRI) {
            return response()->json([
                'kod' => 'KESINTI_EXCEL_COK_BUYUK',
                'mesaj' => __('hata.kesinti_excel_cok_buyuk', ['adet' => $adet, 'sinir' => self::EXCEL_SINIRI]),
            ], 422);
        }

        $pivotlar = array_map(fn (string $pivot): array => [
            'ad' => __("kesinti.excel.pivot.{$pivot}"),
            'gruplar' => $this->sorgu::pivotGruplari($pivot),
            'toplamlar' => $this->sorgu::pivotToplamlari(),
            'satirlar' => $this->sorgu->pivot($sorgu, $pivot),
        ], $this->sorgu::pivotlar());

        $yol = $aktarici->olustur(
            $this->sorgu->sirala($sorgu->clone(), $request->input('sirala'), $request->input('yon')),
            $aktarim,
            $baslik,
            $pivotlar,
        );

        return response()
            ->download($yol, $dosyaAdi, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
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
