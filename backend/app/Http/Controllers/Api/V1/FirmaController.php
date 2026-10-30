<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\FirmaExcelRequest;
use App\Http\Requests\Kesinti\FirmaKaydetRequest;
use App\Http\Resources\FirmaResource;
use App\Models\Firma;
use App\Services\Kesinti\FirmaExcelAktarici;
use App\Support\KayitSilme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /** Ekranda aranıp sıralanmış firmaların tamamı, tüm kolonlar, ekrandaki sırayla .xlsx */
    public function excel(FirmaExcelRequest $request, FirmaExcelAktarici $aktarici): BinaryFileResponse
    {
        return response()
            ->download($aktarici->olustur($request->idler()), 'firmalar.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'private, no-store',
            ])
            ->deleteFileAfterSend();
    }

    public function store(FirmaKaydetRequest $request): JsonResponse
    {
        $firma = $this->kaydet(new Firma, $request->validated());

        return FirmaResource::make($firma)->response()->setStatusCode(201);
    }

    public function update(FirmaKaydetRequest $request, Firma $firma): FirmaResource
    {
        return FirmaResource::make($this->kaydet($firma, $request->validated()));
    }

    /**
     * Yalnız tek firma "firmamız" olabilir: işaretlenen firmaya geçer, öncekinin
     * işareti kalkar (aynı işlemde; kısmi benzersiz indeks de garanti eder).
     *
     * @param  array<string, mixed>  $veri
     */
    private function kaydet(Firma $firma, array $veri): Firma
    {
        return DB::transaction(function () use ($firma, $veri): Firma {
            if (($veri['firmamiz'] ?? false) === true) {
                Firma::query()
                    ->where('firmamiz', true)
                    ->when($firma->exists, fn (Builder $q) => $q->whereKeyNot($firma->getKey()))
                    ->update(['firmamiz' => false]);
            }

            $firma->fill($veri)->save();

            return $firma;
        });
    }

    public function destroy(Firma $firma): Response
    {
        KayitSilme::sil($firma);

        return response()->noContent();
    }
}
