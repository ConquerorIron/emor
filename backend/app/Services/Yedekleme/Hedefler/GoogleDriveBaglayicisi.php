<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\AtomikDosya;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\SureButcesi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use GuzzleHttp\Psr7\LazyOpenStream;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * "Google Drive" hedefi (README §4.6 "Google Drive bağlantısı", BY-021).
 * Ek kütüphane yok: Drive REST v3 + OAuth yenileme Laravel HTTP istemcisiyle.
 *
 * Yetki en dar olanıdır: yalnız `drive.file` — uygulama yalnız KENDİ
 * oluşturduğu dosyaları ve kullanıcının Google'ın seçim penceresinde
 * (Picker) seçtiği klasörü görür, hesabın diğer dosyalarını listeleyemez.
 * Bağlı hesabın e-postası da bu yetkiyle `about.user`'dan okunur; `openid
 * email` istenmez.
 *
 * Akış: hedef kaydedilir → "Google ile bağlan" (OAuth, yenileme anahtarı) →
 * "Klasör seç" (tarayıcıda Google Picker, `seciciErisimi`; ya da yeni klasör
 * `klasorAc`) → seçilen klasör `klasorDogrula` ile denenip
 * `baglanti.klasor_kimligi` / `klasor_adi`'na yazılır. Klasör konumdur
 * (HedefKonumu): kopyası duran hedefte değiştirilemez.
 *
 * Sırlar: yenileme anahtarı `gizli_bilgiler['yenileme_anahtari']`'nda
 * (şifreli), istemci kimliği / sırrı config'te. Erişim anahtarı yalnız bu
 * nesnede, istek süresince tutulur. Hiçbiri hata mesajına, günlüğe ya da
 * `baglanti`'ya girmez; Google'ın hata gövdesi de taşınmaz (D2).
 *
 * İzin kaldırılınca (Google `invalid_grant` ya da yenilemeden sonra yine 401)
 * hedef `baglanti.durum = yeniden_baglan` olur ve YENIDEN_BAGLAN fırlatılır;
 * kopyalayıcı bunu yeniden denemez (D8).
 *
 * Yükleme "resumable" oturumla yapılır: dosya Drive'da ancak içerik tamamen
 * gelince oluşur, hedefte yarım dosya kalmaz. Oturumu açan istek dosya
 * oluşturmadığı için yeniden denenebilir; içerik isteği denenmez — yanıtı
 * kaybolursa oturumun durumu sorulur (yinelenen dosya oluşmasın).
 */
final class GoogleDriveBaglayicisi implements HedefBaglayicisi
{
    public const KAPSAM = 'https://www.googleapis.com/auth/drive.file';

    public const DURUM_YENIDEN_BAGLAN = 'yeniden_baglan';

    /** İzin kodu değişiminde hesabın kimliği okunamadı; bağlantı kaydedilmez */
    public const HESAP_OKUNAMADI = 'GOOGLE_HESAP_OKUNAMADI';

    private const YETKI_ADRESI = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ADRESI = 'https://oauth2.googleapis.com/token';

    private const API = 'https://www.googleapis.com/drive/v3';

    private const YUKLEME_API = 'https://www.googleapis.com/upload/drive/v3/files';

    private const KLASOR_TURU = 'application/vnd.google-apps.folder';

    /** Dosya taşımayan isteklerin süre sınırı (sn); dosya taşıyanlar `sure_siniri.kopya` */
    private const KISA_ISTEK_SURESI = 30;

    private const YENIDEN_DENEME_MS = [500, 2000];

    private const EN_COK_SAYFA = 100;

    /** Drive dosya kimliği */
    private const KIMLIK = '/^[A-Za-z0-9_-]{1,200}$/D';

    /** OAuth `state` (sahte istek koruması): tahmin edilemeyecek uzunlukta olmalı */
    private const DURUM_DEGERI = '/^[A-Za-z0-9._~-]{32,512}$/D';

    private ?string $erisimAnahtari = null;

    private ?string $klasorKimligi = null;

    public function __construct(
        private readonly YedekHedefi $hedef,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    public static function tanim(HedefTuru $tur = HedefTuru::GoogleDrive): HedefTuruTanimi
    {
        // Formda alan yok: hesap "Google ile bağlan", klasör "Klasör seç" ile gelir
        return new HedefTuruTanimi(HedefTuru::GoogleDrive, [], sunucuDisi: true, oauth: true);
    }

    /**
     * Google izin ekranının adresi. `$durum` OAuth `state` değeridir: akışı
     * başlatan oturuma bağlı, tahmin edilemez bir değer olmalı ve dönüşte
     * çağıran tarafından doğrulanmalıdır (sahte istek koruması).
     *
     * @throws HedefHatasi İstemci kimliği tanımlı değil
     * @throws InvalidArgumentException Durum değeri kısa ya da biçimsiz
     */
    public static function yetkiAdresi(string $durum, string $donusAdresi): string
    {
        if (preg_match(self::DURUM_DEGERI, $durum) !== 1) {
            throw new InvalidArgumentException('OAuth durum değeri en az 32 karakterlik rastgele bir değer olmalı.');
        }

        [$istemciKimligi] = self::istemciBilgileri();

        return self::YETKI_ADRESI.'?'.http_build_query([
            'client_id' => $istemciKimligi,
            'redirect_uri' => $donusAdresi,
            'response_type' => 'code',
            'scope' => self::KAPSAM,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $durum,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * İzin kodunu kalıcı yenileme anahtarıyla değiştirir. Kod tek kullanımlık
     * olduğu için istek yeniden denenmez. Kullanıcı izin ekranında Drive
     * kutusunu kaldırdıysa (`drive.file` verilmediyse) bağlantı kurulmaz.
     * Hesabın e-postası (`about.user`) ZORUNLUDUR: okunamazsa bağlantı
     * kurulmaz (HESAP_OKUNAMADI, yeniden denenebilir) — kimliksiz bağlantı
     * kopya alır, sonra aynı hesapla bile yenilenemezdi (BY-021-R3).
     *
     * `$klasorKimligi` verilirse (hesabı kayıtlı olmayan eski hedef) yeni
     * bağlantının o klasörün SAHİBİ olup olmadığı sorulur. Erişim kanıt
     * değildir: Drive'da paylaşılmış klasör başka hesaptan da okunur
     * (BY-021-R4). Kanıt: klasör türünde, çöpte değil, `ownedByMe` (kişisel
     * Drive; paylaşılan drive'da alan yoktur → kanıt yok), dosya eklenebilir
     * ve listelenebilir. `$kopyaKimligi` (hedefteki kayıtlı bir kopya)
     * verilirse o dosyanın da sahibi olmalı, çöpte olmamalı ve indirilebilmeli
     * — yeni bağlantının eski yedekleri okuyabildiği. Herhangi biri
     * tutmazsa ya da sorulamazsa (404, 5xx, bağlantı hatası)
     * `dogrulanan_klasor` null döner.
     *
     * @return array{yenileme_anahtari: string, eposta: string, dogrulanan_klasor: string|null}
     *
     * @throws HedefHatasi
     */
    public static function kodDegistir(string $kod, string $donusAdresi, ?string $klasorKimligi = null, ?string $kopyaKimligi = null): array
    {
        [$istemciKimligi, $istemciSirri] = self::istemciBilgileri();

        $yanit = self::tokenIstegi([
            'grant_type' => 'authorization_code',
            'code' => $kod,
            'redirect_uri' => $donusAdresi,
            'client_id' => $istemciKimligi,
            'client_secret' => $istemciSirri,
        ], yenidenDene: false);

        if (! $yanit->successful()) {
            throw match (self::tokenHatasi($yanit)) {
                'invalid_grant' => new HedefHatasi(HedefHatasi::KIMLIK, 'Google izin kodu geçersiz ya da süresi dolmuş; yeniden bağlanın.'),
                'invalid_client', 'unauthorized_client' => new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Google istemci kimliği ya da gizli anahtarı geçersiz.'),
                default => self::hata($yanit, HedefHatasi::KIMLIK, 'izin kodu değişimi'),
            };
        }

        $yenileme = $yanit->json('refresh_token');
        $erisim = $yanit->json('access_token');

        if (! is_string($yenileme) || $yenileme === '' || ! is_string($erisim) || $erisim === '') {
            throw new HedefHatasi(HedefHatasi::KIMLIK, 'Google kalıcı yenileme anahtarı vermedi; yeniden bağlanın.');
        }

        if (! in_array(self::KAPSAM, explode(' ', (string) $yanit->json('scope')), true)) {
            throw new HedefHatasi(HedefHatasi::KIMLIK, 'Google izninde Drive yetkisi verilmedi; izin ekranında Drive kutusu işaretlenmeli.');
        }

        $eposta = self::eposta($erisim)
            ?? throw new HedefHatasi(self::HESAP_OKUNAMADI, 'Bağlanan Google hesabının kimliği (e-posta) okunamadı; bağlantı kaydedilmedi. Yeniden deneyin.');

        return [
            'yenileme_anahtari' => $yenileme,
            'eposta' => $eposta,
            'dogrulanan_klasor' => $klasorKimligi !== null && self::klasorunSahibi($erisim, $klasorKimligi)
                && ($kopyaKimligi === null || self::kopyaninSahibi($erisim, $kopyaKimligi)) ? $klasorKimligi : null,
        ];
    }

    /** Kimlik doğrulaması ve seçilen yedek klasörünün kullanılabilirliği. */
    public function baglan(): void
    {
        $this->klasor();
    }

    public function yaz(string $yerelYol, string $ad): string
    {
        $this->adDenetle($ad);
        $boyut = is_file($yerelYol) && is_readable($yerelYol) ? @filesize($yerelYol) : false;

        if ($boyut === false) {
            throw new HedefHatasi(HedefHatasi::YAZILAMADI, 'Yüklenecek yerel dosya okunamadı.');
        }

        $ustBilgi = (string) json_encode(['name' => $ad, 'parents' => [$this->klasor()]]);
        $oturum = $this->istek('POST', self::YUKLEME_API, ['uploadType' => 'resumable', 'fields' => 'id'], fn (PendingRequest $istemci): PendingRequest => $istemci
            ->withHeaders(['X-Upload-Content-Type' => 'application/octet-stream', 'X-Upload-Content-Length' => (string) $boyut])
            ->withBody($ustBilgi, 'application/json; charset=UTF-8'));

        if (! $oturum->successful()) {
            throw self::hata($oturum, HedefHatasi::YAZILAMADI, 'yükleme oturumu');
        }

        $oturumAdresi = $this->oturumAdresi((string) $oturum->header('Location'));

        return $this->icerikGonder($oturumAdresi, $yerelYol, $boyut);
    }

    public function oku(string $uzakKimlik, string $yerelYol): void
    {
        $this->kimlikDenetle($uzakKimlik);
        $gecici = AtomikDosya::geciciAd($yerelYol);

        try {
            $yanit = $this->istek('GET', self::API.'/files/'.$uzakKimlik, ['alt' => 'media'], fn (PendingRequest $istemci): PendingRequest => $istemci->sink($gecici), dosyaTasir: true);

            if (! $yanit->successful()) {
                throw self::hata($yanit, HedefHatasi::OKUNAMADI, 'indirmesi');
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

    public function sil(string $uzakKimlik): void
    {
        $this->kimlikDenetle($uzakKimlik);
        $yanit = $this->istek('DELETE', self::API.'/files/'.$uzakKimlik);

        if (! $yanit->successful() && $yanit->status() !== 404) {
            throw self::hata($yanit, HedefHatasi::SILINEMEDI, 'silmesi');
        }
    }

    /** Yedek klasöründeki `erp_….dump|gpg` dosyalarının kimlikleri. */
    public function listele(): array
    {
        $sorgu = [
            'q' => "'{$this->klasor()}' in parents and trashed = false",
            'fields' => 'nextPageToken,files(id,name)',
            'pageSize' => '1000',
            'spaces' => 'drive',
        ];
        $kimlikler = [];

        for ($sayfa = 0; $sayfa < self::EN_COK_SAYFA; $sayfa++) {
            $yanit = $this->istek('GET', self::API.'/files', $sorgu);

            if (! $yanit->successful() || ! is_array($yanit->json('files'))) {
                throw self::hata($yanit, HedefHatasi::ERISILEMEDI, 'listelemesi');
            }

            foreach ($yanit->json('files') as $dosya) {
                $kimlik = is_array($dosya) ? ($dosya['id'] ?? null) : null;
                $ad = is_array($dosya) ? ($dosya['name'] ?? null) : null;

                if (is_string($kimlik) && preg_match(self::KIMLIK, $kimlik) === 1 && is_string($ad) && preg_match('/^erp_.+\.(dump|gpg)$/D', $ad) === 1) {
                    $kimlikler[] = $kimlik;
                }
            }

            $sonraki = $yanit->json('nextPageToken');

            if (! is_string($sonraki) || $sonraki === '') {
                return $kimlikler;
            }

            $sorgu['pageToken'] = $sonraki;
        }

        throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'Google Drive listesi sayfa sınırını aştı.');
    }

    /** Hesabın kalan depolama alanı; sınırsız hesapta (limit yok) null. */
    public function kalanAlan(): ?int
    {
        $yanit = $this->istek('GET', self::API.'/about', ['fields' => 'storageQuota']);

        if (! $yanit->successful()) {
            throw self::hata($yanit, HedefHatasi::ERISILEMEDI, 'kota sorgusu');
        }

        $sinir = $yanit->json('storageQuota.limit');
        $kullanim = $yanit->json('storageQuota.usage');

        if (! is_string($sinir) || ! ctype_digit($sinir)) {
            return null;
        }

        $kullanim = is_string($kullanim) && ctype_digit($kullanim) ? (int) $kullanim : 0;

        return max(0, (int) $sinir - $kullanim);
    }

    /**
     * İçerik isteği (oturum adresine). Yeniden denenmez; bağlantı koparsa ya
     * da 5xx gelirse oturumun durumu sorulur: yükleme aslında tamamlanmışsa
     * aynı dosyanın kimliği döner, ikinci kopya oluşmaz.
     */
    private function icerikGonder(string $oturumAdresi, string $yerelYol, int $boyut): string
    {
        try {
            // Akışla: dosya belleğe alınmaz
            $yanit = $this->istemci(true, yenidenDene: false)
                ->withBody(new LazyOpenStream($yerelYol, 'rb'), 'application/octet-stream')
                ->put($oturumAdresi);
        } catch (ConnectionException) {
            $yanit = null;
        }

        if ($yanit === null || $yanit->serverError()) {
            $yanit = $this->oturumDurumu($oturumAdresi, $boyut);
        }

        if ($yanit === null || ! $yanit->successful()) {
            throw $yanit === null
                ? new HedefHatasi(HedefHatasi::ERISILEMEDI, 'Google Drive yüklemesi tamamlanamadı (bağlantı koptu).')
                : self::hata($yanit, HedefHatasi::YAZILAMADI, 'yüklemesi');
        }

        $kimlik = $yanit->json('id');

        if (! is_string($kimlik) || preg_match(self::KIMLIK, $kimlik) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAZILAMADI, 'Google Drive yükleme yanıtında dosya kimliği yok.');
        }

        return $kimlik;
    }

    /** Resumable oturumun durumu: 200/201 tamamlandı, 308 eksik. */
    private function oturumDurumu(string $oturumAdresi, int $boyut): ?Response
    {
        try {
            return $this->istemci(false, yenidenDene: false)
                ->withHeaders(['Content-Range' => "bytes */{$boyut}"])
                ->withBody('', 'application/octet-stream')
                ->put($oturumAdresi);
        } catch (ConnectionException) {
            return null;
        }
    }

    /**
     * Oturum adresi yanıt başlığından gelir: yalnız Google'ın yükleme ucuna
     * gidilir, başka bir sunucuya dosya gönderilmez (G15).
     */
    private function oturumAdresi(string $adres): string
    {
        $parca = parse_url($adres);
        $gecerli = is_array($parca)
            && ($parca['scheme'] ?? '') === 'https'
            && ($parca['host'] ?? '') === 'www.googleapis.com'
            && ! isset($parca['port']) && ! isset($parca['user']) && ! isset($parca['pass'])
            && ($parca['path'] ?? '') === '/upload/drive/v3/files'
            && preg_match('/^[A-Za-z0-9=&_.%-]+$/D', $parca['query'] ?? '') === 1;

        if (! $gecerli) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'Google Drive yükleme oturumu beklenmeyen bir adres döndürdü.');
        }

        return $adres;
    }

    /**
     * Klasör seçme penceresi (Google Picker) için erişim anahtarı. Pencere
     * kullanıcının tarayıcısında açılır ve Google'a bu anahtarla bağlanır;
     * kapsamı `drive.file`, ömrü Google'ın verdiği kadardır (~1 saat).
     * Yenileme anahtarı tarayıcıya hiç gitmez.
     *
     * @throws HedefHatasi Bağlı değil, izin kaldırılmış (YENIDEN_BAGLAN) ya da yapılandırma eksik
     */
    public function seciciErisimi(): string
    {
        return $this->erisimAnahtari();
    }

    /**
     * Kullanıcının seçtiği klasörü doğrular: klasör türünde, çöpte değil,
     * hesabın kendi klasörü (`ownedByMe` — paylaşılmış klasör başka hesabın
     * deposudur, sahibi erişimi kaldırınca kopyalar kaybolur) ve içine
     * gerçekten yazılabiliyor. Yazma, küçük bir deneme dosyası açılıp
     * silinerek denenir: `capabilities.canAddChildren` alanına ya da Google'ın
     * `drive.file` + seçim penceresi davranışına güvenilmez (belgede açık değil).
     * Hiçbir şey kaydetmez; kaydı çağıran yapar.
     *
     * @return array{kimlik: string, ad: string}
     *
     * @throws HedefHatasi
     */
    public function klasorDogrula(string $kimlik): array
    {
        if (preg_match(self::KIMLIK, $kimlik) !== 1) {
            throw new HedefHatasi(HedefHatasi::KLASOR_GECERSIZ, 'Google Drive klasör kimliği biçimsiz.');
        }

        $yanit = $this->istek('GET', self::API.'/files/'.$kimlik, ['fields' => 'id,name,mimeType,trashed,ownedByMe,capabilities(canAddChildren)']);

        if ($yanit->status() === 404) {
            throw new HedefHatasi(HedefHatasi::KLASOR_GECERSIZ, 'Seçilen Google Drive klasörüne erişilemiyor; klasörü yeniden seçin.');
        }

        if (! $yanit->successful()) {
            throw self::hata($yanit, HedefHatasi::ERISILEMEDI, 'klasör sorgusu');
        }

        $ad = $yanit->json('name');

        if ($yanit->json('id') !== $kimlik || $yanit->json('mimeType') !== self::KLASOR_TURU || ! is_string($ad)) {
            throw new HedefHatasi(HedefHatasi::KLASOR_GECERSIZ, 'Seçilen öğe bir Google Drive klasörü değil.');
        }

        if ($yanit->json('trashed') !== false) {
            throw new HedefHatasi(HedefHatasi::KLASOR_GECERSIZ, 'Seçilen Google Drive klasörü çöp kutusunda.');
        }

        if ($yanit->json('ownedByMe') !== true) {
            throw new HedefHatasi(HedefHatasi::KLASOR_GECERSIZ, 'Seçilen klasör bağlı Google hesabının kendi klasörü değil (paylaşılmış klasör seçilemez).');
        }

        if ($yanit->json('capabilities.canAddChildren') === false) {
            throw new HedefHatasi(HedefHatasi::YAZILAMADI, 'Seçilen Google Drive klasörüne dosya eklenemiyor.');
        }

        $this->yazmaSina($kimlik);

        return ['kimlik' => $kimlik, 'ad' => $ad];
    }

    /**
     * Hesabın Drive'ının köküne yeni klasör açar (seçim penceresi yerine
     * "yeni klasör"). Dosya oluşturan istek: yeniden denenmez (yinelenen
     * klasör açılmasın). Yazma, seçilen klasörde olduğu gibi denenir.
     *
     * @return array{kimlik: string, ad: string}
     *
     * @throws HedefHatasi
     */
    public function klasorAc(string $ad): array
    {
        $ad = trim($ad);

        if ($ad === '' || mb_strlen($ad) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $ad) !== 0) {
            throw new HedefHatasi(HedefHatasi::KLASOR_GECERSIZ, 'Klasör adı boş, 100 karakterden uzun ya da denetim karakterli olamaz.');
        }

        $yanit = $this->istek('POST', self::API.'/files', ['fields' => 'id'], fn (PendingRequest $istemci): PendingRequest => $istemci
            ->withBody((string) json_encode(['name' => $ad, 'mimeType' => self::KLASOR_TURU]), 'application/json; charset=UTF-8'), yenidenDene: false);
        $kimlik = $yanit->successful() ? $yanit->json('id') : null;

        if (! is_string($kimlik) || preg_match(self::KIMLIK, $kimlik) !== 1) {
            throw $yanit->successful()
                ? new HedefHatasi(HedefHatasi::ERISILEMEDI, 'Google Drive klasör yanıtında kimlik yok.')
                : self::hata($yanit, HedefHatasi::YAZILAMADI, 'klasör açma');
        }

        $this->yazmaSina($kimlik);

        return ['kimlik' => $kimlik, 'ad' => $ad];
    }

    /**
     * Klasöre küçük bir deneme dosyası yazar ve siler. Ad `erp_` ile
     * başlamaz: silinemeyip kalsa bile listede yedek sayılmaz.
     *
     * @throws HedefHatasi
     */
    private function yazmaSina(string $klasor): void
    {
        $sinir = 'emor'.bin2hex(random_bytes(12));
        $govde = "--{$sinir}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
            .json_encode(['name' => '.emor-yazma-sinamasi-'.bin2hex(random_bytes(6)), 'parents' => [$klasor]])
            ."\r\n--{$sinir}\r\nContent-Type: text/plain\r\n\r\neMOR yazma sinamasi\r\n--{$sinir}--";

        $yanit = $this->istek('POST', self::YUKLEME_API, ['uploadType' => 'multipart', 'fields' => 'id'], fn (PendingRequest $istemci): PendingRequest => $istemci
            ->withBody($govde, 'multipart/related; boundary='.$sinir), yenidenDene: false);
        $kimlik = $yanit->successful() ? $yanit->json('id') : null;

        if (! is_string($kimlik) || preg_match(self::KIMLIK, $kimlik) !== 1) {
            $hata = $yanit->successful()
                ? new HedefHatasi(HedefHatasi::YAZILAMADI, 'Google Drive deneme dosyası yanıtında kimlik yok.')
                : self::hata($yanit, HedefHatasi::YAZILAMADI, 'klasöre yazma denemesi');

            // 403 / 404 burada kimlik değil klasör izni sorunudur: erişim anahtarı az önce klasörü okudu
            throw in_array($yanit->status(), [403, 404], true) && $hata->kod !== HedefHatasi::KOTA
                ? new HedefHatasi(HedefHatasi::YAZILAMADI, 'Google, uygulamanın seçilen klasöre dosya eklemesine izin vermedi (HTTP '.$yanit->status().').')
                : $hata;
        }

        $silme = $this->istek('DELETE', self::API.'/files/'.$kimlik);

        if (! $silme->successful() && $silme->status() !== 404) {
            throw self::hata($silme, HedefHatasi::SILINEMEDI, 'deneme dosyasının silinmesi');
        }
    }

    /**
     * Yedek klasörünün kimliği: kullanıcının seçtiği (ya da ekrandan açtırdığı)
     * klasör, `baglanti.klasor_kimligi`. Seçilmemişse ya da artık
     * kullanılamıyorsa (silinmiş, çöpte) hata: başka bir klasör kendiliğinden
     * aranmaz ya da açılmaz — kopyaların yeri sessizce değişmesin (G21).
     *
     * @throws HedefHatasi
     */
    private function klasor(): string
    {
        if ($this->klasorKimligi !== null) {
            return $this->klasorKimligi;
        }

        $kayitli = $this->hedef->baglanti['klasor_kimligi'] ?? null;

        if (! is_string($kayitli) || preg_match(self::KIMLIK, $kayitli) !== 1) {
            throw new HedefHatasi(HedefHatasi::KLASOR_SECILMEDI, 'Google Drive klasörü seçilmedi; listede "Klasör seç" ile seçin.');
        }

        $yanit = $this->istek('GET', self::API.'/files/'.$kayitli, ['fields' => 'id,mimeType,trashed']);

        if ($yanit->status() === 404) {
            throw new HedefHatasi(HedefHatasi::KLASOR_GECERSIZ, 'Seçilen Google Drive klasörü bulunamadı (silinmiş ya da erişim kaldırılmış); klasörü yeniden seçin.');
        }

        if (! $yanit->successful()) {
            throw self::hata($yanit, HedefHatasi::ERISILEMEDI, 'klasör sorgusu');
        }

        if ($yanit->json('mimeType') !== self::KLASOR_TURU || $yanit->json('trashed') !== false) {
            throw new HedefHatasi(HedefHatasi::KLASOR_GECERSIZ, 'Seçilen Google Drive klasörü çöp kutusunda ya da klasör değil; klasörü yeniden seçin.');
        }

        return $this->klasorKimligi = $kayitli;
    }

    /**
     * Erişim anahtarlı Drive isteği. 401 gelirse erişim anahtarı bir kez
     * yenilenip tekrarlanır; yine 401 ise izin kaldırılmıştır.
     *
     * @param  array<string, string>  $sorgu
     * @param  (callable(PendingRequest): PendingRequest)|null  $hazirla
     *
     * @throws HedefHatasi
     */
    private function istek(string $yontem, string $adres, array $sorgu = [], ?callable $hazirla = null, bool $yenidenDene = true, bool $dosyaTasir = false): Response
    {
        $tam = $adres.($sorgu === [] ? '' : '?'.http_build_query($sorgu, '', '&', PHP_QUERY_RFC3986));
        $gonder = function (string $erisim) use ($yontem, $tam, $hazirla, $yenidenDene, $dosyaTasir): Response {
            $istemci = $this->istemci($dosyaTasir, $yenidenDene)->withToken($erisim);
            $istemci = $hazirla === null ? $istemci : $hazirla($istemci);

            try {
                return $istemci->send($yontem, $tam);
            } catch (ConnectionException) {
                throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'Google Drive\'a bağlanılamadı ya da süre aşıldı.');
            }
        };

        $yanit = $gonder($this->erisimAnahtari());

        if ($yanit->status() === 401) {
            $this->erisimAnahtari = null;
            $yanit = $gonder($this->erisimAnahtari());
        }

        if ($yanit->status() === 401) {
            $this->yenidenBaglanIsaretle();

            throw new HedefHatasi(HedefHatasi::YENIDEN_BAGLAN, 'Google Drive bağlantısının izni kaldırılmış; hedefi yeniden bağlayın.');
        }

        return $yanit;
    }

    /** Yenileme anahtarıyla erişim anahtarı (yalnız bu nesnede tutulur). */
    private function erisimAnahtari(): string
    {
        if ($this->erisimAnahtari !== null) {
            return $this->erisimAnahtari;
        }

        $yenileme = $this->yenilemeAnahtari();
        [$istemciKimligi, $istemciSirri] = self::istemciBilgileri();

        $yanit = self::tokenIstegi([
            'grant_type' => 'refresh_token',
            'refresh_token' => $yenileme,
            'client_id' => $istemciKimligi,
            'client_secret' => $istemciSirri,
        ], yenidenDene: true);

        $erisim = $yanit->successful() ? $yanit->json('access_token') : null;

        if (is_string($erisim) && $erisim !== '') {
            return $this->erisimAnahtari = $erisim;
        }

        $tokenHatasi = $yanit->successful() ? null : self::tokenHatasi($yanit);

        if ($tokenHatasi === 'invalid_grant') {
            $this->yenidenBaglanIsaretle();

            throw new HedefHatasi(HedefHatasi::YENIDEN_BAGLAN, 'Google Drive bağlantısının izni kaldırılmış ya da süresi dolmuş; hedefi yeniden bağlayın.');
        }

        if (in_array($tokenHatasi, ['invalid_client', 'unauthorized_client'], true)) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Google istemci kimliği ya da gizli anahtarı geçersiz.');
        }

        throw $yanit->successful()
            ? new HedefHatasi(HedefHatasi::KIMLIK, 'Google erişim anahtarı vermedi.')
            : self::hata($yanit, HedefHatasi::KIMLIK, 'erişim anahtarı yenilemesi');
    }

    private function yenilemeAnahtari(): string
    {
        $yenileme = $this->hedef->gizli_bilgiler['yenileme_anahtari'] ?? null;

        if (! is_string($yenileme) || $yenileme === '') {
            throw new HedefHatasi(HedefHatasi::YENIDEN_BAGLAN, 'Google hesabı bağlı değil; "Google ile bağlan" ile bağlayın.');
        }

        return $yenileme;
    }

    /**
     * Hedefi "yeniden bağlanın" durumuna alır — yalnız reddedilen anahtar hâlâ
     * kayıtlıysa: bu arada yeniden bağlanmış hedef eski anahtarın reddiyle
     * bozuk işaretlenmez.
     */
    private function yenidenBaglanIsaretle(): void
    {
        $this->baglantiyiGuncelle(['durum' => self::DURUM_YENIDEN_BAGLAN], $this->yenilemeAnahtari());
    }

    /**
     * `baglanti`'ya alan yazar. Kayıt veritabanından taze okunup yalnız bu
     * alanlar değiştirilir: bellekteki modelin kaydedilmemiş değişiklikleri
     * (ör. formdaki değerlerle sınama) kalıcı olmaz. Kaydedilmemiş hedefte
     * yalnız bellekteki model değişir.
     *
     * @param  array<string, string>  $degisiklik
     */
    private function baglantiyiGuncelle(array $degisiklik, ?string $yenilemeKosulu = null): void
    {
        $this->hedef->baglanti = array_merge($this->hedef->baglanti ?? [], $degisiklik);

        if (! $this->hedef->exists) {
            return;
        }

        $taze = YedekHedefi::query()->find($this->hedef->getKey());
        $kayitliYenileme = $taze?->gizli_bilgiler['yenileme_anahtari'] ?? null;

        if ($taze === null || ($yenilemeKosulu !== null && (! is_string($kayitliYenileme) || ! hash_equals($kayitliYenileme, $yenilemeKosulu)))) {
            return;
        }

        $taze->baglanti = array_merge($taze->baglanti ?? [], $degisiklik);
        $taze->save();
    }

    private function istemci(bool $dosyaTasir, bool $yenidenDene): PendingRequest
    {
        return self::istemciKur($this->yapilandirma, $dosyaTasir, $yenidenDene);
    }

    /**
     * Bağlantı ve toplam süre sınırı her istekte (D1). Yeniden deneme yalnız
     * bağlantı hatası, 5xx ve 429'da; 4xx kalıcıdır (D8). Yönlendirme izlenmez.
     * Sınamada (etkin süre bütçesi) sınırlar ortak son zamana kısalır, deneme
     * ve önündeki bekleme sığmıyorsa yapılmaz (BY-016-R4).
     */
    private static function istemciKur(YedeklemeYapilandirmasi $yapilandirma, bool $dosyaTasir, bool $yenidenDene): PendingRequest
    {
        $kopyaSuresi = $yapilandirma->sureSiniri('kopya');
        $baglanti = $yapilandirma->sureSiniri('baglanti');
        $zamanAsimi = $dosyaTasir ? $kopyaSuresi : min(self::KISA_ISTEK_SURESI, $kopyaSuresi);
        $istemci = Http::connectTimeout($baglanti)->timeout($zamanAsimi)->withoutRedirecting();

        if (! $yenidenDene) {
            return $istemci;
        }

        return $istemci->retry(
            self::YENIDEN_DENEME_MS,
            0,
            fn (?Throwable $hata, PendingRequest $istek): bool => ($hata instanceof ConnectionException
                || ($hata instanceof RequestException
                    && ($hata->response->serverError() || $hata->response->status() === 429)))
                && self::denemeyiSinirla($istek, $baglanti, $zamanAsimi),
            throw: false,
        );
    }

    /** Sonraki deneme etkin süre bütçesine sığıyorsa süre sınırlarını kalan süreye kısaltır */
    private static function denemeyiSinirla(PendingRequest $istek, int $baglanti, int $zamanAsimi): bool
    {
        $sinir = SureButcesi::denemeSiniri($zamanAsimi, max(self::YENIDEN_DENEME_MS));
        if ($sinir === null) {
            return false;
        }

        $istek->connectTimeout(min($baglanti, $sinir))->timeout($sinir);

        return true;
    }

    /**
     * @param  array<string, string>  $form
     */
    private static function tokenIstegi(array $form, bool $yenidenDene): Response
    {
        try {
            return self::istemciKur(app(YedeklemeYapilandirmasi::class), false, $yenidenDene)
                ->asForm()
                ->post(self::TOKEN_ADRESI, $form);
        } catch (ConnectionException) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'Google kimlik sunucusuna bağlanılamadı ya da süre aşıldı.');
        }
    }

    /** Bağlı hesabın e-postası (`drive.file` ile `about.user`); okunamazsa null. */
    private static function eposta(string $erisim): ?string
    {
        try {
            $yanit = self::istemciKur(app(YedeklemeYapilandirmasi::class), false, true)
                ->withToken($erisim)
                ->get(self::API.'/about', ['fields' => 'user(emailAddress)']);
        } catch (ConnectionException) {
            return null;
        }

        $eposta = $yanit->successful() ? $yanit->json('user.emailAddress') : null;

        return is_string($eposta) && filter_var($eposta, FILTER_VALIDATE_EMAIL) !== false ? $eposta : null;
    }

    /** Erişim anahtarının hesabı bu klasörün sahibi mi ve klasör kullanılabilir mi; her hata "doğrulanamadı" (false). */
    private static function klasorunSahibi(string $erisim, string $kimlik): bool
    {
        $bilgi = self::dosyaBilgisi($erisim, $kimlik, 'id,mimeType,trashed,ownedByMe,capabilities(canAddChildren,canListChildren)');

        return $bilgi !== null
            && ($bilgi['mimeType'] ?? null) === self::KLASOR_TURU
            && ($bilgi['capabilities']['canAddChildren'] ?? null) === true
            && ($bilgi['capabilities']['canListChildren'] ?? null) === true;
    }

    /** Erişim anahtarının hesabı bu kopyanın sahibi mi ve onu indirebiliyor mu; her hata "doğrulanamadı" (false). */
    private static function kopyaninSahibi(string $erisim, string $kimlik): bool
    {
        $bilgi = self::dosyaBilgisi($erisim, $kimlik, 'id,trashed,ownedByMe,capabilities(canDownload)');

        return $bilgi !== null && ($bilgi['capabilities']['canDownload'] ?? null) === true;
    }

    /**
     * `files.get` yanıtı — yalnız kimlik aynıysa, dosya çöpte değilse
     * (`trashed` açıkça false) ve hesap sahibiyse (`ownedByMe` açıkça true);
     * aksi ya da her hata null.
     *
     * @return array<string, mixed>|null
     */
    private static function dosyaBilgisi(string $erisim, string $kimlik, string $alanlar): ?array
    {
        if (preg_match(self::KIMLIK, $kimlik) !== 1) {
            return null;
        }

        try {
            $yanit = self::istemciKur(app(YedeklemeYapilandirmasi::class), false, true)
                ->withToken($erisim)
                ->get(self::API.'/files/'.$kimlik, ['fields' => $alanlar]);
        } catch (ConnectionException) {
            return null;
        }

        $bilgi = $yanit->successful() ? $yanit->json() : null;

        return is_array($bilgi) && ($bilgi['id'] ?? null) === $kimlik && ($bilgi['trashed'] ?? null) === false && ($bilgi['ownedByMe'] ?? null) === true
            ? $bilgi
            : null;
    }

    /**
     * @return array{0: string, 1: string} [istemci kimliği, istemci sırrı]
     *
     * @throws HedefHatasi
     */
    private static function istemciBilgileri(): array
    {
        $kimlik = config('yedekleme.google.istemci_kimligi');
        $sir = config('yedekleme.google.istemci_sirri');

        if (! is_string($kimlik) || $kimlik === '' || ! is_string($sir) || $sir === '') {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Google istemci kimliği / gizli anahtarı tanımlı değil (YEDEKLEME_GOOGLE_ISTEMCI_*).');
        }

        return [$kimlik, $sir];
    }

    /** OAuth hata kodu; yalnız bilinen değerler (serbest metin alınmaz). */
    private static function tokenHatasi(Response $yanit): ?string
    {
        $hata = $yanit->json('error');

        return in_array($hata, ['invalid_grant', 'invalid_client', 'unauthorized_client', 'invalid_request', 'invalid_scope'], true) ? $hata : null;
    }

    /**
     * Ham gövde mesaja girmez; yalnız HTTP durumu ve doğrulanmış kısa neden
     * kodu (D2). Kota dolu → KOTA.
     */
    private static function hata(Response $yanit, string $varsayilanKod, string $islem): HedefHatasi
    {
        $durum = $yanit->status();
        $neden = $yanit->json('error.errors.0.reason');
        $neden = is_string($neden) && preg_match('/^[A-Za-z]{1,40}$/D', $neden) === 1 ? $neden : null;
        $ek = "HTTP {$durum}".($neden === null ? '' : ", {$neden}");

        if ($neden === 'storageQuotaExceeded') {
            return new HedefHatasi(HedefHatasi::KOTA, "Google Drive depolama kotası dolu; {$islem} yapılamadı ({$ek}).");
        }

        $kod = match (true) {
            $durum >= 500 || $durum === 429 || in_array($neden, ['rateLimitExceeded', 'userRateLimitExceeded'], true) => HedefHatasi::ERISILEMEDI,
            $durum === 403 => HedefHatasi::KIMLIK,
            default => $varsayilanKod,
        };

        return new HedefHatasi($kod, "Google Drive {$islem} başarısız ({$ek}).");
    }

    /** Yalnız düz dosya adı (YerelDiskBaglayicisi ile aynı kural) */
    private function adDenetle(string $ad): void
    {
        if (preg_match('/^[A-Za-z0-9.][A-Za-z0-9._+-]{0,200}$/D', $ad) !== 1 || str_contains($ad, '..')) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz hedef dosya adı.');
        }
    }

    private function kimlikDenetle(string $kimlik): void
    {
        if (preg_match(self::KIMLIK, $kimlik) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz Google Drive dosya kimliği.');
        }
    }
}
