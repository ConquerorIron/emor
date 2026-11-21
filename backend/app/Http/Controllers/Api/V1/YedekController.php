<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\IstekAnahtari;
use App\Http\Resources\YedekResource;
use App\Models\Yedek;
use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\GeriYuklemeKaynaklari;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\KullanimDenetimi;
use App\Services\Yedekleme\PgAraclari;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekIstekServisi;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekOzeti;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Ayarlar → Yedekler (BY-014; README §4.10 (1)). Liste ve özet okunur;
 * değiştiren her işlem istek klasörüne bırakılır, işi çalıştırıcı yapar
 * (web isteğinde `pg_dump` yok). Ön koşulu tutmayan istek (sabit / kullanımda
 * yedeği silmek) bırakılmadan 409 ile döner; çalıştırıcı aynı denetimi yine yapar.
 * İstemci `Idempotency-Key` gönderirse aynı eylemin yeniden denemesi ikinci
 * iş bırakmaz, mevcut kabulü alır (IstekAnahtari; BY-014-R2).
 */
final class YedekController extends Controller
{
    /** İstek sonucundan ekrana geçen alanlar (serbest metin `mesaj` dönmez) */
    private const SONUC_ALANLARI = ['kimlik', 'durum', 'kod', 'zaman', 'yedek_kimligi', 'geri_yukleme_kimligi', 'hedef_veritabani', 'dogrulama_durumu', 'yedek_durumu', 'tarama'];

    public function __construct(private readonly YedekIstekServisi $istekler) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var array{kaynak?: string, dogrulama?: string, sabit?: bool, guvenilmeyen?: bool, silinenler?: bool, siralama?: string, yon?: string, sayfa_boyutu?: int} $veri */
        $veri = $request->validate([
            'kaynak' => ['nullable', Rule::enum(YedekKaynagi::class)],
            'dogrulama' => ['nullable', Rule::enum(DogrulamaDurumu::class)],
            'sabit' => ['nullable', 'boolean'],
            'guvenilmeyen' => ['nullable', 'boolean'],
            'silinenler' => ['nullable', 'boolean'],
            'siralama' => ['nullable', Rule::in(['alinma_zamani', 'boyut'])],
            'yon' => ['nullable', Rule::in(['asc', 'desc'])],
            'sayfa_boyutu' => ['nullable', 'integer', 'between:10,100'],
        ]);

        $sorgu = Yedek::query()
            ->with(['kopyalar.hedef', 'alanKullanici'])
            ->when($veri['kaynak'] ?? null, fn ($q, string $k) => $q->where('kaynak', $k))
            ->when($veri['dogrulama'] ?? null, fn ($q, string $d) => $q->where('dogrulama_durumu', $d))
            ->when(isset($veri['sabit']), fn ($q) => $q->where('sabit', (bool) $veri['sabit']))
            ->when(isset($veri['guvenilmeyen']), fn ($q) => $q->where('imzali', ! $veri['guvenilmeyen']))
            ->when(! ($veri['silinenler'] ?? false), fn ($q) => $q->where('durum', '!=', YedekDurumu::Silindi->value))
            ->orderBy($veri['siralama'] ?? 'alinma_zamani', $veri['yon'] ?? 'desc')
            ->orderByDesc('id');

        return YedekResource::collection($sorgu->paginate($veri['sayfa_boyutu'] ?? 25)->withQueryString());
    }

    /**
     * Özet şeridi: son yerel / uzak / sınama zamanları, sıradaki, boyut, boş
     * alan. `canli_veritabani`: üstüne geri yükleme onayında yazılacak ad (BY-030).
     */
    public function ozet(YedekOzeti $ozet, PgAraclari $pg): JsonResponse
    {
        return response()->json(['data' => [...$ozet->hesapla(), 'canli_veritabani' => $pg->baglanti()->veritabani]]);
    }

    /** Bırakılan isteğin durumu (ekran yoklar): kuyrukta / calisiyor / tamam / basarisiz */
    public function istek(IstekKutusu $kutu, string $kimlik): JsonResponse
    {
        $durum = $kutu->durum($kimlik);

        if ($durum === null) {
            return response()->json(['kod' => 'ISTEK_BULUNAMADI', 'mesaj' => __('hata.yedek_istegi_yok')], 404);
        }

        return response()->json(['data' => array_intersect_key($durum, array_flip(self::SONUC_ALANLARI))]);
    }

    /** "Şimdi Yedek Al" */
    public function store(Request $request): JsonResponse
    {
        /** @var array{aciklama?: string|null} $veri */
        $veri = $request->validate(['aciklama' => ['nullable', 'string', 'max:500']]);

        return $this->kabul($this->istekler->birak('yedek_al', [
            'kaynak' => YedekKaynagi::Elle->value,
            'aciklama' => $veri['aciklama'] ?? null,
        ], (int) $request->user()->id, null, IstekAnahtari::oku($request)));
    }

    /** 2. düzey doğrulama: sınama geri yüklemesi (README §4.4) */
    public function dogrula(Request $request, string $kimlik): JsonResponse
    {
        $yedek = $this->bul($kimlik);

        if (! $yedek->geriYuklenebilirMi()) {
            return $this->reddet('YEDEK_DOGRULANAMAZ', 'hata.yedek_dogrulanamaz');
        }

        // Doğrulama geri yükleme motorunu kullanır: yalnız şifreli kopyası
        // kalan yedek ekrandan doğrulanamaz (BY-027-R1)
        $engel = GeriYuklemeKaynaklari::engel($yedek->loadMissing('kopyalar'));

        if ($engel !== null) {
            return $this->reddet($engel, 'hata.yedek_dogrulama_kaynagi_yok');
        }

        return $this->kabul($this->istekler->birak('dogrula', ['yedek_kimligi' => $yedek->kimlik], (int) $request->user()->id, $yedek->id, IstekAnahtari::oku($request)));
    }

    public function sabitle(Request $request, string $kimlik): JsonResponse
    {
        return $this->yedekIstegi($request, $kimlik, 'sabitle');
    }

    public function sabitCoz(Request $request, string $kimlik): JsonResponse
    {
        return $this->yedekIstegi($request, $kimlik, 'sabit_coz');
    }

    public function aciklama(Request $request, string $kimlik): JsonResponse
    {
        /** @var array{aciklama?: string|null} $veri */
        $veri = $request->validate(['aciklama' => ['present', 'nullable', 'string', 'max:500']]);
        $yedek = $this->bul($kimlik);

        if ($yedek->durum === YedekDurumu::Silindi) {
            return $this->reddet('YEDEK_SILINMIS', 'hata.yedek_silinmis');
        }

        return $this->kabul($this->istekler->birak('aciklama', [
            'yedek_kimligi' => $yedek->kimlik,
            'aciklama' => $veri['aciklama'] ?? null,
        ], (int) $request->user()->id, $yedek->id, IstekAnahtari::oku($request)));
    }

    /**
     * Sil: yerel dosya ve bütün kopyalar (README §4.3). Sabitlenmiş ya da
     * kullanımdaki (kopyalanan, geri yüklenen) yedek silinmez.
     */
    public function destroy(Request $request, KullanimDenetimi $kullanim, string $kimlik): JsonResponse
    {
        $yedek = $this->bul($kimlik);

        if ($yedek->sabit) {
            return $this->reddet('YEDEK_SABIT', 'hata.yedek_sabit');
        }

        if ($kullanim->kullanimdaMi($yedek)) {
            return $this->reddet('YEDEK_KULLANIMDA', 'hata.yedek_kullanimda');
        }

        return $this->yedekIstegi($request, $kimlik, 'sil');
    }

    /** "Klasörü Tara": dizini manifestlerden yeniden kurar (BY-011) */
    public function tara(Request $request): JsonResponse
    {
        return $this->kabul($this->istekler->birak('tara', [], (int) $request->user()->id, null, IstekAnahtari::oku($request)));
    }

    private function yedekIstegi(Request $request, string $kimlik, string $tur): JsonResponse
    {
        $yedek = $this->bul($kimlik);

        if ($yedek->durum === YedekDurumu::Silindi) {
            return $this->reddet('YEDEK_SILINMIS', 'hata.yedek_silinmis');
        }

        return $this->kabul($this->istekler->birak($tur, ['yedek_kimligi' => $yedek->kimlik], (int) $request->user()->id, $yedek->id, IstekAnahtari::oku($request)));
    }

    private function bul(string $kimlik): Yedek
    {
        return Yedek::query()->where('kimlik', $kimlik)->firstOrFail();
    }

    private function kabul(string $istekKimligi): JsonResponse
    {
        return response()->json(['data' => ['istek_kimligi' => $istekKimligi, 'durum' => 'kuyrukta']], 202);
    }

    private function reddet(string $kod, string $mesaj): JsonResponse
    {
        return response()->json(['kod' => $kod, 'mesaj' => __($mesaj)], 409);
    }
}
