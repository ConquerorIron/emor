<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\YedekIslemKaydi;
use App\Services\Yedekleme\IslemSonucu;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ayarlar → İşlem Günlüğü (BY-018; README §4.9). Salt okunur: kayıt ekleme,
 * değiştirme ya da silme ucu yoktur (model de güncelleme / silmeyi reddeder).
 */
final class YedekIslemKaydiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var array{baslangic?: string, bitis?: string, kullanici_id?: int, islem?: string, sonuc?: string, sayfa_boyutu?: int} $veri */
        $veri = $request->validate([
            'baslangic' => ['nullable', 'date_format:Y-m-d'],
            'bitis' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:baslangic'],
            'kullanici_id' => ['nullable', 'integer'],
            'islem' => ['nullable', 'string', 'max:48', 'regex:/^[a-z_:]+$/D'],
            'sonuc' => ['nullable', Rule::enum(IslemSonucu::class)],
            'sayfa_boyutu' => ['nullable', 'integer', 'between:10,100'],
        ]);
        $gun = fn (string $tarih): CarbonImmutable => CarbonImmutable::createFromFormat('!Y-m-d', $tarih, 'Europe/Istanbul');

        $sayfa = YedekIslemKaydi::query()
            ->with(['kullanici:id,ad', 'yedek:id,kimlik,dosya_adi'])
            ->when($veri['baslangic'] ?? null, fn ($q, string $t) => $q->where('created_at', '>=', $gun($t)->utc()))
            ->when($veri['bitis'] ?? null, fn ($q, string $t) => $q->where('created_at', '<', $gun($t)->addDay()->utc()))
            ->when($veri['kullanici_id'] ?? null, fn ($q, int $id) => $q->where('kullanici_id', $id))
            ->when($veri['islem'] ?? null, fn ($q, string $i) => $q->where('islem', $i))
            ->when($veri['sonuc'] ?? null, fn ($q, string $s) => $q->where('sonuc', $s))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($veri['sayfa_boyutu'] ?? 50)
            ->withQueryString();

        // Serbest hata metni yol / komut çıktısı (pg_dump stderr: sunucu, rol)
        // taşıyabilir: kardeş uçlar (Yedekler listesi, istek durumu) onu hiç
        // döndürmez; burada yalnız sistem yöneticisine döner, diğerleri kodu
        // `ayrinti.kod`'dan okur (D2, YF-30)
        $hataMetniGorur = $request->user()?->sistem_yoneticisi === true;

        return response()->json([
            'data' => $sayfa->getCollection()->map(fn (YedekIslemKaydi $k): array => [
                'id' => $k->id,
                'zaman' => $k->created_at->toIso8601String(),
                'kullanici' => $k->kullanici?->ad,
                'islem' => $k->islem,
                'sonuc' => $k->sonuc->value,
                'sure_ms' => $k->sure_ms,
                'yedek' => $k->yedek === null ? null : ['kimlik' => $k->yedek->kimlik, 'dosya_adi' => $k->yedek->dosya_adi],
                'ayrinti' => (object) ($k->ayrinti ?? []),
                'hata_mesaji' => $hataMetniGorur ? $k->hata_mesaji : null,
            ])->values(),
            'meta' => ['current_page' => $sayfa->currentPage(), 'last_page' => $sayfa->lastPage(), 'per_page' => $sayfa->perPage(), 'total' => $sayfa->total()],
        ]);
    }
}
