<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Yedek;
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
use Illuminate\Support\Facades\Log;
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
 *   dosyaya yazar. Oturumlar veritabanındadır ve yer değiştirmeyle düşer;
 *   bu yüzden tek yetki `X-Erisim-Anahtari` başlığındaki anahtardır ve
 *   yalnız kendi işlemine erişir. Özel başlık tarayıcıdan başka siteden
 *   gönderilemez (CSRF koruması yerine geçer). Hız sınırı yoktur:
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
    ) {}

    /** "Üstüne geri yükle" (onay metni = canlı veritabanının adı) */
    public function baslat(Request $request, YedekIstekServisi $istekler, PgAraclari $pg, string $kimlik): JsonResponse
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
        $istek = $istekler->birak('ustune_geri_yukle', [
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

        // Denetim kaydı veritabanına yazılmaz (erişilemez olabilir): günlüğe;
        // çalıştırıcı işlerken ayrıca kaydeder
        $istek = $this->kutu->yaz($tur, ['islem_kimligi' => $islem, 'erisim_anahtari' => $anahtar], $kayit->isteyenKullaniciId);
        Log::channel('yedekleme')->info('yedekleme.istek', ['tur' => $tur, 'istek' => $istek, 'islem' => $islem, 'kullanici_id' => $kayit->isteyenKullaniciId]);

        return response()->json(['data' => ['istek_kimligi' => $istek, 'durum' => 'kuyrukta']], 202);
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
