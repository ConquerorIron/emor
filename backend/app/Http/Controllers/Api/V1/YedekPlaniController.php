<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Yedek;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\PlanTakvimi;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SiklikTuru;
use App\Services\Yedekleme\SilmeKarari;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekIstekServisi;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Ayarlar → Yedek Planları (BY-017; README §4.2, §4.3, §4.10 (2)).
 *
 * - Plan en az bir hedefle kaydedilir; her hedefin saklama kuralı ("son N" ve
 *   / veya "N günden eski") boş olamaz.
 * - Kopyası olan bir hedef plandan çıkarılamaz (F6): veritabanı da reddeder
 *   (bileşik anahtar); uç 409 ve bağlı kopya sayısıyla döner.
 * - Önizleme, değişikliği bir işlem içinde UYGULAYIP zamanlayıcının ve
 *   saklamanın kendi hesabını çalıştırır, sonra işlemi geri alır: kaydedilince
 *   aynı sonuç çıkar.
 */
final class YedekPlaniController extends Controller
{
    private const SAAT = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/D';

    public function __construct(
        private readonly PlanTakvimi $takvim,
        private readonly YedekDenetimi $denetim,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->diziler($this->planSorgusu()->orderBy('ad')->get())]);
    }

    public function store(Request $request): JsonResponse
    {
        $veri = $this->dogrula($request, null);
        $plan = DB::transaction(fn (): YedekPlani => $this->uygula(new YedekPlani, $veri));
        $this->denetle('plan_ekle', $request, $plan);

        return response()->json(['data' => $this->diziler($this->planSorgusu()->whereKey($plan->id)->get())[0]], 201);
    }

    public function update(Request $request, YedekPlani $plan): JsonResponse
    {
        $veri = $this->dogrula($request, $plan);
        DB::transaction(fn (): YedekPlani => $this->uygula($plan, $veri));
        $this->denetle('plan_guncelle', $request, $plan);

        return response()->json(['data' => $this->diziler($this->planSorgusu()->whereKey($plan->id)->get())[0]]);
    }

    /** Kopyası olan plan silinmez; pasife alınır (geçmişi durur) */
    public function destroy(Request $request, YedekPlani $plan): JsonResponse
    {
        $kopya = $plan->kopyalar()->count();
        if ($kopya > 0) {
            return response()->json(['kod' => 'PLAN_KOPYALI', 'mesaj' => __('hata.yedek_plani_kopyali', ['adet' => $kopya]), 'kopya_sayisi' => $kopya], 409);
        }

        $plan->delete();
        $this->denetle('plan_sil', $request, $plan);

        return response()->json(null, 204);
    }

    /**
     * Kaydetmeden önce: sıradaki 5 çalışma, ay sonu uyarısı, aynı dakikaya
     * düşen diğer planlar ve bu kurallarla silinecek kopyalar.
     */
    public function onizleme(Request $request, SaklamaPolitikasi $saklama): JsonResponse
    {
        $kayitli = $request->filled('id') ? YedekPlani::query()->findOrFail($request->integer('id')) : null;
        $veri = $this->dogrula($request, $kayitli);
        $simdi = CarbonImmutable::now();

        // Önizleme hiçbir şey kaydetmez: değişiklik uygulanır, hesaplanır, geri alınır
        DB::beginTransaction();
        try {
            $plan = $this->uygula($kayitli ?? new YedekPlani, $veri);
            $siradakiler = $this->takvim->siradakiler($plan, 5, $simdi);
            $planinKopyalari = array_flip(array_map('intval', $plan->kopyalar()->pluck('yedek_kopyalari.id')->all()));
            $silinecek = array_values(array_filter(
                $saklama->hesapla($simdi)['silinecek'],
                fn (SilmeKarari $k): bool => $k->planId === $plan->id || ($k->kopyaId !== null && isset($planinKopyalari[$k->kopyaId])),
            ));
            $yedekler = Yedek::query()->whereIn('id', array_map(fn (SilmeKarari $k): int => $k->yedekId, $silinecek))->get()->keyBy('id');

            $sonuc = [
                'siradakiler' => array_map(fn (CarbonImmutable $a): string => $a->toIso8601String(), $siradakiler),
                'ay_sonu_uyarisi' => $this->takvim->ayinSonunaKayabilir($plan),
                'cakisan_planlar' => $this->cakisanlar($plan, $siradakiler, $simdi),
                'silinecekler' => array_map(fn (SilmeKarari $k): array => [
                    ...$k->diziye(),
                    'dosya_adi' => $yedekler[$k->yedekId]->dosya_adi ?? null,
                    'alinma_zamani' => $yedekler[$k->yedekId]?->alinma_zamani?->toIso8601String(),
                ], $silinecek),
            ];
        } finally {
            DB::rollBack();
        }

        return response()->json(['data' => $sonuc]);
    }

    /** "Şimdi çalıştır": isteği çalıştırıcıya bırakır */
    public function calistir(Request $request, YedekIstekServisi $istekler, YedekPlani $plan): JsonResponse
    {
        $kimlik = $istekler->birak('plan_calistir', ['plan_id' => $plan->id], (int) $request->user()->id);

        return response()->json(['data' => ['istek_kimligi' => $kimlik, 'durum' => 'kuyrukta']], 202);
    }

    /**
     * @return array{ad: string, aktif: bool, siklik_turu: SiklikTuru, saat: string, siklik: array<string, mixed>, hedefler: array<int, array{sakla_son_adet: int|null, sakla_gun: int|null}>}
     */
    private function dogrula(Request $request, ?YedekPlani $kayitli): array
    {
        $veri = $request->validate([
            'ad' => ['required', 'string', 'max:100', Rule::unique('yedek_planlari', 'ad')->ignore($kayitli?->id)],
            'aktif' => ['nullable', 'boolean'],
            'siklik_turu' => ['required', Rule::enum(SiklikTuru::class)],
            'saat' => ['required', 'string', self::SAAT],
            'siklik' => ['present', 'array'],
            'siklik.aralik' => ['nullable', 'integer', 'min:1', 'max:366'],
            'siklik.saat_baslangic' => ['nullable', 'string', self::SAAT],
            'siklik.saat_bitis' => ['nullable', 'string', self::SAAT],
            'siklik.gunler' => ['nullable', 'array', 'max:7'],
            'siklik.gunler.*' => ['integer', 'between:1,7', 'distinct'],
            'siklik.baslangic_tarihi' => ['nullable', 'date_format:Y-m-d'],
            'siklik.ayin_gunu' => ['nullable', 'integer', 'between:1,31'],
            'hedefler' => ['required', 'array', 'min:1', 'max:20'],
            'hedefler.*.hedef_id' => ['required', 'integer', 'distinct', Rule::exists('yedek_hedefleri', 'id')],
            'hedefler.*.sakla_son_adet' => ['nullable', 'integer', 'between:1,1000', 'required_without:hedefler.*.sakla_gun'],
            'hedefler.*.sakla_gun' => ['nullable', 'integer', 'between:1,3650', 'required_without:hedefler.*.sakla_son_adet'],
        ]);

        $tur = SiklikTuru::from($veri['siklik_turu']);
        $siklik = $this->siklik($tur, $veri['siklik'] ?? []);
        $hedefler = [];
        foreach ($veri['hedefler'] as $h) {
            $hedefler[(int) $h['hedef_id']] = ['sakla_son_adet' => $h['sakla_son_adet'] ?? null, 'sakla_gun' => $h['sakla_gun'] ?? null];
        }

        $sonuc = ['ad' => trim($veri['ad']), 'aktif' => (bool) ($veri['aktif'] ?? true), 'siklik_turu' => $tur, 'saat' => $veri['saat'], 'siklik' => $siklik, 'hedefler' => $hedefler];

        // Tanım gerçekten çalışıyor mu (ör. saat penceresi aralıkla hiç kesişmiyor)
        try {
            $this->takvim->sonraki(new YedekPlani(['ad' => $sonuc['ad'], 'siklik_turu' => $tur, 'saat' => $sonuc['saat'], 'siklik' => $siklik]), CarbonImmutable::now());
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['siklik' => __('hata.yedek_plani_calismiyor')]);
        }

        if ($kayitli !== null) {
            $this->kopyaliHedefCikarilmasin($kayitli, array_keys($hedefler));
        }

        return $sonuc;
    }

    /**
     * Sıklık türüne göre yalnız anlamlı alanlar saklanır (fazlası atılır).
     *
     * @param  array<string, mixed>  $girdi
     * @return array<string, mixed>
     */
    private function siklik(SiklikTuru $tur, array $girdi): array
    {
        $gerekli = fn (string $alan) => ($girdi[$alan] ?? null) ?? throw ValidationException::withMessages(['siklik.'.$alan => __('validation.required', ['attribute' => $alan])]);

        return match ($tur) {
            SiklikTuru::Saatlik => array_filter([
                'aralik' => min(24, (int) $gerekli('aralik')),
                'saat_baslangic' => $girdi['saat_baslangic'] ?? null,
                'saat_bitis' => $girdi['saat_bitis'] ?? null,
                'gunler' => $girdi['gunler'] ?? null,
            ], fn ($d) => $d !== null && $d !== []),
            SiklikTuru::Gunluk => [],
            SiklikTuru::NGunluk => array_filter([
                'aralik' => (int) $gerekli('aralik'),
                'baslangic_tarihi' => $girdi['baslangic_tarihi'] ?? CarbonImmutable::now(PlanTakvimi::SAAT_DILIMI)->toDateString(),
            ]),
            SiklikTuru::Haftalik => ['gunler' => array_values(array_map('intval', $gerekli('gunler')))],
            SiklikTuru::Aylik => ['ayin_gunu' => (int) $gerekli('ayin_gunu')],
        };
    }

    /**
     * F6: bu plana bağlı kopyası olan hedef plandan çıkarılırsa saklama kuralı
     * kaybolur; reddedilir (veritabanı da reddeder — bileşik anahtar).
     *
     * @param  list<int>  $yeniHedefler
     */
    private function kopyaliHedefCikarilmasin(YedekPlani $plan, array $yeniHedefler): void
    {
        $bagli = DB::table('yedek_kopya_planlari')
            ->where('yedek_plani_id', $plan->id)
            ->whereNotIn('yedek_hedefi_id', $yeniHedefler === [] ? [0] : $yeniHedefler)
            ->join('yedek_hedefleri', 'yedek_hedefleri.id', '=', 'yedek_kopya_planlari.yedek_hedefi_id')
            ->groupBy('yedek_hedefleri.id', 'yedek_hedefleri.ad')
            ->selectRaw('yedek_hedefleri.id, yedek_hedefleri.ad, count(*) as adet')
            ->get();

        if ($bagli->isNotEmpty()) {
            throw new HttpResponseException(response()->json([
                'kod' => 'PLAN_HEDEFI_KOPYALI',
                'mesaj' => __('hata.yedek_plan_hedefi_kopyali', ['hedefler' => $bagli->map(fn ($s) => "{$s->ad} ({$s->adet})")->implode(', ')]),
                'hedefler' => $bagli->map(fn ($s): array => ['hedef_id' => (int) $s->id, 'ad' => $s->ad, 'kopya_sayisi' => (int) $s->adet])->values()->all(),
            ], 409));
        }
    }

    /**
     * @param  array{ad: string, aktif: bool, siklik_turu: SiklikTuru, saat: string, siklik: array<string, mixed>, hedefler: array<int, array{sakla_son_adet: int|null, sakla_gun: int|null}>}  $veri
     */
    private function uygula(YedekPlani $plan, array $veri): YedekPlani
    {
        $zamanDegisti = ! $plan->exists
            || $plan->siklik_turu !== $veri['siklik_turu'] || $plan->saat !== $veri['saat'] || $plan->siklik != $veri['siklik'];

        $plan->fill(['ad' => $veri['ad'], 'aktif' => $veri['aktif'], 'siklik_turu' => $veri['siklik_turu'], 'saat' => $veri['saat'], 'siklik' => $veri['siklik']]);
        if ($zamanDegisti) {
            $plan->sonraki_calisma = $this->takvim->sonraki($plan, CarbonImmutable::now());
        }
        $plan->save();
        $plan->hedefler()->sync($veri['hedefler']);

        return $plan;
    }

    /**
     * Diğer etkin planların aynı ana düşen çalışmaları (tek yedek alınır, uyarı)
     *
     * @param  list<CarbonImmutable>  $siradakiler
     * @return list<array{id: int, ad: string, an: string}>
     */
    private function cakisanlar(YedekPlani $plan, array $siradakiler, CarbonImmutable $simdi): array
    {
        $anlar = array_flip(array_map(fn (CarbonImmutable $a): string => $a->format('Y-m-d H:i'), $siradakiler));
        $cakisan = [];

        foreach (YedekPlani::query()->where('aktif', true)->whereKeyNot($plan->id)->get() as $diger) {
            try {
                foreach ($this->takvim->siradakiler($diger, 5, $simdi) as $an) {
                    if (isset($anlar[$an->format('Y-m-d H:i')])) {
                        $cakisan[] = ['id' => $diger->id, 'ad' => $diger->ad, 'an' => $an->toIso8601String()];
                        break;
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        return $cakisan;
    }

    /**
     * Planlar + son çalışmanın yedeği ve onun kopyaları (BY-017-R3: yedek
     * planla ilişkili — `son_yedek_id`, PlanliYedek yazar). Eager load: sorgu
     * sayısı plan sayısından bağımsız (BY-017-R2).
     *
     * @return Builder<YedekPlani>
     */
    private function planSorgusu(): Builder
    {
        return YedekPlani::query()->with(['hedefler', 'sonYedek.kopyalar.hedef']);
    }

    /**
     * Liste dönüşümü: bağ sayıları plan sayısından bağımsız sabit sayıda
     * sorguyla alınır (BY-017-R2).
     *
     * @param  Collection<int, YedekPlani>  $planlar
     * @return list<array<string, mixed>>
     */
    private function diziler(Collection $planlar): array
    {
        $bagSayilari = [];
        DB::table('yedek_kopya_planlari')->whereIn('yedek_plani_id', $planlar->modelKeys())
            ->groupBy('yedek_plani_id', 'yedek_hedefi_id')
            ->selectRaw('yedek_plani_id, yedek_hedefi_id, count(*) as adet')
            ->get()
            ->each(function (object $satir) use (&$bagSayilari): void {
                $bagSayilari[(int) $satir->yedek_plani_id][(int) $satir->yedek_hedefi_id] = (int) $satir->adet;
            });

        return $planlar->map(fn (YedekPlani $p): array => $this->diziye($p, $bagSayilari[$p->id] ?? []))->values()->all();
    }

    /**
     * Son çalışmanın sonucu (BY-017-R1, R3), o çalışmanın kendi kayıtlarından:
     * yedek alınamadıysa `yedek_basarisiz`; o çalışmada bu planın kopyalandığı
     * hedeflerden birinde kopya başarısızsa `hedef_basarisiz`; değilse
     * `basarili`. Planın bugünkü hedef listesine bakılmaz: hedef sonradan
     * çıkarılsa da eski çalışmanın sonucu değişmez. Bilinmiyorsa (hiç
     * çalışmadı, yedek kaydı yok) null.
     *
     * @return array{durum: 'basarili'|'yedek_basarisiz'|'hedef_basarisiz', hata_kodu: string|null, basarisiz_hedefler: list<array{hedef_id: int, ad: string, hata_kodu: string|null}>}|null
     */
    private function sonSonuc(YedekPlani $plan): ?array
    {
        $yedek = $plan->sonYedek;

        if ($yedek === null || ! in_array($yedek->durum, [YedekDurumu::Tamam, YedekDurumu::Silindi, YedekDurumu::Basarisiz], true)) {
            return null;
        }

        if ($yedek->durum === YedekDurumu::Basarisiz) {
            return ['durum' => 'yedek_basarisiz', 'hata_kodu' => $yedek->hata_kodu, 'basarisiz_hedefler' => []];
        }

        $calismaHedefleri = array_flip($plan->son_hedef_idleri ?? []);
        $basarisiz = $yedek->kopyalar
            ->filter(fn (YedekKopyasi $k): bool => $k->durum === KopyaDurumu::Basarisiz && isset($calismaHedefleri[$k->yedek_hedefi_id]))
            ->sortBy('yedek_hedefi_id')
            ->map(fn (YedekKopyasi $k): array => ['hedef_id' => $k->yedek_hedefi_id, 'ad' => $k->hedef->ad, 'hata_kodu' => $k->hata_kodu])
            ->values()
            ->all();

        return ['durum' => $basarisiz === [] ? 'basarili' : 'hedef_basarisiz', 'hata_kodu' => null, 'basarisiz_hedefler' => $basarisiz];
    }

    /**
     * @param  array<int, int>  $bagSayilari  hedef id => bu plana bağlı kopya sayısı
     * @return array<string, mixed>
     */
    private function diziye(YedekPlani $plan, array $bagSayilari): array
    {
        return [
            'id' => $plan->id,
            'ad' => $plan->ad,
            'aktif' => $plan->aktif,
            'siklik_turu' => $plan->siklik_turu->value,
            'saat' => $plan->saat,
            'siklik' => (object) $plan->siklik,
            'son_calisma' => $plan->son_calisma?->toIso8601String(),
            'son_sonuc' => $this->sonSonuc($plan),
            'sonraki_calisma' => $plan->aktif ? $plan->sonraki_calisma?->toIso8601String() : null,
            'ay_sonu_uyarisi' => $this->takvim->ayinSonunaKayabilir($plan),
            'hedefler' => $plan->hedefler->map(fn ($h): array => [
                'hedef_id' => $h->id,
                'ad' => $h->ad,
                'tur' => $h->tur->value,
                'sakla_son_adet' => $h->pivot->sakla_son_adet,
                'sakla_gun' => $h->pivot->sakla_gun,
                'kopya_sayisi' => (int) ($bagSayilari[$h->id] ?? 0),
            ])->values()->all(),
        ];
    }

    private function denetle(string $islem, Request $request, YedekPlani $plan): void
    {
        $this->denetim->kaydet($islem, IslemSonucu::Basarili, null, (int) $request->user()->id, null, ['plan' => $plan->ad, 'plan_id' => $plan->id]);
    }
}
