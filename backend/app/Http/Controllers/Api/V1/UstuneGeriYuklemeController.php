<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\IstekAnahtari;
use App\Models\Yedek;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\AyarSecimi;
use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\IslemDurumuBozuk;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\Istekler\IstekReddi;
use App\Services\Yedekleme\OnayBilgisi;
use App\Services\Yedekleme\PgAraclari;
use App\Services\Yedekleme\UstuneGeriYuklemeGorunumu;
use App\Services\Yedekleme\YedekIstekServisi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Üstüne geri yükleme (BY-030 arka uç; README §4.5-B, §6.4, §7.2).
 *
 * - baslat: oturumla, yalnız sistem yöneticisi. İsteği istek klasörüne
 *   bırakır (işi servis hesabının çalıştırıcısı yapar); işleme özel erişim
 *   anahtarını YALNIZ bu yanıtta bir kez verir (dosyada özeti durur).
 * - durum / onayla / geriDon: OTURUMSUZ. Bakım modundan muaftır
 *   (bootstrap/app.php) ve veritabanına dokunmaz — yalnız işlem durumu,
 *   onay bilgisi ve istek dosyalarını okur, onay / geri dönüş isteğini
 *   dosyaya yazar. İsteğe anahtar değil, 5 dakikalık tek kullanımlık karar
 *   kanıtı yazılır (ErisimAnahtari::kanit; BY-030-R1); onay, farkı olan her
 *   ayar grubunun seçimini taşır (BY-028-R2). Oturumlar veritabanındadır ve yer değiştirmeyle düşer;
 *   bu yüzden tek yetki `X-Erisim-Anahtari` başlığındaki anahtardır ve
 *   yalnız kendi işlemine erişir. CSRF belirteci gerekmez, çünkü anahtar
 *   çerez gibi tarayıcının kendiliğinden eklediği bir kimlik bilgisi
 *   değildir: başka site onu bilmeden isteği yetkilendiremez (CORS bu
 *   başlığı engellemez; koruma başlığın gönderilememesi değil, anahtarın
 *   sırrıdır — YF-34). Hız sınırı yoktur:
 *   sınırlayıcı veritabanındaki önbelleğe dayanır; anahtar 256 bit rastgele,
 *   karşılaştırma sabit sürelidir.
 */
final class UstuneGeriYuklemeController extends Controller
{
    public const ANAHTAR_BASLIGI = 'X-Erisim-Anahtari';

    public function __construct(
        private readonly YedeklemeYapilandirmasi $yapilandirma,
        private readonly IstekKutusu $kutu,
        private readonly OnayBilgisi $onayBilgisi,
        private readonly YedekIstekServisi $istekler,
    ) {}

    /**
     * "Üstüne geri yükle" (onay metni = canlı veritabanının adı). İstemci
     * anahtarı (Idempotency-Key) burada kullanılmaz: erişim anahtarı yalnız
     * ilk yanıtta döner, yeniden denemeye verilemez; ikinci istek çalıştırıcıda
     * "açık geri yükleme var" diye reddedilir.
     */
    public function baslat(Request $request, PgAraclari $pg, string $kimlik): JsonResponse
    {
        /** @var array{onay_metni: string} $veri */
        $veri = $request->validate(['onay_metni' => ['required', 'string', 'max:128']]);
        $canli = $pg->baglanti()->veritabani;

        if (! hash_equals($canli, $veri['onay_metni'])) {
            throw ValidationException::withMessages(['onay_metni' => __('hata.geri_yukleme_onay_metni', ['ad' => $canli])]);
        }

        $yedek = Yedek::query()->where('kimlik', $kimlik)->firstOrFail();

        $red = match (true) {
            ! $yedek->geriYuklenebilirMi() => ['GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ', 'hata.yedek_geri_yuklenemez'],
            $yedek->ice_alindi => ['GERI_YUKLEME_SEMA_BILINMIYOR', 'hata.geri_yukleme_sema_bilinmiyor'],
            ! $this->yapilandirma->servisCalistiricisi() => ['GERI_YUKLEME_SERVIS_GEREKLI', 'hata.geri_yukleme_servis_gerekli'],
            default => $this->acikIslemReddi(),
        };
        if ($red !== null) {
            return response()->json(['kod' => $red[0], 'mesaj' => __($red[1])], 409);
        }

        $anahtar = ErisimAnahtari::uret();
        $istek = $this->istekler->birak('ustune_geri_yukle', [
            'yedek_kimligi' => $yedek->kimlik,
            'onay_metni' => $veri['onay_metni'],
            'erisim_anahtari_ozeti' => ErisimAnahtari::ozet($anahtar),
        ], (int) $request->user()->id, $yedek->id);

        // İşlemin kimliği isteğin kimliğidir (çalıştırıcı aynısını kullanır)
        return response()->json(['data' => [
            'istek_kimligi' => $istek,
            'islem_kimligi' => $istek,
            'erisim_anahtari' => $anahtar,
            'erisim_anahtari_bitis' => CarbonImmutable::now('UTC')->addHours(ErisimAnahtari::GECERLILIK_SAAT)->toIso8601String(),
            'durum' => 'kuyrukta',
        ]], 202)->header('Cache-Control', 'no-store');
    }

    /**
     * İlerleme: işlem durumundan; henüz çalıştırıcıya ulaşmadıysa istek
     * dosyasından (`kuyrukta`), işlenmeden reddedildiyse sonuç dosyasından
     * (`basarisiz`). `?istek=<kimlik>` verilirse o onay / geri dönüş isteğinin
     * durumu da eklenir (yalnız bu işlemin isteğiyse).
     */
    public function durum(Request $request, string $islem): JsonResponse
    {
        $anahtar = $this->anahtar($request);
        if ($anahtar instanceof JsonResponse) {
            return $anahtar;
        }

        $kayit = $this->islem($islem);
        if ($kayit instanceof JsonResponse) {
            return $kayit;
        }

        if ($kayit !== null) {
            $red = $this->anahtarReddi(ErisimAnahtari::denetle($anahtar, $kayit->erisimAnahtariOzeti, $kayit->erisimAnahtariBitis));
            if ($red !== null) {
                return $red;
            }

            $gorunum = UstuneGeriYuklemeGorunumu::diziye($kayit, $this->onayBilgisi->bilgiOku($islem));
            $istek = $request->query('istek');
            if (is_string($istek)) {
                $gorunum['istek'] = $this->kararIstegi($istek, $islem);
            }

            return response()->json(['data' => $gorunum]);
        }

        return $this->henuzBaslamamis($islem, $anahtar);
    }

    public function onayla(Request $request, string $islem): JsonResponse
    {
        return $this->karar($request, $islem, 'onayla');
    }

    public function geriDon(Request $request, string $islem): JsonResponse
    {
        return $this->karar($request, $islem, 'geri_don');
    }

    /**
     * @param  'onayla'|'geri_don'  $tur
     */
    private function karar(Request $request, string $islem, string $tur): JsonResponse
    {
        $anahtar = $this->anahtar($request);
        if ($anahtar instanceof JsonResponse) {
            return $anahtar;
        }

        $kayit = $this->islem($islem);
        if ($kayit instanceof JsonResponse) {
            return $kayit;
        }

        if ($kayit === null) {
            return $this->hata('GERI_YUKLEME_ERISIM_ANAHTARI_GECERSIZ', 'hata.geri_yukleme_erisim_anahtari_gecersiz', 403);
        }

        $red = $this->anahtarReddi(ErisimAnahtari::denetle($anahtar, $kayit->erisimAnahtariOzeti, $kayit->erisimAnahtariBitis));
        if ($red !== null) {
            return $red;
        }

        $yapilabilir = UstuneGeriYuklemeGorunumu::diziye($kayit, null)['yapilabilir'];
        if (! $yapilabilir[$tur === 'onayla' ? 'onayla' : 'geri_don']) {
            return $this->hata('GERI_YUKLEME_ISLEM_DURUMU_UYGUN_DEGIL', 'hata.geri_yukleme_islem_durumu_uygun_degil', 409);
        }

        $parametreler = [];
        if ($tur === 'onayla') {
            $secimler = $this->ayarSecimleri($request, $islem);
            if ($secimler instanceof JsonResponse) {
                return $secimler;
            }
            $parametreler['ayar_secimleri'] = AyarSecimi::metne($secimler);
        }

        // Kabul sözleşmesi Yedekler istekleriyle ortak (YedekIstekServisi;
        // BY-030-R3): önce günlük, sonra yayın; yayımlanmış karar kabul
        // edilmiştir; aynı Idempotency-Key ile yeniden deneme aynı kararı izler.
        // İsteğe anahtar DEĞİL, kısa ömürlü, tek kullanımlık, karara ve
        // seçimlere bağlı kanıt yazılır (BY-030-R1); çalıştırıcı özetle doğrular
        $secim = $parametreler['ayar_secimleri'] ?? '';
        $istek = $this->istekler->kararBirak($tur, $islem, $secim, function () use ($kayit, $islem, $tur, $parametreler, $secim): array {
            $son = CarbonImmutable::now()->getTimestamp() + ErisimAnahtari::KANIT_OMRU_SN;
            $nonce = ErisimAnahtari::nonce();

            return [
                'islem_kimligi' => $islem,
                'son_gecerlilik' => $son,
                'nonce' => $nonce,
                ...$parametreler,
                'kanit' => ErisimAnahtari::kanit((string) $kayit->erisimAnahtariOzeti, $islem, $tur, $son, $nonce, $secim),
            ];
        }, $kayit->isteyenKullaniciId, IstekAnahtari::oku($request));

        return response()->json(['data' => ['istek_kimligi' => $istek, 'durum' => 'kuyrukta']], 202);
    }

    /**
     * Onayın ayar grubu seçimleri (README §5.3; BY-028-R2): onay bilgisinde
     * farkı olan her grup için `guncel` ya da `geri_yuklenen` zorunlu
     * (varsayılan yok). Çalıştırıcı da yeniden denetler.
     *
     * @return array<string, string>|JsonResponse
     */
    private function ayarSecimleri(Request $request, string $islem): array|JsonResponse
    {
        /** @var array{ayar_secimleri?: array<string, string>} $veri */
        $veri = $request->validate([
            'ayar_secimleri' => ['sometimes', 'array:'.implode(',', AyarKopyasi::GRUPLAR)],
            'ayar_secimleri.*' => ['string', 'in:'.AyarSecimi::GUNCEL.','.AyarSecimi::GERI_YUKLENEN],
        ]);
        $secimler = $veri['ayar_secimleri'] ?? [];

        $gruplar = $this->onayBilgisi->bilgiOku($islem)['ayar_gruplari'] ?? [];
        $gerekli = is_array($gruplar)
            ? array_map(fn (array $g): string => (string) $g['grup'], array_filter($gruplar, fn (mixed $g): bool => is_array($g) && ($g['farkli'] ?? false) === true))
            : [];

        return array_diff($gerekli, array_keys($secimler)) === []
            ? $secimler
            : $this->hata('GERI_YUKLEME_AYAR_SECIMI_EKSIK', 'hata.geri_yukleme_ayar_secimi_eksik', 422);
    }

    /**
     * Çalıştırıcı işlemi henüz başlatmadı: istek bekliyor ya da işlenmeden reddedildi.
     */
    private function henuzBaslamamis(string $islem, string $anahtar): JsonResponse
    {
        $kok = $this->yapilandirma->istekKlasoru();

        foreach (['bekleyen', 'isleniyor'] as $alt) {
            try {
                $istek = $this->kutu->oku("{$kok}/{$alt}/{$islem}.json");
            } catch (IstekReddi) {
                continue;
            }

            if ($istek->tur === 'ustune_geri_yukle') {
                $red = $this->anahtarReddi(ErisimAnahtari::denetle($anahtar, $istek->metin('erisim_anahtari_ozeti'), $istek->zaman->addHours(ErisimAnahtari::GECERLILIK_SAAT)));

                return $red ?? response()->json(['data' => $this->sade($islem, 'kuyrukta', null)]);
            }
        }

        // Taşınırken kaçırılmasın diye sonuç en son okunur
        $sonuc = $this->kutu->durum($islem);
        if (is_array($sonuc) && ($sonuc['islem_kimligi'] ?? null) === $islem && is_string($sonuc['erisim_anahtari_ozeti'] ?? null)) {
            $zaman = is_string($sonuc['zaman'] ?? null) ? CarbonImmutable::parse($sonuc['zaman']) : CarbonImmutable::now('UTC');
            $red = $this->anahtarReddi(ErisimAnahtari::denetle($anahtar, $sonuc['erisim_anahtari_ozeti'], $zaman->addHours(ErisimAnahtari::GECERLILIK_SAAT)));

            // İşlem durumu artık başka bir işlemi gösteriyorsa ilerleme bilinmez
            $reddedildi = ($sonuc['durum'] ?? null) === 'basarisiz';

            return $red ?? response()->json(['data' => $this->sade($islem, $reddedildi ? 'basarisiz' : 'bilinmiyor', $reddedildi ? [
                'kod' => (string) ($sonuc['kod'] ?? 'ISTEK_BEKLENMEYEN_HATA'), 'mesaj' => (string) ($sonuc['mesaj'] ?? ''),
            ] : null)]);
        }

        return $this->hata('GERI_YUKLEME_ERISIM_ANAHTARI_GECERSIZ', 'hata.geri_yukleme_erisim_anahtari_gecersiz', 403);
    }

    /**
     * Onay / geri dönüş isteğinin durumu; bu işlemin isteği değilse null.
     *
     * @return array{durum: string, kod: string|null, mesaj: string|null}|null
     */
    private function kararIstegi(string $istek, string $islem): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $istek) !== 1) {
            return null;
        }

        $kok = $this->yapilandirma->istekKlasoru();
        foreach (['bekleyen' => 'kuyrukta', 'isleniyor' => 'calisiyor'] as $alt => $durum) {
            try {
                $okunan = $this->kutu->oku("{$kok}/{$alt}/{$istek}.json");
            } catch (IstekReddi) {
                continue;
            }

            return in_array($okunan->tur, ['onayla', 'geri_don'], true) && $okunan->metin('islem_kimligi') === $islem
                ? ['durum' => $durum, 'kod' => null, 'mesaj' => null] : null;
        }

        $sonuc = $this->kutu->durum($istek);

        return is_array($sonuc) && ($sonuc['islem_kimligi'] ?? null) === $islem && in_array($sonuc['durum'] ?? null, ['tamam', 'basarisiz'], true)
            ? ['durum' => (string) $sonuc['durum'], 'kod' => $sonuc['kod'] ?? null, 'mesaj' => $sonuc['mesaj'] ?? null]
            : null;
    }

    /**
     * Henüz işlem durumu olmayan işlemin sade görünümü.
     *
     * @param  array{kod: string, mesaj: string}|null  $hata
     * @return array<string, mixed>
     */
    private function sade(string $islem, string $durum, ?array $hata): array
    {
        return [
            'islem_kimligi' => $islem, 'durum' => $durum, 'adim' => null, 'acik' => false, 'yer_degistirildi' => false,
            'hata' => $hata, 'adimlar' => [], 'yapilabilir' => ['onayla' => false, 'geri_don' => false], 'onay_bilgisi' => null,
        ];
    }

    private function anahtar(Request $request): string|JsonResponse
    {
        $anahtar = $request->header(self::ANAHTAR_BASLIGI);

        return is_string($anahtar) && $anahtar !== ''
            ? $anahtar
            : $this->hata('GERI_YUKLEME_ERISIM_ANAHTARI_YOK', 'hata.geri_yukleme_erisim_anahtari_yok', 401);
    }

    /**
     * Bu işlemin kaydı (işlem durumu bu işlemi gösteriyorsa); değilse null.
     */
    private function islem(string $islem): IslemKaydi|JsonResponse|null
    {
        try {
            $kayit = $this->yapilandirma->islemDurumu()->oku();
        } catch (IslemDurumuBozuk) {
            return $this->hata('GERI_YUKLEME_ISLEM_DURUMU_OKUNAMADI', 'hata.geri_yukleme_islem_durumu_okunamadi', 503);
        }

        return $kayit !== null && $kayit->tur === IslemTuru::GeriYuklemeUstune && $kayit->kimlik === $islem ? $kayit : null;
    }

    private function anahtarReddi(string $sonuc): ?JsonResponse
    {
        return match ($sonuc) {
            ErisimAnahtari::GECERLI => null,
            ErisimAnahtari::SURESI_DOLDU => $this->hata('GERI_YUKLEME_ERISIM_ANAHTARI_SURESI_DOLDU', 'hata.geri_yukleme_erisim_anahtari_suresi_doldu', 403),
            default => $this->hata('GERI_YUKLEME_ERISIM_ANAHTARI_GECERSIZ', 'hata.geri_yukleme_erisim_anahtari_gecersiz', 403),
        };
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function acikIslemReddi(): ?array
    {
        try {
            return $this->yapilandirma->islemDurumu()->acikGeriYuklemeVarMi() ? ['GERI_YUKLEME_ACIK_GERI_YUKLEME', 'hata.geri_yukleme_acik'] : null;
        } catch (IslemDurumuBozuk) {
            return ['GERI_YUKLEME_ISLEM_DURUMU_OKUNAMADI', 'hata.geri_yukleme_islem_durumu_okunamadi'];
        }
    }

    private function hata(string $kod, string $mesaj, int $durum): JsonResponse
    {
        return response()->json(['kod' => $kod, 'mesaj' => __($mesaj)], $durum);
    }
}
