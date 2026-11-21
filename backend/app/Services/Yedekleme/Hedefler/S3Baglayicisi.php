<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\AtomikDosya;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\SureButcesi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\LazyOpenStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * "S3 uyumlu depolama" hedefi (README §4.6, BY-023): AWS S3 ya da S3 uyumlu
 * bir uç nokta (MinIO, Ceph…). Ek kütüphane yok: istekler Laravel HTTP
 * istemcisiyle, AWS Signature V4 ile imzalanır. Gizli anahtar hiçbir isteğe,
 * hata mesajına ya da `baglanti`'ya girmez; yalnız imza hesabında kullanılır.
 *
 * Yol stili (`yol_stili`): açıksa `https://uc/kova/anahtar` (MinIO gibi S3
 * uyumlu sunucuların çoğu), kapalıysa `https://kova.uc/anahtar` (AWS). Noktalı
 * kova adı sanal sunucu stilinde TLS sertifikasıyla eşleşmez; yol stili seçilir.
 *
 * Tek PUT atomiktir: nesne ancak yükleme tamamlanınca görünür, hedefte yarım
 * dosya kalmaz. Gövdenin SHA-256'sı imzaya girer; S3 içeriği bununla doğrular.
 * Bütün işlemler (GET, HEAD, PUT aynı nesneye, DELETE) tekrarlanabilir: yalnız
 * bağlantı hatası, 5xx ve 429'da yeniden denenir; 4xx kalıcıdır (D8).
 */
final class S3Baglayicisi implements HedefBaglayicisi
{
    /** Boş gövdenin SHA-256'sı (GET, HEAD, DELETE) */
    private const BOS_GOVDE_OZETI = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /** Dosya taşımayan isteklerin süre sınırı (sn); dosya taşıyan istekler `sure_siniri.kopya` */
    private const KISA_ISTEK_SURESI = 30;

    private const YENIDEN_DENEME_MS = [500, 2000];

    /** Liste sayfası sınırı: bozuk bir sunucu sonsuz sayfa döndürse de döngü biter */
    private const EN_COK_SAYFA = 1000;

    private const UC_NOKTA = '#^https://([A-Za-z0-9.-]{1,253})(?::([0-9]{1,5}))?/?$#D';

    private const BOLGE = '/^[a-z0-9-]{1,40}$/D';

    private const KOVA = '/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D';

    /** `/` ile ayrılmış parçalar; parça noktayla başlamaz (`.`, `..` yok), başta `/` yok */
    private const ON_EK = '#^(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*(?:[A-Za-z0-9_-][A-Za-z0-9._-]*)?$#D';

    private const ERISIM_ANAHTARI = '/^[A-Za-z0-9._~+=-]{3,128}$/D';

    public function __construct(
        private readonly YedekHedefi $hedef,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    public static function tanim(HedefTuru $tur = HedefTuru::S3): HedefTuruTanimi
    {
        return new HedefTuruTanimi(HedefTuru::S3, [
            new HedefAlani('uc_nokta', 'yedekleme.hedef.alan.uc_nokta', zorunlu: true, kurallar: ['string', 'max:300', 'regex:'.self::UC_NOKTA]),
            new HedefAlani('bolge', 'yedekleme.hedef.alan.bolge', zorunlu: true, kurallar: ['string', 'regex:'.self::BOLGE], varsayilan: 'us-east-1'),
            new HedefAlani('kova', 'yedekleme.hedef.alan.kova', zorunlu: true, kurallar: ['string', 'regex:'.self::KOVA]),
            new HedefAlani('on_ek', 'yedekleme.hedef.alan.on_ek', kurallar: ['nullable', 'string', 'max:200', 'regex:'.self::ON_EK]),
            new HedefAlani('yol_stili', 'yedekleme.hedef.alan.yol_stili', HedefAlani::EVET_HAYIR, kurallar: ['boolean'], varsayilan: false),
            new HedefAlani('erisim_anahtari', 'yedekleme.hedef.alan.erisim_anahtari', zorunlu: true, kurallar: ['string', 'regex:'.self::ERISIM_ANAHTARI]),
            new HedefAlani('gizli_anahtar', 'yedekleme.hedef.alan.gizli_anahtar', HedefAlani::PAROLA, zorunlu: true, gizli: true, kurallar: ['string', 'max:256']),
        ], sunucuDisi: true);
    }

    /** Kovaya erişim ve kimlik (HEAD kova). */
    public function baglan(): void
    {
        $yanit = $this->istek('HEAD', '');

        if ($yanit->successful()) {
            return;
        }

        if ($yanit->status() === 301) {
            $bolge = (string) $yanit->header('x-amz-bucket-region');
            $ipucu = preg_match(self::BOLGE, $bolge) === 1 ? " (kovanın bölgesi: {$bolge})" : '';

            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, "S3 kovası başka bir bölgede{$ipucu}.");
        }

        if ($yanit->status() === 404) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'S3 kovası bulunamadı (HTTP 404).');
        }

        throw $this->hata($yanit, HedefHatasi::ERISILEMEDI, 'bağlantısı');
    }

    public function yaz(string $yerelYol, string $ad): string
    {
        $this->adDenetle($ad);
        $anahtar = $this->onEk().$ad;

        if (! is_file($yerelYol) || ! is_readable($yerelYol)) {
            throw new HedefHatasi(HedefHatasi::YAZILAMADI, 'Yüklenecek yerel dosya okunamadı.');
        }

        $yanit = $this->istek('PUT', $anahtar, govdeYolu: $yerelYol);

        if (! $yanit->successful()) {
            throw $this->hata($yanit, HedefHatasi::YAZILAMADI, 'yüklemesi');
        }

        return $anahtar;
    }

    public function oku(string $uzakKimlik, string $yerelYol): void
    {
        $this->anahtarDenetle($uzakKimlik);
        $gecici = AtomikDosya::geciciAd($yerelYol);

        try {
            $yanit = $this->istek('GET', $uzakKimlik, hedefYol: $gecici);

            if (! $yanit->successful()) {
                throw $this->hata($yanit, HedefHatasi::OKUNAMADI, 'indirmesi');
            }

            if (! @rename($gecici, $yerelYol)) {
                throw new HedefHatasi(HedefHatasi::OKUNAMADI, 'İndirilen dosya yerine taşınamadı.');
            }
        } finally {
            if (file_exists($gecici)) {
                @unlink($gecici);
            }
        }
    }

    /**
     * Silme yetkisi yoksa (değiştirilemez saklama, kova politikası) S3
     * `AccessDenied` döner: SILME_YETKISI_YOK — temizlik bunu hata değil
     * bilgi sayar (README §4.6, BY-023).
     */
    public function sil(string $uzakKimlik): void
    {
        $this->anahtarDenetle($uzakKimlik);
        $yanit = $this->istek('DELETE', $uzakKimlik);

        if ($yanit->successful() || ($yanit->status() === 404 && $this->s3Kodu($yanit) === 'NoSuchKey')) {
            return;
        }

        if ($yanit->status() === 403 && $this->s3Kodu($yanit) === 'AccessDenied') {
            throw new HedefHatasi(HedefHatasi::SILME_YETKISI_YOK, "S3 hedefi bu uygulamaya silme yetkisi vermiyor: {$uzakKimlik}");
        }

        throw $this->hata($yanit, HedefHatasi::SILINEMEDI, 'silmesi');
    }

    /** Ön ekin hemen altındaki `erp_….dump|gpg` nesneleri (ListObjectsV2, sayfalı). */
    public function listele(): array
    {
        $onEk = $this->onEk();
        $anahtarlar = [];
        $devam = null;

        for ($sayfa = 0; $sayfa < self::EN_COK_SAYFA; $sayfa++) {
            $sorgu = ['list-type' => '2', 'prefix' => $onEk.'erp_'];
            if ($devam !== null) {
                $sorgu['continuation-token'] = $devam;
            }

            $yanit = $this->istek('GET', '', $sorgu);
            $xml = $yanit->successful() ? $this->xml($yanit->body()) : null;

            if ($xml === null) {
                throw $yanit->successful()
                    ? new HedefHatasi(HedefHatasi::ERISILEMEDI, 'S3 liste yanıtı okunamadı.')
                    : $this->hata($yanit, HedefHatasi::ERISILEMEDI, 'listelemesi');
            }

            foreach ($xml->Contents as $nesne) {
                $anahtar = (string) $nesne->Key;
                $ad = substr($anahtar, strlen($onEk));

                if (str_starts_with($anahtar, $onEk) && preg_match('/^erp_[^\/]+\.(dump|gpg)$/D', $ad) === 1) {
                    $anahtarlar[] = $anahtar;
                }
            }

            $devam = (string) $xml->NextContinuationToken;

            if ((string) $xml->IsTruncated !== 'true' || $devam === '') {
                return $anahtarlar;
            }
        }

        throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'S3 listesi sayfa sınırını aştı.');
    }

    /** S3 kota bildirmez. */
    public function kalanAlan(): ?int
    {
        return null;
    }

    /**
     * Kovada nesne kilidi (Object Lock — değiştirilemez saklama) açık mı;
     * ekranda bilgi olarak gösterilir (BY-023). Bilinemiyorsa (yetki yok,
     * erişilemedi) null.
     */
    public function silinmeKorumasiVarMi(): ?bool
    {
        try {
            $yanit = $this->istek('GET', '', ['object-lock' => '']);
        } catch (HedefHatasi) {
            return null;
        }

        if ($yanit->status() === 404) {
            return false;
        }

        $xml = $yanit->successful() ? $this->xml($yanit->body()) : null;

        return $xml === null ? null : (string) $xml->ObjectLockEnabled === 'Enabled';
    }

    /**
     * İmzalı istek çıkaran TEK nokta. Adres ve kimlik bilgisi istekten önce
     * doğrulanır; yönlendirme izlenmez (imzalı istek başka bir sunucuya gitmez).
     *
     * @param  array<string, string>  $sorgu
     *
     * @throws HedefHatasi
     */
    private function istek(string $yontem, string $anahtar, array $sorgu = [], ?string $govdeYolu = null, ?string $hedefYol = null): Response
    {
        [$sema, $sunucu, $yol] = $this->adres($anahtar);
        $sorguMetni = $this->kanonikSorgu($sorgu);
        $govdeOzeti = $govdeYolu === null ? self::BOS_GOVDE_OZETI : (string) hash_file('sha256', $govdeYolu);
        $zaman = CarbonImmutable::now('UTC');
        $amzTarih = $zaman->format('Ymd\THis\Z');

        $imzali = ['host' => $sunucu, 'x-amz-content-sha256' => $govdeOzeti, 'x-amz-date' => $amzTarih];
        $basliklar = [
            'x-amz-content-sha256' => $govdeOzeti,
            'x-amz-date' => $amzTarih,
            'Authorization' => $this->yetkiBasligi($yontem, $yol, $sorguMetni, $imzali, $govdeOzeti, $zaman),
        ];

        $istemci = $this->istemci($govdeYolu !== null || $hedefYol !== null)->withHeaders($basliklar);

        if ($govdeYolu !== null) {
            // Akışla: dosya belleğe alınmaz; yeniden denemede baştan okunur
            $istemci = $istemci->withBody(new LazyOpenStream($govdeYolu, 'rb'), 'application/octet-stream');
        }

        if ($hedefYol !== null) {
            $istemci = $istemci->sink($hedefYol);
        }

        try {
            return $istemci->send($yontem, $sema.'://'.$sunucu.$yol.($sorguMetni === '' ? '' : '?'.$sorguMetni));
        } catch (ConnectionException $hata) {
            throw $this->sertifikaHatasiMi($hata)
                ? new HedefHatasi(HedefHatasi::SERTIFIKA, 'S3 uç noktasının TLS sertifikası doğrulanamadı.')
                : new HedefHatasi(HedefHatasi::ERISILEMEDI, 'S3 uç noktasına bağlanılamadı ya da süre aşıldı.');
        }
    }

    /**
     * Sınamada (etkin süre bütçesi) sınırlar ortak son zamana kısalır, deneme
     * ve önündeki bekleme sığmıyorsa yapılmaz (BY-016-R4).
     */
    private function istemci(bool $dosyaTasir): PendingRequest
    {
        $kopyaSuresi = $this->yapilandirma->sureSiniri('kopya');
        $baglanti = $this->yapilandirma->sureSiniri('baglanti');
        $zamanAsimi = $dosyaTasir ? $kopyaSuresi : min(self::KISA_ISTEK_SURESI, $kopyaSuresi);

        return Http::connectTimeout($baglanti)
            ->timeout($zamanAsimi)
            ->withoutRedirecting()
            ->retry(
                self::YENIDEN_DENEME_MS,
                0,
                fn (?Throwable $hata, PendingRequest $istek): bool => (($hata instanceof ConnectionException && ! $this->sertifikaHatasiMi($hata))
                    || ($hata instanceof RequestException
                        && ($hata->response->serverError() || $hata->response->status() === 429)))
                    && $this->denemeyiSinirla($istek, $baglanti, $zamanAsimi),
                throw: false,
            );
    }

    /** Sonraki deneme etkin süre bütçesine sığıyorsa süre sınırlarını kalan süreye kısaltır */
    private function denemeyiSinirla(PendingRequest $istek, int $baglanti, int $zamanAsimi): bool
    {
        $sinir = SureButcesi::denemeSiniri($zamanAsimi, max(self::YENIDEN_DENEME_MS));
        if ($sinir === null) {
            return false;
        }

        $istek->connectTimeout(min($baglanti, $sinir))->timeout($sinir);

        return true;
    }

    /**
     * AWS Signature V4 (tek parça gövde). Kanonik istek: yöntem, yol, sorgu,
     * imzalı başlıklar, gövde özeti; anahtar gizli anahtardan gün → bölge →
     * hizmet → `aws4_request` zinciriyle türetilir.
     *
     * @param  array<string, string>  $imzali  küçük harfli başlık adı => değer
     */
    private function yetkiBasligi(string $yontem, string $yol, string $sorguMetni, array $imzali, string $govdeOzeti, CarbonImmutable $zaman): string
    {
        $gun = $zaman->format('Ymd');
        $bolge = $this->ayar('bolge', self::BOLGE);
        $kapsam = "{$gun}/{$bolge}/s3/aws4_request";
        ksort($imzali);

        $kanonikBasliklar = '';
        foreach ($imzali as $ad => $deger) {
            $kanonikBasliklar .= $ad.':'.trim($deger)."\n";
        }
        $imzaliAdlar = implode(';', array_keys($imzali));

        $kanonik = implode("\n", [$yontem, $yol, $sorguMetni, $kanonikBasliklar, $imzaliAdlar, $govdeOzeti]);
        $imzalanacak = implode("\n", ['AWS4-HMAC-SHA256', $zaman->format('Ymd\THis\Z'), $kapsam, hash('sha256', $kanonik)]);

        $anahtar = hash_hmac('sha256', $gun, 'AWS4'.$this->gizliAnahtar(), true);
        foreach ([$bolge, 's3', 'aws4_request'] as $parca) {
            $anahtar = hash_hmac('sha256', $parca, $anahtar, true);
        }

        $erisim = $this->ayar('erisim_anahtari', self::ERISIM_ANAHTARI);
        $imza = hash_hmac('sha256', $imzalanacak, $anahtar);

        return "AWS4-HMAC-SHA256 Credential={$erisim}/{$kapsam}, SignedHeaders={$imzaliAdlar}, Signature={$imza}";
    }

    /**
     * Doğrulanmış adres parçaları. `parse_url`'e güvenilmez: alan adı izinli
     * karakter kümesiyle denetlenir (G15).
     *
     * @return array{0: string, 1: string, 2: string} [şema, sunucu(:port), kodlanmış yol]
     */
    private function adres(string $anahtar): array
    {
        $uc = $this->hedef->baglanti['uc_nokta'] ?? null;

        if (! is_string($uc) || preg_match(self::UC_NOKTA, $uc, $parca) !== 1 || str_contains($parca[1], '..') || ! ctype_alnum($parca[1][0])) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'S3 uç noktası geçersiz (yalnız https://alan.adi[:port]).');
        }

        $port = isset($parca[2]) && $parca[2] !== '' ? (int) $parca[2] : 443;
        if ($port < 1 || $port > 65535) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'S3 uç noktasının portu geçersiz.');
        }

        $kova = $this->ayar('kova', self::KOVA);
        $kodlu = implode('/', array_map(rawurlencode(...), explode('/', $anahtar)));
        $alanAdi = strtolower($parca[1]);
        $yolStili = ($this->hedef->baglanti['yol_stili'] ?? false) === true;
        $sunucu = ($yolStili ? $alanAdi : $kova.'.'.$alanAdi).($port === 443 ? '' : ':'.$port);

        return ['https', $sunucu, ($yolStili ? '/'.$kova : '').'/'.$kodlu];
    }

    /** @param  array<string, string>  $sorgu */
    private function kanonikSorgu(array $sorgu): string
    {
        $parcalar = [];
        foreach ($sorgu as $ad => $deger) {
            $parcalar[rawurlencode((string) $ad)] = rawurlencode($deger);
        }
        ksort($parcalar, SORT_STRING);

        return implode('&', array_map(fn (string $ad, string $deger): string => $ad.'='.$deger, array_keys($parcalar), $parcalar));
    }

    private function onEk(): string
    {
        $onEk = $this->hedef->baglanti['on_ek'] ?? '';
        $onEk = is_string($onEk) ? trim($onEk, '/') : null;

        if ($onEk === null || strlen($onEk) > 200 || preg_match(self::ON_EK, $onEk) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'S3 anahtar ön eki geçersiz.');
        }

        return $onEk === '' ? '' : $onEk.'/';
    }

    private function ayar(string $ad, string $bicim): string
    {
        $deger = $this->hedef->baglanti[$ad] ?? null;

        if (! is_string($deger) || preg_match($bicim, $deger) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, "S3 hedefinin '{$ad}' alanı geçersiz.");
        }

        return $deger;
    }

    private function gizliAnahtar(): string
    {
        $gizli = $this->hedef->gizli_bilgiler['gizli_anahtar'] ?? null;

        if (! is_string($gizli) || $gizli === '') {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'S3 gizli anahtarı tanımlı değil.');
        }

        return $gizli;
    }

    /** Yalnız düz dosya adı (YerelDiskBaglayicisi ile aynı kural) */
    private function adDenetle(string $ad): void
    {
        if (preg_match('/^[A-Za-z0-9.][A-Za-z0-9._+-]{0,200}$/D', $ad) !== 1 || str_contains($ad, '..')) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz hedef dosya adı.');
        }
    }

    /** Uzak kimlik = ön ek + düz dosya adı: başka bir ön ekteki nesneye dokunulmaz */
    private function anahtarDenetle(string $anahtar): void
    {
        $onEk = $this->onEk();

        if (! str_starts_with($anahtar, $onEk)) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz hedef dosya kimliği.');
        }

        $this->adDenetle(substr($anahtar, strlen($onEk)));
    }

    /**
     * Ham gövde mesaja girmez; yalnız HTTP durumu ve doğrulanmış kısa S3 kodu (D2).
     */
    private function hata(Response $yanit, string $varsayilanKod, string $islem): HedefHatasi
    {
        $durum = $yanit->status();
        $s3Kodu = $this->s3Kodu($yanit);
        $ek = "HTTP {$durum}".($s3Kodu === null ? '' : ", {$s3Kodu}");

        $kod = match (true) {
            $durum >= 500 || $durum === 429 => HedefHatasi::ERISILEMEDI,
            $durum === 401 || $durum === 403 => HedefHatasi::KIMLIK,
            $s3Kodu === 'NoSuchBucket' => HedefHatasi::YAPILANDIRMA,
            default => $varsayilanKod,
        };

        return new HedefHatasi($kod, "S3 {$islem} başarısız ({$ek}).");
    }

    /** Hata gövdesindeki `<Code>`; yalnız kısa harf dizisi kabul edilir (D2). */
    private function s3Kodu(Response $yanit): ?string
    {
        $xml = $this->xml($yanit->body());
        $kod = $xml === null ? '' : (string) $xml->Code;

        return preg_match('/^[A-Za-z]{1,40}$/D', $kod) === 1 ? $kod : null;
    }

    private function xml(string $govde): ?SimpleXMLElement
    {
        if (trim($govde) === '') {
            return null;
        }

        $onceki = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($govde, SimpleXMLElement::class, LIBXML_NONET);

            return $xml === false ? null : $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($onceki);
        }
    }

    /** cURL TLS hataları (35, 51, 58, 60): yeniden denemeyle düzelmez, sessizce yok sayılmaz */
    private function sertifikaHatasiMi(ConnectionException $hata): bool
    {
        return preg_match('/cURL error (35|51|58|60)\b/', $hata->getMessage()) === 1;
    }
}
