<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\IsgucuIceAktarRequest;
use App\Http\Resources\KesintiYuklemesiResource;
use App\Models\KesintiYuklemesi;
use App\Services\Kesinti\IceAktarimTablosu;
use App\Services\Kesinti\IsgucuIceAktarimi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * İşgücü Kesintileri yüklemeleri (partiler): Excel / yapıştırma önizlemesi,
 * kayıt ve yanlış yüklemenin geri alınması (satırlarıyla birlikte).
 */
final class IsgucuYuklemeController extends Controller
{
    /** Yüklemeler listesinde gösterilen son parti sayısı */
    private const LISTE_SINIRI = 200;

    public function __construct(
        private readonly IsgucuIceAktarimi $aktarim,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return KesintiYuklemesiResource::collection(
            KesintiYuklemesi::query()
                ->where('icmal', KesintiYuklemesi::ICMAL_ISGUCU)
                ->with('yukleyen:id,ad')
                ->latest()
                ->orderByDesc('id')
                ->limit(self::LISTE_SINIRI)
                ->get(),
        );
    }

    /**
     * İçe aktarım kolonları, standart sırayla: yapıştırma tablosu başlıkları
     * ve kabul edilen başlık adları (tek kaynak: IsgucuIceAktarimi::KOLONLAR).
     */
    public function kolonlar(): JsonResponse
    {
        return response()->json(['data' => array_map(
            fn (string $alan, array $basliklar): array => ['alan' => $alan, 'basliklar' => $basliklar],
            array_keys(IsgucuIceAktarimi::KOLONLAR),
            array_values(IsgucuIceAktarimi::KOLONLAR),
        )]);
    }

    /** Yazmadan doğrular: hata özeti, satırlar ve para birimine göre toplamlar. */
    public function onizle(IsgucuIceAktarRequest $request): JsonResponse
    {
        return response()->json(['data' => Arr::except($this->incele($request), 'kayitlar')]);
    }

    /** Hatasızsa tüm satırları tek parti olarak kaydeder; tek hata bile varsa hiçbirini. */
    public function store(IsgucuIceAktarRequest $request): JsonResponse
    {
        $sonuc = $this->incele($request);

        if ($sonuc['kayitlar'] === []) {
            return response()->json([
                'kod' => 'ICE_AKTARIM_HATALI',
                'mesaj' => __('hata.ice_aktarim_hatali'),
                'data' => Arr::except($sonuc, ['kayitlar', 'satirlar']),
            ], 422);
        }

        $yukleme = $this->aktarim->kaydet($sonuc['kayitlar'], $request->kaynak(), $request->dosyaAdi(), $request->user()?->id);

        return KesintiYuklemesiResource::make($yukleme->load('yukleyen:id,ad'))->response()->setStatusCode(201);
    }

    /** Partiyi satırlarıyla birlikte siler (cascade). */
    public function destroy(KesintiYuklemesi $yukleme): Response
    {
        abort_unless($yukleme->icmal === KesintiYuklemesi::ICMAL_ISGUCU, 404);

        $yukleme->delete();

        return response()->noContent();
    }

    /**
     * @return array{hata: string|null, eksik_kolonlar: list<string>, satirlar: list<array<string, mixed>>, kayitlar: list<array<string, mixed>>, hatalar: list<array{alan: string, deger: string, kod: string, adet: int, satirlar: list<int>}>, toplamlar: list<array<string, int|string>>}
     */
    private function incele(IsgucuIceAktarRequest $request): array
    {
        $dosya = $request->dosya();
        $tablo = $dosya !== null
            ? IceAktarimTablosu::excel($dosya->getRealPath(), IsgucuIceAktarimi::KOLONLAR)
            : IceAktarimTablosu::metin((string) $request->input('metin'), IsgucuIceAktarimi::KOLONLAR);

        return $this->aktarim->incele($tablo);
    }
}
