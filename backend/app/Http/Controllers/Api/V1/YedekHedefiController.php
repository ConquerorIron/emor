<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\YedekHedefiResource;
use App\Models\YedekHedefi;
use App\Services\Yedekleme\HedefKonumu;
use App\Services\Yedekleme\Hedefler\DosyaSistemiHedefi;
use App\Services\Yedekleme\Hedefler\GoogleDriveBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefAlani;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\Hedefler\HedefTuruTanimi;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KanonikJson;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Closure;
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
 *   değişmez. Zorunlu olmayan kayıtlı sır `silinecek_sirlar` ile silinir
 *   (YF-49). Sır olmayan bir bağlantı alanı (sunucu, kullanıcı…) değişirken
 *   sır boş bırakılırsa kayıt reddedilir: kayıtlı parola yeni sunucuya
 *   gönderilmez (SQL bağlantısındaki kural; Dikkat_Edilecekler K8).
 * - Bağlayıcının kendi yazdığı alanlar (SFTP sunucu anahtarı, Google klasör
 *   kimliği) düzenlemede korunur; formdan gelmez.
 * - Kopyası duran hedefin konumu (sunucu, port, klasör, kova…) değiştirilemez:
 *   409 `HEDEF_KONUMU_DEGISMEZ`, yeni konum için yeni hedef açılır
 *   (`HedefKonumu`, BY-016-R1/R3; denetim ve kayıt hedef satırının kilidi
 *   altında). Sır yenileme serbesttir. Google bağlantısı hesabın kimliği
 *   (e-posta) okunmadan kaydedilmez: 422 `GOOGLE_HESAP_OKUNAMADI`, yeniden
 *   denenebilir (BY-021-R3). Kopyalı Google Drive hedefi ancak aynı hesap
 *   doğrulanınca yeniden bağlanır — kayıtlı e-postayla ya da (hesabı
 *   kayıtlı olmayan eski hedefte) yeni bağlantının kayıtlı klasörün ve en
 *   son kopyanın sahibi olmasıyla; doğrulanamazsa eski bağlantı aynen kalır:
 *   409 `GOOGLE_HESAP_DOGRULANAMADI` (BY-021-R2, R3, R4). "Kopyalı" burada
 *   dosyası durabilen kopyayı da kapsar (`konum_belirsiz`; BY-021-R5).
 * - Aynı fiziksel depoyu (yazımı farklı da olsa aynı klasör, klasörü boş
 *   ikinci Yerel hedef = ikinci ana klasör hedefi; uzakta aynı sunucu +
 *   kullanıcı + klasör ya da aynı S3 uç noktası + kova + önek) gösteren
 *   ikinci hedef kaydedilmez: 409 `HEDEF_AYNI_DEPO` (HedefKonumu::ayniDepoMu;
 *   BY-010-R7, R8).
 * - "Sına" web isteğinde çalışır; bütün adımlar tek toplam süre bütçesini
 *   paylaşır (`HedefSinayici`, D9). Servis kipinde Yerel / NAS hedefinde
 *   yalnız klasörün varlığı denetlenir (YF-21).
 * - Ekleme ve silmenin denetim kaydı değişiklikle aynı işlemdedir: günlük
 *   yazılamazsa değişiklik geri alınır (YF-33).
 */
final class YedekHedefiController extends Controller
{
    /** Google bağlantı akışının durum değeri bu süre geçerlidir (sn) */
    private const GOOGLE_DURUM_SURESI = 600;

    /** Aynı depo denetimi + kayıt kilidi (depoKilidiAltinda) */
    public const DEPO_KILIDI = 'yedek-hedefi-depo';

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

        // Denetim kaydı kayıtla aynı işlemde: günlük yazılamazsa hedef de eklenmez (YF-33, BY-014-R1)
        $hedef = $this->depoKilidiAltinda(fn (): YedekHedefi => DB::transaction(function () use ($request, $tanim, $acik, $gizli): YedekHedefi {
            $hedef = new YedekHedefi([
                'ad' => $request->string('ad')->trim()->value(),
                'tur' => $tanim->tur,
                'aktif' => $request->boolean('aktif', true),
                'baglanti' => $acik,
                'gizli_bilgiler' => $gizli === [] ? null : $gizli,
            ]);
            $this->ayniDepoyuReddet($hedef);
            $hedef->save();
            $this->denetle('hedef_ekle', $request, $hedef);

            return $hedef;
        }));

        return (new YedekHedefiResource($hedef->loadCount('planlar')))->response()->setStatusCode(201);
    }

    /**
     * Konum denetimi ile kayıt, hedef satırının kilidi altında tek işlemdir:
     * kopyalayıcı kopyayı aynı kilit altında "kopyalanıyor" yapar (BY-016-R3).
     * Denetim sırasında kopya başlayamaz; başlamışsa güncelleme onu görür.
     * Başka bir depoya taşınan hedef başka bir hedefin deposunu gösteremez
     * (409 `HEDEF_AYNI_DEPO`); konumu değişmeyen kayıt (ad, aktiflik, sır) ve
     * aynı depoda kalan konum değişikliği (eşdeğer klasör yazımı, S3 bölgesi /
     * yol stili — depo kimliğine girmeyen alanlar) eskiden kalmış çakışan
     * hedefte de serbesttir: yeni çakışma doğurmaz, hedef pasife alınabilsin
     * (BY-010-R7, R8).
     */
    public function update(Request $request, YedekHedefi $hedef): YedekHedefiResource
    {
        $hedef = $this->depoKilidiAltinda(fn (): YedekHedefi => DB::transaction(function () use ($request, $hedef): YedekHedefi {
            $hedef = YedekHedefi::query()->whereKey($hedef->getKey())->lockForUpdate()->firstOrFail();
            [, $acik, $gizli, $silinen] = $this->dogrula($request, $hedef, konumKilidi: true);
            $onceki = clone $hedef;
            $oncekiIz = $this->konum->iz($onceki);

            $hedef->fill([
                'ad' => $request->string('ad')->trim()->value(),
                'aktif' => $request->boolean('aktif', true),
                'baglanti' => $acik,
                'gizli_bilgiler' => $gizli === [] ? null : $gizli,
            ]);
            if ($this->konum->iz($hedef) !== $oncekiIz && ! $this->konum->ayniDepoMu($onceki, $hedef)) {
                $this->ayniDepoyuReddet($hedef);
            }
            $hedef->save();
            $this->denetle('hedef_guncelle', $request, $hedef, [
                'sir_degisti' => $request->collect('alanlar')->only($this->gizliAdlar($hedef->tur))->filter(fn ($d) => $d !== null && $d !== '')->keys()->all(),
                ...($silinen === [] ? [] : ['sir_silindi' => $silinen]),
            ]);

            return $hedef;
        }));

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

        // Denetim kaydı silmeyle aynı işlemde: günlük yazılamazsa hedef silinmez (YF-33, BY-014-R1)
        DB::transaction(function () use ($request, $hedef): void {
            $hedef->delete();
            $this->denetle('hedef_sil', $request, $hedef);
        });

        if ($hedef->tur === HedefTuru::GoogleDrive) {
            $this->googleAnahtariniIptalEt($request, $hedef, $hedef->gizli_bilgiler['yenileme_anahtari'] ?? null, $hedef->baglanti['eposta'] ?? null, 'sil');
        }

        return response()->json(null, 204);
    }

    /**
     * "Bağlantıyı Sına": bağlan → yaz → oku → doğrula → sil, adım adım; tek
     * toplam süre bütçesiyle (D9). S3'te silinme koruması da bildirilir.
     * `id` verilip form değiştirilmediyse kayıtlı hedefin kendisi sınanır (SFTP
     * sunucu anahtarı ilk bağlantıda ona yazılır); değiştirildiyse kaydedilmemiş
     * kopyası sınanır, hiçbir şey kaydedilmez. `silinecek_sirlar` kayıttaki gibi
     * doğrulanır; silinecek sır kayıtlı değerle doldurulmaz (YF-49).
     *
     * Servis kipinde dosya sistemi hedeflerinde (Yerel, NAS) web hesabı yalnız
     * klasörün varlığını / paylaşımın bağlı olduğunu denetler; yazma adımları o
     * klasöre yazan çalıştırıcının iznine bağlıdır, web'de çalıştırılmaz
     * (`HEDEF_SINAMA_CALISTIRICIDA`, sonuç `basarili: null` — YF-21).
     */
    public function sina(Request $request, HedefSinayici $sinayici, YedeklemeYapilandirmasi $yapilandirma): JsonResponse
    {
        $kayitli = $request->filled('id') ? YedekHedefi::query()->findOrFail($request->integer('id')) : null;
        [$tanim, $acik, $gizli] = $this->dogrula($request, $kayitli, adGerekli: false);

        // Kesin karşılaştırma: gevşek eşitlik "001" ile "1"i aynı sayar, formdaki değer yerine kayıtlı hedef sınanırdı (G1, BY-016-R3)
        $hedef = $kayitli !== null && KanonikJson::kodla($kayitli->baglanti) === KanonikJson::kodla($acik) && KanonikJson::kodla($kayitli->gizli_bilgiler ?? []) === KanonikJson::kodla($gizli)
            ? $kayitli
            : new YedekHedefi(['ad' => $kayitli->ad ?? 'sinama', 'tur' => $tanim->tur, 'aktif' => true, 'baglanti' => $acik, 'gizli_bilgiler' => $gizli]);

        try {
            $baglayici = $this->katalog->olustur($hedef);
            $sonuc = $baglayici instanceof DosyaSistemiHedefi && $yapilandirma->servisCalistiricisi()
                ? $sinayici->varlikSonucu($baglayici)
                : $sinayici->sonuc($baglayici);
        } catch (HedefHatasi $hata) {
            $sonuc = [
                'basarili' => false,
                'adimlar' => [['adim' => 'baglan', 'basarili' => false, 'kod' => $hata->kod, 'mesaj' => Str::limit($hata->getMessage(), 300)]],
                'kalan_alan' => null,
                'silinme_korumasi' => null,
            ];
        }

        $adimlar = $sonuc['adimlar'];
        // Yalnız başarısız sonuç hata koduyla yazılır; bilgi kodu (silme yetkisi yok) ve kısmi sınama (null) hata değildir
        $hataKodu = $sonuc['basarili'] === false ? (array_values(array_filter(array_column($adimlar, 'kod')))[0] ?? null) : null;
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
        // Satır kilidi altında taze kayıttan: araya giren güncellemenin değişikliği eski kopyayla ezilmesin (YF-55, G18)
        $hedef = DB::transaction(function () use ($hedef): YedekHedefi {
            $hedef = YedekHedefi::query()->whereKey($hedef->getKey())->lockForUpdate()->firstOrFail();
            $hedef->baglanti = array_diff_key($hedef->baglanti ?? [], array_flip(['sunucu_anahtari', 'sunucu_parmak_izi', 'sunucu_anahtari_icin']));
            $hedef->save();

            return $hedef;
        });
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

        // Hesabı kayıtlı olmayan eski hedefte yeni bağlantının kayıtlı klasörün
        // ve dosyası hedefte duran (ya da durabilecek) en son kopyanın sahibi
        // olduğu sorulur (BY-021-R3, R4, R5). Uzak kimlikli böyle kopya yoksa
        // (henüz aktarılıyor) klasörün sahipliği yeter.
        $kayitliKlasor = ($hedef->baglanti['eposta'] ?? null) === null ? ($hedef->baglanti['klasor_kimligi'] ?? null) : null;
        $kayitliKlasor = is_string($kayitliKlasor) ? $kayitliKlasor : null;
        $kayitliKopya = $kayitliKlasor === null ? null : self::sonFizikselKopya($hedef);

        try {
            $sonuc = GoogleDriveBaglayicisi::kodDegistir($veri['kod'], self::googleDonusAdresi(), $kayitliKlasor, $kayitliKopya);
        } catch (HedefHatasi $hata) {
            $this->denetle('hedef_google_baglan', $request, $hedef, [], $hata->kod);

            // Hesap kimliği okunamadı: hiçbir şey kaydedilmedi, yeniden denenebilir
            $mesaj = $hata->kod === GoogleDriveBaglayicisi::HESAP_OKUNAMADI ? 'hata.yedek_google_hesap_okunamadi' : 'hata.yedek_google_baglanamadi';

            return response()->json(['kod' => $hata->kod, 'mesaj' => __($mesaj)], 422);
        }

        /** @var array{red: string|null, eski_anahtar: string|null, eski_hesap: string|null} $kayit */
        $kayit = DB::transaction(function () use ($hedef, $sonuc, $kayitliKopya): array {
            $hedef = YedekHedefi::query()->whereKey($hedef->getKey())->lockForUpdate()->firstOrFail();
            $oncekiHesap = $hedef->baglanti['eposta'] ?? null;
            $eskiAnahtar = $hedef->gizli_bilgiler['yenileme_anahtari'] ?? null;
            // Kayıtlı hesap bilinmiyorsa: yeni bağlantı kayıtlı klasörün ve kayıtlı kopyanın sahibiyse
            // aynı hesaptır; yalnız erişim yetmez (paylaşılmış klasör — BY-021-R4). Kanıt kilit altında
            // yeniden eşlenir: klasör ve en son fiziksel kopya, sahipliği sorulanlarla aynı olmalı (BY-021-R5)
            $klasorKaniti = $oncekiHesap === null && $sonuc['dogrulanan_klasor'] !== null
                && $sonuc['dogrulanan_klasor'] === ($hedef->baglanti['klasor_kimligi'] ?? null)
                && self::sonFizikselKopya($hedef) === $kayitliKopya;
            $ayniHesap = ($oncekiHesap !== null && $oncekiHesap === $sonuc['eposta']) || $klasorKaniti;

            // Dosya kimlikleri bağlı hesaptadır: hesabın aynı olduğu DOĞRULANMADIKÇA
            // (kayıtlı hesap bilinmiyor, kanıt yok) kopyası duran hedefte eski bağlantı aynen kalır
            if (! $ayniHesap && ($this->konum->kilitliAlanlar($hedef) !== [] || $this->geridekiKopyaVarMi($hedef, $sonuc['eposta']))) {
                return ['red' => $oncekiHesap === null ? 'GOOGLE_HESAP_DOGRULANAMADI' : 'HEDEF_KONUMU_DEGISMEZ', 'eski_anahtar' => null, 'eski_hesap' => null];
            }

            $baglanti = $hedef->baglanti ?? [];
            unset($baglanti['durum']);
            // Klasör o hesaptadır: başka (ya da bilinmeyen) hesapta yeniden aranır / açılır
            if (! $ayniHesap) {
                unset($baglanti['klasor_kimligi']);
            }
            $baglanti['eposta'] = $sonuc['eposta'];
            $hedef->baglanti = $baglanti;
            $hedef->gizli_bilgiler = [...($hedef->gizli_bilgiler ?? []), 'yenileme_anahtari' => $sonuc['yenileme_anahtari']];
            $hedef->save();

            // Eski anahtar yalnız BAŞKA bir hesabınsa iptal edilir: aynı hesabın iptali yeni bağlantıyı da düşürebilir
            $baskaHesap = is_string($oncekiHesap) && $oncekiHesap !== $sonuc['eposta'];

            return ['red' => null, 'eski_anahtar' => $baskaHesap ? $eskiAnahtar : null, 'eski_hesap' => $oncekiHesap];
        });
        $red = $kayit['red'];

        if ($red !== null) {
            $this->denetle('hedef_google_baglan', $request, $hedef, [], $red);
            // Kaydedilmeyen yeni bağlantının anahtarı Google'da geçerli kalmasın (YF-48)
            $this->googleAnahtariniIptalEt($request, $hedef, $sonuc['yenileme_anahtari'], $sonuc['eposta'], 'reddedilen_baglanti');

            return $red === 'HEDEF_KONUMU_DEGISMEZ'
                ? $this->konumDegismez(['eposta'])
                : response()->json(['kod' => $red, 'mesaj' => __('hata.yedek_google_hesap_dogrulanamadi')], 409);
        }

        $this->denetle('hedef_google_baglan', $request, $hedef);
        $this->googleAnahtariniIptalEt($request, $hedef, $kayit['eski_anahtar'], $kayit['eski_hesap'], 'yeniden_baglan');

        return response()->json(['data' => new YedekHedefiResource($hedef->refresh()->loadCount('planlar'))]);
    }

    /**
     * Klasör seçme penceresi (Google Picker) için tarayıcıya verilenler: bağlı
     * hesabın kısa ömürlü erişim anahtarı (`drive.file`), API anahtarı ve
     * proje numarası. Yenileme anahtarı hiçbir yanıtta dönmez; yanıt
     * önbelleğe alınmaz. Erişim anahtarı denetim kaydına girmez.
     */
    public function googleSecici(Request $request, YedekHedefi $hedef): JsonResponse
    {
        if ($hedef->tur !== HedefTuru::GoogleDrive) {
            return response()->json(['kod' => 'HEDEF_TURU_UYGUN_DEGIL', 'mesaj' => __('hata.yedek_hedefi_google_degil')], 409);
        }

        $apiAnahtari = config('yedekleme.google.api_anahtari');
        $projeNumarasi = config('yedekleme.google.proje_numarasi');

        if (! is_string($apiAnahtari) || $apiAnahtari === '' || ! is_string($projeNumarasi) || preg_match('/^[0-9]{1,20}$/D', $projeNumarasi) !== 1) {
            return response()->json(['kod' => 'GOOGLE_SECICI_YAPILANDIRILMADI', 'mesaj' => __('hata.yedek_google_secici_yapilandirilmadi')], 409);
        }

        try {
            /** @var GoogleDriveBaglayicisi $baglayici */
            $baglayici = $this->katalog->olustur($hedef);
            $erisim = $baglayici->seciciErisimi();
        } catch (HedefHatasi $hata) {
            $this->denetle('hedef_google_secici', $request, $hedef, [], $hata->kod);

            return response()->json(['kod' => $hata->kod, 'mesaj' => Str::limit($hata->getMessage(), 300)], 409);
        }

        $this->denetle('hedef_google_secici', $request, $hedef);

        return response()->json(['data' => [
            'erisim_anahtari' => $erisim,
            'api_anahtari' => $apiAnahtari,
            'proje_numarasi' => $projeNumarasi,
            'eposta' => $hedef->baglanti['eposta'] ?? null,
        ]])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Yedek klasörünü kaydeder: seçim penceresinde seçilen klasör
     * (`klasor_kimligi`) ya da Drive'ın köküne açılacak yeni klasör
     * (`yeni_klasor_adi`). Klasör önce bağlayıcıyla denenir (tür, çöp,
     * sahiplik, deneme dosyası yazıp silme); kayıt hedef satırının ve depo
     * kilidinin altında, denetimler yeniden yapılarak:
     * - Klasör konumdur: kopyası duran hedefte başka klasöre geçilemez (409
     *   `HEDEF_KONUMU_DEGISMEZ`); dosyası durabilen `konum_belirsiz` kopya da
     *   sayılır (G21).
     * - Başka bir hedefin klasörü seçilemez (409 `HEDEF_AYNI_DEPO`).
     * - Deneme sürerken hesap değiştiyse (yeniden bağlandı) kayıt yapılmaz (409).
     */
    public function googleKlasor(Request $request, YedekHedefi $hedef): JsonResponse
    {
        if ($hedef->tur !== HedefTuru::GoogleDrive) {
            return response()->json(['kod' => 'HEDEF_TURU_UYGUN_DEGIL', 'mesaj' => __('hata.yedek_hedefi_google_degil')], 409);
        }

        /** @var array{klasor_kimligi?: string|null, yeni_klasor_adi?: string|null} $veri */
        $veri = $request->validate([
            'klasor_kimligi' => ['required_without:yeni_klasor_adi', 'prohibits:yeni_klasor_adi', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,200}$/D'],
            'yeni_klasor_adi' => ['required_without:klasor_kimligi', 'nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/Du'],
        ]);
        $secilen = $veri['klasor_kimligi'] ?? null;

        // Ağ isteğinden önce ucuz denetim: kilitli hedefte boşuna klasör açılmasın / denenmesin
        if ($secilen !== ($hedef->baglanti['klasor_kimligi'] ?? null) && $this->klasorKilitliMi($hedef)) {
            $this->denetle('hedef_google_klasor', $request, $hedef, [], 'HEDEF_KONUMU_DEGISMEZ');

            return $this->konumDegismez(['klasor_kimligi']);
        }

        $hesap = $hedef->baglanti['eposta'] ?? null;

        try {
            /** @var GoogleDriveBaglayicisi $baglayici */
            $baglayici = $this->katalog->olustur($hedef);
            $klasor = $secilen !== null ? $baglayici->klasorDogrula($secilen) : $baglayici->klasorAc((string) $veri['yeni_klasor_adi']);
        } catch (HedefHatasi $hata) {
            $this->denetle('hedef_google_klasor', $request, $hedef, [], $hata->kod);

            return response()->json(['kod' => $hata->kod, 'mesaj' => Str::limit($hata->getMessage(), 300)], 422);
        }

        $red = $this->depoKilidiAltinda(fn (): ?JsonResponse => DB::transaction(function () use ($hedef, $klasor, $hesap): ?JsonResponse {
            $hedef = YedekHedefi::query()->whereKey($hedef->getKey())->lockForUpdate()->firstOrFail();

            if (($hedef->baglanti['eposta'] ?? null) !== $hesap) {
                return response()->json(['kod' => 'GOOGLE_HESAP_DEGISTI', 'mesaj' => __('hata.yedek_google_hesap_degisti')], 409);
            }

            if ($klasor['kimlik'] !== ($hedef->baglanti['klasor_kimligi'] ?? null) && $this->klasorKilitliMi($hedef)) {
                return $this->konumDegismez(['klasor_kimligi']);
            }

            $hedef->baglanti = [...($hedef->baglanti ?? []), 'klasor_kimligi' => $klasor['kimlik'], 'klasor_adi' => $klasor['ad']];
            $this->ayniDepoyuReddet($hedef);
            $hedef->save();

            return null;
        }));

        if ($red !== null) {
            $this->denetle('hedef_google_klasor', $request, $hedef, [], (string) $red->getData(true)['kod']);

            return $red;
        }

        $this->denetle('hedef_google_klasor', $request, $hedef, ['yeni' => $secilen === null]);

        return response()->json(['data' => new YedekHedefiResource($hedef->refresh()->load('sonKopya')->loadCount('planlar'))]);
    }

    /**
     * Klasör değiştirilemez mi: konum kilidi (kopyalanıyor / tamam) ya da
     * dosyası durabilen başka kopya (`konum_belirsiz` dahil; G21). Klasörü
     * hiç seçilmemiş hedefin kopyası olamaz (iz yok); eski sürümden kalan
     * izsiz kopya da geride sayılır.
     */
    private function klasorKilitliMi(YedekHedefi $hedef): bool
    {
        return $this->konum->kilitliAlanlar($hedef) !== []
            || $hedef->kopyalar()->whereIn('durum', KopyaDurumu::yedegiTutanlar())->exists();
    }

    /**
     * "Bağlantıyı kes": yenileme anahtarı silinir (ve Google'da iptal edilir —
     * googleAnahtariniIptalEt); kopyalar "yeniden bağlanın" ile durur. Satır
     * kilidi altında taze kayıttan yalnız ilgili anahtarlar değişir: araya
     * giren güncellemenin değişikliği (seçilen klasör) eski kopyayla ezilmez
     * (YF-55, G18).
     */
    public function googleKes(Request $request, YedekHedefi $hedef): YedekHedefiResource
    {
        [$hedef, $anahtar] = DB::transaction(function () use ($hedef): array {
            $hedef = YedekHedefi::query()->whereKey($hedef->getKey())->lockForUpdate()->firstOrFail();
            $gizli = $hedef->gizli_bilgiler ?? [];
            $anahtar = $gizli['yenileme_anahtari'] ?? null;
            unset($gizli['yenileme_anahtari']);
            $hedef->gizli_bilgiler = $gizli === [] ? null : $gizli;
            $hedef->baglanti = [...($hedef->baglanti ?? []), 'durum' => GoogleDriveBaglayicisi::DURUM_YENIDEN_BAGLAN];
            $hedef->save();

            return [$hedef, $anahtar];
        });
        $this->denetle('hedef_google_kes', $request, $hedef);
        $this->googleAnahtariniIptalEt($request, $hedef, $anahtar, $hedef->baglanti['eposta'] ?? null, 'kes');

        return new YedekHedefiResource($hedef->loadCount('planlar'));
    }

    /**
     * Artık kullanılmayan Google yenileme anahtarını en iyi çabayla iptal eder
     * (YF-48): "kesilmiş" bağlantının anahtarı eski veritabanı yedeklerinde
     * (`gizli_bilgiler`, APP_KEY ile) durur, iptal edilmezse kullanılabilir.
     * İptal başarısızlığı işlemi düşürmez; denetime `hedef_google_iptal`
     * olarak yazılır.
     *
     * Google'da iptal kullanıcının bu uygulamaya verdiği izni bütünüyle
     * kaldırabilir: aynı hesapla bağlı başka bir hedefin (ya da hesabı
     * bilinmeyen bağlı hedefin) anahtarı varken iptal edilmez — o hedefin
     * kopyaları sessizce "yeniden bağlanın"a düşerdi (GoogleDriveBaglayicisi::iptalGuvenliMi).
     *
     * @param  'kes'|'yeniden_baglan'|'reddedilen_baglanti'|'sil'  $neden
     */
    private function googleAnahtariniIptalEt(Request $request, YedekHedefi $hedef, mixed $anahtar, mixed $hesap, string $neden): void
    {
        if (! is_string($anahtar) || $anahtar === '' || ! GoogleDriveBaglayicisi::iptalGuvenliMi(is_string($hesap) ? $hesap : null)) {
            return;
        }

        $iptal = GoogleDriveBaglayicisi::iptalEt($anahtar);
        $this->denetle('hedef_google_iptal', $request, $hedef, ['neden' => $neden], $iptal ? null : 'GOOGLE_IPTAL_EDILEMEDI');
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
     * Dosyası hedefte duran ya da durabilecek (KopyaDurumu::yedegiTutanlar)
     * en son uzak kimlikli kopya (BY-021-R5): yükseltmede izsiz eski `tamam`
     * kopya `konum_belirsiz` olur, dosyası yine bağlı hesaptadır; hesap
     * kanıtında o da sorulur.
     */
    private static function sonFizikselKopya(YedekHedefi $hedef): ?string
    {
        $kimlik = $hedef->kopyalar()
            ->whereIn('durum', KopyaDurumu::yedegiTutanlar())
            ->whereNotNull('uzak_kimlik')
            ->orderByDesc('id')
            ->value('uzak_kimlik');

        return is_string($kimlik) ? $kimlik : null;
    }

    /**
     * Hesap değişince dosyası eski hesapta kalacak kopya var mı (BY-021-R5,
     * G21). Konum kilidi (HedefKonumu) yalnız `tamam` / `kopyalaniyor`'a
     * bakar; `konum_belirsiz` kopyanın dosyası da durabilir. Konum izi yeni
     * hesabın konumunu gösteren kopya geride kalmaz (o hesaba dönmek kopyanın
     * konumunu düzeltir); izsiz kopya doğrulanamaz, geride sayılır.
     */
    private function geridekiKopyaVarMi(YedekHedefi $hedef, string $yeniHesap): bool
    {
        $aday = clone $hedef;
        $aday->baglanti = [...($hedef->baglanti ?? []), 'eposta' => $yeniHesap];

        return $hedef->kopyalar()
            ->whereIn('durum', KopyaDurumu::yedegiTutanlar())
            ->distinct()
            ->pluck('konum_izi')
            ->contains(fn (?string $iz): bool => ! $this->konum->konumdaMi($aday, $iz));
    }

    /**
     * Hedef eklemede ve güncellemede aynı depo denetimi ile kayıt tek adımdır
     * (G18): eşzamanlı iki istek aynı klasörü denetimden geçirip ikisi de
     * kaydedemez. Kilit testte de tutulur.
     *
     * @template T
     *
     * @param  Closure(): T  $is
     * @return T
     */
    private function depoKilidiAltinda(Closure $is): mixed
    {
        return Cache::lock(self::DEPO_KILIDI, 10)->block(10, $is);
    }

    /**
     * Aynı fiziksel depoyu gösteren ikinci hedef kaydedilmez (409; BY-010-R7):
     * iki hedefte aynı ad aynı dosyadır, bir hedefin saklaması diğerinin
     * kopyasını silerdi. Bir hedef birden çok planda kullanılabilir (F6).
     */
    private function ayniDepoyuReddet(YedekHedefi $aday): void
    {
        $adlar = $this->konum->ayniDepodakiler($aday)->pluck('ad')->all();

        if ($adlar !== []) {
            throw new HttpResponseException(response()->json([
                'kod' => HedefHatasi::AYNI_DEPO,
                'mesaj' => __('hata.yedek_hedefi_ayni_depo', ['hedefler' => implode(', ', $adlar)]),
                'hedefler' => $adlar,
            ], 409));
        }
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
     * İstek gövdesi: `ad`, `aktif`, `tur` (yalnız eklemede), `alanlar` {ad: değer},
     * `silinecek_sirlar` (isteğe bağlı): kayıtlı sırrı silinecek alanların adları —
     * yalnız türün ZORUNLU OLMAYAN gizli alanları (SFTP'de parola ya da özel
     * anahtar). Boş sır "değişmedi" demektir; silmenin tek yolu budur (YF-49).
     * Silmeden sonra hedefte en az bir sır kalmalı (SFTP: kimliksiz kalmaz);
     * aynı alana hem değer hem silme verilemez (422).
     *
     * Evet / hayır alanları yalnız boolean değer alır ve gerçek `bool` olarak
     * saklanır (`1` → `true`; YF-50): bağlayıcı ve konum izi yalnız `=== true`'yu
     * açık sayar.
     *
     * @param  bool  $konumKilidi  Kayıtta (sınamada değil) kopyalı hedefin konumu değişmez (409)
     * @return array{0: HedefTuruTanimi, 1: array<string, mixed>, 2: array<string, string>, 3: list<string>} [tanım, açık alanlar, sırlar, silinen kayıtlı sırların adları]
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
        $silinebilir = array_values(array_map(fn (HedefAlani $a): string => $a->ad, array_filter($tanim->alanlar, fn (HedefAlani $a): bool => $a->gizli && ! $a->zorunlu)));
        /** @var list<string> $silinecek */
        $silinecek = $request->validate([
            'silinecek_sirlar' => ['sometimes', 'array', 'list'],
            'silinecek_sirlar.*' => ['string', 'distinct', Rule::in($silinebilir)],
        ])['silinecek_sirlar'] ?? [];

        $kurallar = [];
        foreach ($tanim->alanlar as $alan) {
            $sirKayitli = $alan->gizli && ($kayitli?->gizli_bilgiler[$alan->ad] ?? '') !== '';
            $kurallar[$alan->ad] = [
                ($alan->zorunlu && ! $sirKayitli) ? 'required' : 'nullable',
                ...$alan->kurallar,
                ...($alan->tur === HedefAlani::EVET_HAYIR && ! in_array('boolean', $alan->kurallar, true) ? ['boolean'] : []),
            ];
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

        foreach ($tanim->alanlar as $alan) {
            $deger = $gecerli[$alan->ad] ?? null;
            if ($alan->gizli) {
                if ($deger !== null && $deger !== '') {
                    $gizli[$alan->ad] = (string) $deger;
                }

                continue;
            }
            $acik[$alan->ad] = match (true) {
                $deger === null || $deger === '' => $alan->varsayilan,
                $alan->tur === HedefAlani::EVET_HAYIR => filter_var($deger, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
                default => $deger,
            };
        }

        // Kayıtlı sırrı silme (YF-49): yeni değerle birlikte istenemez; hedef sırsız kalamaz
        $celiski = array_values(array_filter($silinecek, fn (string $ad): bool => ($gecerli[$ad] ?? null) !== null && $gecerli[$ad] !== ''));
        if ($celiski !== []) {
            throw ValidationException::withMessages(['silinecek_sirlar' => __('hata.yedek_hedefi_sir_celiski', ['alanlar' => implode(', ', $celiski)])]);
        }
        $silinen = array_values(array_filter($silinecek, fn (string $ad): bool => ($gizli[$ad] ?? '') !== ''));
        $gizli = array_diff_key($gizli, array_flip($silinecek));
        $gizliAdlar = array_map(fn (HedefAlani $a): string => $a->ad, array_filter($tanim->alanlar, fn (HedefAlani $a): bool => $a->gizli));
        if ($silinecek !== [] && array_filter(array_intersect_key($gizli, array_flip($gizliAdlar)), fn (mixed $s): bool => $s !== '') === []) {
            throw ValidationException::withMessages(['silinecek_sirlar' => __('hata.yedek_hedefi_kimlik_kalmali')]);
        }

        // Konum kilidi sır kuralından önce: kilitli alanı değiştiren önce parolayı yeniden girmeye yönlenmesin
        if ($konumKilidi && $kayitli !== null) {
            $degisen = $this->konum->degisenKilitliAlanlar($kayitli, $acik);
            if ($degisen !== []) {
                throw new HttpResponseException($this->konumDegismez($degisen));
            }
        }

        // Bağlantı hedefi değişirken kayıtlı sır yeni yere gönderilmez (K8); silinen sır gönderilmez
        if ($kayitli !== null && $this->konum->adresDegistiMi($kayitli, $acik)) {
            foreach ($tanim->alanlar as $alan) {
                $bos = ($gecerli[$alan->ad] ?? null) === null || $gecerli[$alan->ad] === '';
                if ($alan->gizli && $bos && ! in_array($alan->ad, $silinecek, true) && ($kayitli->gizli_bilgiler[$alan->ad] ?? '') !== '') {
                    throw ValidationException::withMessages(['alanlar.'.$alan->ad => __('hata.yedek_hedefi_sir_yeniden')]);
                }
            }
        }

        return [$tanim, $acik, $gizli, $silinen];
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
