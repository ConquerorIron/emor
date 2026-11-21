<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\YedekHedefiResource;
use App\Models\YedekHedefi;
use App\Services\Yedekleme\HedefKonumu;
use App\Services\Yedekleme\Hedefler\GoogleDriveBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefAlani;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\Hedefler\HedefTuruTanimi;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\YedekDenetimi;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Ayarlar → Yedek Hedefleri (BY-016; README §4.6, §4.10 (4)). Form alanları
 * bağlayıcının tanımından gelir (`turler`): yeni tür ekranda kod istemez.
 *
 * - Sırlar (`gizli` alanlar) yalnız yazılır; düzenlemede boş bırakılırsa
 *   değişmez. Sır olmayan bir bağlantı alanı (sunucu, kullanıcı…) değişirken
 *   sır boş bırakılırsa kayıt reddedilir: kayıtlı parola yeni sunucuya
 *   gönderilmez (SQL bağlantısındaki kural; Dikkat_Edilecekler K8).
 * - Bağlayıcının kendi yazdığı alanlar (SFTP sunucu anahtarı, Google klasör
 *   kimliği) düzenlemede korunur; formdan gelmez.
 * - Kopyası duran hedefin konumu (sunucu, klasör, kova…) değiştirilemez:
 *   409 `HEDEF_KONUMU_DEGISMEZ`, yeni konum için yeni hedef açılır
 *   (`HedefKonumu`, BY-016-R1). Sır yenileme serbesttir.
 * - "Sına" web isteğinde çalışır; bütün adımlar tek toplam süre bütçesini
 *   paylaşır (`HedefSinayici`, D9).
 */
final class YedekHedefiController extends Controller
{
    /** Google bağlantı akışının durum değeri bu süre geçerlidir (sn) */
    private const GOOGLE_DURUM_SURESI = 600;

    /**
     * Sırrın gideceği yeri belirleyen alanlar: biri değişirken kayıtlı sır
     * boş bırakılırsa kayıt reddedilir (klasör, önek gibi alanlar sır istemez).
     */
    private const ADRES_ALANLARI = ['sunucu', 'port', 'kullanici_adi', 'uc_nokta', 'bolge', 'kova', 'erisim_anahtari'];

    public function __construct(
        private readonly HedefKatalogu $katalog,
        private readonly YedekDenetimi $denetim,
        private readonly HedefKonumu $konum,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return YedekHedefiResource::collection(
            HedefKonumu::kopyaVarMiEkle(YedekHedefi::query()->with('sonKopya')->withCount('planlar'))->orderBy('ad')->get()
        );
    }

    /** Kurulu bağlayıcıların form tanımları */
    public function turler(): JsonResponse
    {
        return response()->json(['data' => array_map(fn (HedefTuruTanimi $t): array => $t->diziye(), $this->katalog->tanimlar())]);
    }

    public function store(Request $request): JsonResponse
    {
        [$tanim, $acik, $gizli] = $this->dogrula($request, null);

        $hedef = YedekHedefi::query()->create([
            'ad' => $request->string('ad')->trim()->value(),
            'tur' => $tanim->tur,
            'aktif' => $request->boolean('aktif', true),
            'baglanti' => $acik,
            'gizli_bilgiler' => $gizli === [] ? null : $gizli,
        ]);
        $this->denetle('hedef_ekle', $request, $hedef);

        return (new YedekHedefiResource($hedef->loadCount('planlar')))->response()->setStatusCode(201);
    }

    public function update(Request $request, YedekHedefi $hedef): YedekHedefiResource
    {
        [, $acik, $gizli] = $this->dogrula($request, $hedef, konumKilidi: true);

        $hedef->fill([
            'ad' => $request->string('ad')->trim()->value(),
            'aktif' => $request->boolean('aktif', true),
            'baglanti' => $acik,
            'gizli_bilgiler' => $gizli === [] ? null : $gizli,
        ])->save();
        $this->denetle('hedef_guncelle', $request, $hedef, ['sir_degisti' => $request->collect('alanlar')->only($this->gizliAdlar($hedef->tur))->filter(fn ($d) => $d !== null && $d !== '')->keys()->all()]);

        return new YedekHedefiResource($hedef->load('sonKopya')->loadCount('planlar'));
    }

    /** Planda kullanılan ya da kopyası olan hedef silinmez (409) */
    public function destroy(Request $request, YedekHedefi $hedef): JsonResponse
    {
        $planSayisi = $hedef->planlar()->count();
        $kopyaSayisi = $hedef->kopyalar()->count();

        if ($planSayisi > 0 || $kopyaSayisi > 0) {
            return response()->json([
                'kod' => 'HEDEF_KULLANILIYOR',
                'mesaj' => __('hata.yedek_hedefi_kullaniliyor', ['plan' => $planSayisi, 'kopya' => $kopyaSayisi]),
                'plan_sayisi' => $planSayisi,
                'kopya_sayisi' => $kopyaSayisi,
            ], 409);
        }

        $hedef->delete();
        $this->denetle('hedef_sil', $request, $hedef);

        return response()->json(null, 204);
    }

    /**
     * "Bağlantıyı Sına": bağlan → yaz → oku → doğrula → sil, adım adım; tek
     * toplam süre bütçesiyle (D9). S3'te silinme koruması da bildirilir.
     * `id` verilip form değiştirilmediyse kayıtlı hedefin kendisi sınanır (SFTP
     * sunucu anahtarı ilk bağlantıda ona yazılır); değiştirildiyse kaydedilmemiş
     * kopyası sınanır, hiçbir şey kaydedilmez.
     */
    public function sina(Request $request, HedefSinayici $sinayici): JsonResponse
    {
        $kayitli = $request->filled('id') ? YedekHedefi::query()->findOrFail($request->integer('id')) : null;
        [$tanim, $acik, $gizli] = $this->dogrula($request, $kayitli, adGerekli: false);

        $hedef = $kayitli !== null && $kayitli->baglanti == $acik && ($kayitli->gizli_bilgiler ?? []) == $gizli
            ? $kayitli
            : new YedekHedefi(['ad' => $kayitli->ad ?? 'sinama', 'tur' => $tanim->tur, 'aktif' => true, 'baglanti' => $acik, 'gizli_bilgiler' => $gizli]);

        try {
            $sonuc = $sinayici->sonuc($this->katalog->olustur($hedef));
        } catch (HedefHatasi $hata) {
            $sonuc = [
                'basarili' => false,
                'adimlar' => [['adim' => 'baglan', 'basarili' => false, 'kod' => $hata->kod, 'mesaj' => Str::limit($hata->getMessage(), 300)]],
                'kalan_alan' => null,
                'silinme_korumasi' => null,
            ];
        }

        $adimlar = $sonuc['adimlar'];
        $hataKodu = $sonuc['basarili'] ? null : (array_values(array_filter(array_column($adimlar, 'kod')))[0] ?? null);
        $this->denetle('hedef_sina', $request, $kayitli, ['tur' => $tanim->tur->value, 'basarili' => $sonuc['basarili']], $hataKodu);

        return response()->json(['data' => [
            ...$sonuc,
            'kaydedildi' => $hedef === $kayitli,
            'sunucu_parmak_izi' => $hedef->baglanti['sunucu_parmak_izi'] ?? null,
        ]]);
    }

    /** SFTP: kayıtlı sunucu anahtarını unut; sonraki "Sına" yenisini kaydeder */
    public function sunucuAnahtariniSifirla(Request $request, YedekHedefi $hedef): YedekHedefiResource
    {
        $hedef->baglanti = array_diff_key($hedef->baglanti ?? [], array_flip(['sunucu_anahtari', 'sunucu_parmak_izi', 'sunucu_anahtari_icin']));
        $hedef->save();
        $this->denetle('hedef_sunucu_anahtari_sifirla', $request, $hedef);

        return new YedekHedefiResource($hedef->loadCount('planlar'));
    }

    /**
     * Google ile bağlan (BY-021): yetki adresi. Durum değeri (CSRF) oturuma
     * kullanıcı + hedefle birlikte yazılır; dönüşte birebir aranır.
     */
    public function googleBaglan(Request $request, YedekHedefi $hedef): JsonResponse
    {
        if ($hedef->tur !== HedefTuru::GoogleDrive) {
            return response()->json(['kod' => 'HEDEF_TURU_UYGUN_DEGIL', 'mesaj' => __('hata.yedek_hedefi_google_degil')], 409);
        }

        $durum = Str::random(48);
        Cache::put(self::googleDurumAnahtari($durum), ['hedef_id' => $hedef->id, 'kullanici_id' => $request->user()->id], self::GOOGLE_DURUM_SURESI);

        try {
            return response()->json(['data' => ['adres' => GoogleDriveBaglayicisi::yetkiAdresi($durum, self::googleDonusAdresi())]]);
        } catch (HedefHatasi $hata) {
            return response()->json(['kod' => $hata->kod, 'mesaj' => __('hata.yedek_google_yapilandirilmadi')], 409);
        }
    }

    /**
     * Google dönüşü: ekran (`/ayarlar/yedek-hedefleri/google-donus`) kod ve
     * durumu buraya iletir. Durum oturumdakiyle aynı değilse, süresi geçtiyse
     * ya da başka kullanıcınınsa reddedilir; değer bir kez kullanılır.
     *
     * Denetim ve tüketme durum değerinin kilidi altında tek adımdır
     * (`Cache::pull` okuma + silme ayrı işlemdir; iki dönüş aynı değeri
     * okuyabilirdi — BY-021-R1). Kullanıcı ya da hedef eşleşmezse değer
     * TÜKETİLMEZ: yanlış oturumdaki deneme doğru akışı bozamaz.
     */
    public function googleDonus(Request $request): JsonResponse
    {
        /** @var array{kod: string, durum: string} $veri */
        $veri = $request->validate([
            'kod' => ['required', 'string', 'max:2048'],
            'durum' => ['required', 'string', 'max:128'],
        ]);
        $gecersiz = fn (): JsonResponse => response()->json(['kod' => 'GOOGLE_DURUM_GECERSIZ', 'mesaj' => __('hata.yedek_google_durum_gecersiz')], 422);
        $anahtar = self::googleDurumAnahtari($veri['durum']);
        $kilit = Cache::lock(self::googleDurumKilidi($veri['durum']), 10);

        // Aynı değerle süren başka bir dönüş var: o tamamlar, bu reddedilir
        if (! $kilit->get()) {
            return $gecersiz();
        }

        try {
            $beklenen = Cache::get($anahtar);
            $hedef = is_array($beklenen) && ($beklenen['kullanici_id'] ?? null) === $request->user()->id
                ? YedekHedefi::query()->whereKey((int) ($beklenen['hedef_id'] ?? 0))->where('tur', HedefTuru::GoogleDrive->value)->first()
                : null;

            if ($hedef === null) {
                return $gecersiz();
            }

            Cache::forget($anahtar);
        } finally {
            $kilit->release();
        }

        try {
            $sonuc = GoogleDriveBaglayicisi::kodDegistir($veri['kod'], self::googleDonusAdresi());
        } catch (HedefHatasi $hata) {
            $this->denetle('hedef_google_baglan', $request, $hedef, [], $hata->kod);

            return response()->json(['kod' => $hata->kod, 'mesaj' => __('hata.yedek_google_baglanamadi')], 422);
        }

        // Başka bir Google hesabı kopyaların durduğu yeri değiştirir (dosya kimlikleri o hesaptadır)
        $oncekiHesap = $hedef->baglanti['eposta'] ?? null;
        if ($oncekiHesap !== null && $sonuc['eposta'] !== null && $oncekiHesap !== $sonuc['eposta'] && $this->konum->kilitliAlanlar($hedef) !== []) {
            $this->denetle('hedef_google_baglan', $request, $hedef, [], 'HEDEF_KONUMU_DEGISMEZ');

            return $this->konumDegismez(['eposta']);
        }

        DB::transaction(function () use ($hedef, $sonuc): void {
            $hedef->refresh();
            $baglanti = $hedef->baglanti ?? [];
            unset($baglanti['durum'], $baglanti['klasor_kimligi']);
            $baglanti['eposta'] = $sonuc['eposta'];
            $hedef->baglanti = $baglanti;
            $hedef->gizli_bilgiler = [...($hedef->gizli_bilgiler ?? []), 'yenileme_anahtari' => $sonuc['yenileme_anahtari']];
            $hedef->save();
        });
        $this->denetle('hedef_google_baglan', $request, $hedef);

        return response()->json(['data' => new YedekHedefiResource($hedef->loadCount('planlar'))]);
    }

    /** "Bağlantıyı kes": yenileme anahtarı silinir; kopyalar "yeniden bağlanın" ile durur */
    public function googleKes(Request $request, YedekHedefi $hedef): YedekHedefiResource
    {
        $gizli = $hedef->gizli_bilgiler ?? [];
        unset($gizli['yenileme_anahtari']);
        $hedef->gizli_bilgiler = $gizli === [] ? null : $gizli;
        $hedef->baglanti = [...($hedef->baglanti ?? []), 'durum' => GoogleDriveBaglayicisi::DURUM_YENIDEN_BAGLAN];
        $hedef->save();
        $this->denetle('hedef_google_kes', $request, $hedef);

        return new YedekHedefiResource($hedef->loadCount('planlar'));
    }

    private static function googleDurumAnahtari(string $durum): string
    {
        return 'yedek-google-durum:'.hash('sha256', $durum);
    }

    /** Durum değerinin denetimini ve tüketilmesini tek adım yapan kilit (testte de tutulur) */
    public static function googleDurumKilidi(string $durum): string
    {
        return self::googleDurumAnahtari($durum).':kilit';
    }

    /**
     * @param  list<string>  $alanlar
     */
    private function konumDegismez(array $alanlar): JsonResponse
    {
        return response()->json([
            'kod' => 'HEDEF_KONUMU_DEGISMEZ',
            'mesaj' => __('hata.yedek_hedefi_konumu_degismez', ['alanlar' => implode(', ', $alanlar)]),
            'alanlar' => $alanlar,
        ], 409);
    }

    public static function googleDonusAdresi(): string
    {
        return rtrim((string) config('app.url'), '/').'/ayarlar/yedek-hedefleri/google-donus';
    }

    /**
     * İstek gövdesi: `ad`, `aktif`, `tur` (yalnız eklemede), `alanlar` {ad: değer}.
     *
     * @param  bool  $konumKilidi  Kayıtta (sınamada değil) kopyalı hedefin konumu değişmez (409)
     * @return array{0: HedefTuruTanimi, 1: array<string, mixed>, 2: array<string, string>} [tanım, açık alanlar, sırlar]
     */
    private function dogrula(Request $request, ?YedekHedefi $kayitli, bool $adGerekli = true, bool $konumKilidi = false): array
    {
        $turler = array_map(fn (HedefTuruTanimi $t): string => $t->tur->value, $this->katalog->tanimlar());
        $temel = $request->validate([
            'ad' => [$adGerekli ? 'required' : 'nullable', 'string', 'max:100', Rule::unique('yedek_hedefleri', 'ad')->ignore($kayitli?->id)],
            'aktif' => ['nullable', 'boolean'],
            'tur' => [$kayitli === null ? 'required' : 'nullable', Rule::in($turler)],
            'alanlar' => ['present', 'array'],
        ]);

        $tur = $kayitli?->tur ?? HedefTuru::from($temel['tur']);
        if ($kayitli !== null && isset($temel['tur']) && $temel['tur'] !== $tur->value) {
            throw ValidationException::withMessages(['tur' => __('hata.yedek_hedefi_tur_degismez')]);
        }

        $tanim = $this->katalog->tanim($tur);
        $kurallar = [];
        foreach ($tanim->alanlar as $alan) {
            $sirKayitli = $alan->gizli && ($kayitli?->gizli_bilgiler[$alan->ad] ?? '') !== '';
            $kurallar[$alan->ad] = [($alan->zorunlu && ! $sirKayitli) ? 'required' : 'nullable', ...$alan->kurallar];
        }

        $girdi = (array) $request->input('alanlar', []);
        $bilinmeyen = array_diff(array_keys($girdi), array_map(fn (HedefAlani $a): string => $a->ad, $tanim->alanlar));
        if ($bilinmeyen !== []) {
            throw ValidationException::withMessages(['alanlar' => __('hata.yedek_hedefi_bilinmeyen_alan', ['alanlar' => implode(', ', $bilinmeyen)])]);
        }

        $gecerli = Validator::make($girdi, $kurallar)->validate();

        // Bağlayıcının yazdığı alanlar korunur; formun açık alanları üstüne yazılır
        $formAdlari = array_map(fn (HedefAlani $a): string => $a->ad, $tanim->alanlar);
        $acik = array_diff_key($kayitli->baglanti ?? [], array_flip($formAdlari));
        $gizli = $kayitli->gizli_bilgiler ?? [];
        $acikDegisti = false;

        foreach ($tanim->alanlar as $alan) {
            $deger = $gecerli[$alan->ad] ?? null;
            if ($alan->gizli) {
                if ($deger !== null && $deger !== '') {
                    $gizli[$alan->ad] = (string) $deger;
                }

                continue;
            }
            $deger ??= $alan->varsayilan;
            if ($kayitli !== null && in_array($alan->ad, self::ADRES_ALANLARI, true) && ($kayitli->baglanti[$alan->ad] ?? $alan->varsayilan) != $deger) {
                $acikDegisti = true;
            }
            $acik[$alan->ad] = $deger;
        }

        // Konum kilidi sır kuralından önce: kilitli alanı değiştiren önce parolayı yeniden girmeye yönlenmesin
        if ($konumKilidi && $kayitli !== null) {
            $degisen = $this->konum->degisenKilitliAlanlar($kayitli, $acik);
            if ($degisen !== []) {
                throw new HttpResponseException($this->konumDegismez($degisen));
            }
        }

        // Bağlantı hedefi değişirken kayıtlı sır yeni yere gönderilmez (K8)
        if ($kayitli !== null && $acikDegisti) {
            foreach ($tanim->alanlar as $alan) {
                $bos = ($gecerli[$alan->ad] ?? null) === null || $gecerli[$alan->ad] === '';
                if ($alan->gizli && $bos && ($kayitli->gizli_bilgiler[$alan->ad] ?? '') !== '') {
                    throw ValidationException::withMessages(['alanlar.'.$alan->ad => __('hata.yedek_hedefi_sir_yeniden')]);
                }
            }
        }

        return [$tanim, $acik, $gizli];
    }

    /**
     * @return list<string>
     */
    private function gizliAdlar(HedefTuru $tur): array
    {
        return array_values(array_map(fn (HedefAlani $a): string => $a->ad, array_filter($this->katalog->tanim($tur)->alanlar, fn (HedefAlani $a): bool => $a->gizli)));
    }

    /**
     * @param  array<string, mixed>  $ayrinti
     */
    private function denetle(string $islem, Request $request, ?YedekHedefi $hedef, array $ayrinti = [], ?string $hataKodu = null): void
    {
        $this->denetim->kaydet($islem, $hataKodu === null ? IslemSonucu::Basarili : IslemSonucu::Basarisiz, null, (int) $request->user()->id, null, [
            'hedef' => $hedef?->ad, 'hedef_id' => $hedef?->id, ...$ayrinti,
        ], $hataKodu);
    }
}
