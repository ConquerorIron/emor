<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kesinti\IcmalIceAktarRequest;
use App\Http\Resources\KesintiYuklemesiResource;
use App\Models\KesintiYuklemesi;
use App\Services\Kesinti\IcmalIceAktarimi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

/**
 * Kesinti icmali yüklemeleri (partiler): yapıştırma tablosu kolonları,
 * Excel / yapıştırma önizlemesi, kayıt ve yanlış yüklemenin geri alınması.
 * Her icmal ince bir alt sınıfla kendi aktarımını ve istek sınıfını verir.
 */
abstract class IcmalYuklemeController extends Controller
{
    /** Yüklemeler listesinde gösterilen son parti sayısı */
    private const LISTE_SINIRI = 200;

    public function __construct(
        protected readonly IcmalIceAktarimi $aktarim,
    ) {}

    /** Yapıştırma tablosunun kolonları ve kabul edilen başlık adları (tek kaynak: aktarım tanımı) */
    public function kolonlar(): JsonResponse
    {
        return response()->json(['data' => $this->aktarim->kolonTanimi()]);
    }

    public function index(): AnonymousResourceCollection
    {
        return KesintiYuklemesiResource::collection(
            KesintiYuklemesi::query()
                ->where('icmal', $this->aktarim::ICMAL)
                ->with('yukleyen:id,ad')
                ->latest()
                ->orderByDesc('id')
                ->limit(self::LISTE_SINIRI)
                ->get(),
        );
    }

    /** Partiyi satırlarıyla birlikte siler (cascade). Başka icmalin partisi bulunamaz. */
    public function destroy(KesintiYuklemesi $yukleme): Response
    {
        abort_unless($yukleme->icmal === $this->aktarim::ICMAL, 404);

        $yukleme->delete();

        return response()->noContent();
    }

    /** Yazmadan doğrular: hata özeti, satırlar ve para birimine göre toplamlar. */
    protected function onizleme(IcmalIceAktarRequest $request): JsonResponse
    {
        return response()->json(['data' => Arr::except($this->incele($request), 'kayitlar')]);
    }

    /**
     * Hatasızsa tüm satırları tek parti olarak kaydeder; tek hata bile varsa
     * hiçbirini. Önizleme şart değildir (kullanıcı kararı 2026-10-30): red
     * yanıtı önizlemeyle aynı sonucu taşır, ekran hataları oradan gösterir.
     */
    protected function kayit(IcmalIceAktarRequest $request): JsonResponse
    {
        $sonuc = $this->incele($request);

        if ($sonuc['kayitlar'] === []) {
            return response()->json([
                'kod' => 'ICE_AKTARIM_HATALI',
                'mesaj' => __('hata.ice_aktarim_hatali'),
                'data' => Arr::except($sonuc, 'kayitlar'),
            ], 422);
        }

        $yukleme = $this->aktarim->kaydet($sonuc['kayitlar'], $request->kaynak(), $request->dosyaAdi(), $request->user()?->id);

        return KesintiYuklemesiResource::make($yukleme->load('yukleyen:id,ad'))->response()->setStatusCode(201);
    }

    /**
     * @return array{hata: string|null, eksik_kolonlar: list<string>, satirlar: list<array<string, mixed>>, kayitlar: list<array<string, mixed>>, hatalar: list<array{alan: string, deger: string, kod: string, adet: int, satirlar: list<string>}>, toplamlar: list<array<string, int|string>>}
     */
    private function incele(IcmalIceAktarRequest $request): array
    {
        $dosya = $request->dosya();
        $tablo = $dosya !== null
            ? $this->aktarim->excelOku($dosya->getRealPath())
            : $this->aktarim->metinOku((string) $request->input('metin'));

        return $this->aktarim->incele($tablo);
    }
}
