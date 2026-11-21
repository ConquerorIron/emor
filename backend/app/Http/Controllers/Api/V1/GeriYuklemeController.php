<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\IstekAnahtari;
use App\Models\GeriYukleme;
use App\Models\Yedek;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\KopyaVeritabanlari;
use App\Services\Yedekleme\VeritabaniAdi;
use App\Services\Yedekleme\YedekIstekServisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ayarlar → Geri Yüklemeler (BY-027; README §4.5-A, §4.10 (3)). Geçmiş
 * işlemler ve bekleyen kopyalar okunur; "farklı adla geri yükle" ve kopya
 * silme istek klasörüne bırakılır, işi çalıştırıcı yapar (BY-025/026).
 *
 * Kopya silme yalnız bu sistemin açtığı ve kaydını tuttuğu `erp_geri_*`
 * veritabanlarında: `erp` ve kalıp dışı adlar ekrandan silinemez
 * (çalıştırıcı da aynı denetimi yapar).
 */
final class GeriYuklemeController extends Controller
{
    public function __construct(private readonly YedekIstekServisi $istekler) {}

    public function index(KopyaVeritabanlari $kopyalar): JsonResponse
    {
        $liste = GeriYukleme::query()->with(['yedek:id,kimlik,dosya_adi,alinma_zamani', 'isteyenKullanici:id,ad'])
            ->orderByDesc('id')->limit(200)->get();
        $boyutlar = $this->boyutlar($liste->filter(fn (GeriYukleme $g): bool => $this->bekleyenKopyaMi($g))->pluck('hedef_veritabani')->all());

        return response()->json(['data' => $liste->map(fn (GeriYukleme $g): array => [
            'kimlik' => $g->kimlik,
            'tur' => $g->tur->value,
            'durum' => $g->durum->value,
            'yedek' => $g->yedek === null ? null : ['kimlik' => $g->yedek->kimlik, 'dosya_adi' => $g->yedek->dosya_adi, 'alinma_zamani' => $g->yedek->alinma_zamani?->toIso8601String()],
            'hedef_veritabani' => $g->hedef_veritabani,
            'onceki_veritabani' => $g->onceki_veritabani,
            'isteyen' => $g->isteyenKullanici?->ad,
            'adimlar' => $g->adimlar ?? [],
            'hata_kodu' => $g->hata_kodu,
            'basladi' => $g->basladi?->toIso8601String(),
            'bitti' => $g->bitti?->toIso8601String(),
            'veritabani_silinme_zamani' => $g->veritabani_silinme_zamani?->toIso8601String(),
            'bekleyen_kopya' => $this->bekleyenKopyaMi($g),
            'silinecegi_zaman' => $kopyalar->silinecegiZaman($g)?->toIso8601String(),
            'boyut' => $boyutlar[$g->hedef_veritabani] ?? null,
        ])->values()]);
    }

    /** "Farklı adla geri yükle": canlıya dokunmaz; hedef adı sistem üretir (isteğe bağlı kısa ek) */
    public function kopyaGeriYukle(Request $request, string $kimlik): JsonResponse
    {
        /** @var array{ek?: string|null} $veri */
        $veri = $request->validate(['ek' => ['nullable', 'string', 'regex:'.VeritabaniAdi::EK_DESENI]]);
        $yedek = Yedek::query()->where('kimlik', $kimlik)->firstOrFail();

        if (! $yedek->geriYuklenebilirMi()) {
            return response()->json(['kod' => 'GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ', 'mesaj' => __('hata.yedek_geri_yuklenemez')], 409);
        }

        $istek = $this->istekler->birak('kopya_geri_yukle', ['yedek_kimligi' => $yedek->kimlik, 'ek' => $veri['ek'] ?? null], (int) $request->user()->id, $yedek->id, IstekAnahtari::oku($request));

        return response()->json(['data' => ['istek_kimligi' => $istek, 'durum' => 'kuyrukta']], 202);
    }

    /** Bekleyen kopyayı sil (onaylı); yalnız kaydı olan `erp_geri_*` */
    public function kopyaSil(Request $request, string $kimlik): JsonResponse
    {
        $geri = GeriYukleme::query()->where('kimlik', $kimlik)->firstOrFail();

        if (! $this->bekleyenKopyaMi($geri) || ! VeritabaniAdi::kopyaMi($geri->hedef_veritabani)) {
            return response()->json(['kod' => 'GERI_YUKLEME_KOPYA_YOK', 'mesaj' => __('hata.geri_yukleme_kopya_yok')], 409);
        }

        $istek = $this->istekler->birak('kopya_sil', ['veritabani' => $geri->hedef_veritabani], (int) $request->user()->id, $geri->yedek_id);

        return response()->json(['data' => ['istek_kimligi' => $istek, 'durum' => 'kuyrukta']], 202);
    }

    private function bekleyenKopyaMi(GeriYukleme $geri): bool
    {
        return $geri->tur === GeriYuklemeTuru::Kopya
            && $geri->durum === GeriYuklemeDurumu::Tamam
            && $geri->veritabani_silinme_zamani === null;
    }

    /**
     * Bekleyen kopyaların boyutu (bayt). Yalnız PostgreSQL'de; okunamazsa boş
     * (kopya uygulama rolünündür, `pg_database_size` yetki ister).
     *
     * @param  list<string>  $adlar
     * @return array<string, int>
     */
    private function boyutlar(array $adlar): array
    {
        $adlar = array_values(array_filter($adlar, fn (string $ad): bool => VeritabaniAdi::kopyaMi($ad)));
        if ($adlar === [] || DB::connection()->getDriverName() !== 'pgsql') {
            return [];
        }

        try {
            $yer = implode(',', array_fill(0, count($adlar), '?'));

            return collect(DB::select("select datname, pg_database_size(datname) as boyut from pg_database where datname in ({$yer})", $adlar))
                ->mapWithKeys(fn (object $s): array => [$s->datname => (int) $s->boyut])->all();
        } catch (Throwable $hata) {
            report($hata);

            return [];
        }
    }
}
