<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\AtomikDosya;
use App\Services\Yedekleme\HedefTuru;
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
 * oluşturduğu dosyaları görür, hesabın diğer dosyalarını listeleyemez. Bağlı
 * hesabın e-postası da bu yetkiyle `about.user`'dan okunur; `openid email`
 * istenmez.
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

    public const VARSAYILAN_KLASOR_ADI = 'eMOR Yedekleri';

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
        return new HedefTuruTanimi(HedefTuru::GoogleDrive, [
            new HedefAlani('klasor_adi', 'yedekleme.hedef.alan.klasor_adi', kurallar: ['nullable', 'string', 'max:100', 'regex:/^[^\x00-\x1F\x7F]+$/Du'], varsayilan: self::VARSAYILAN_KLASOR_ADI),
        ], sunucuDisi: true, oauth: true);
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
     * E-posta `about.user`'dan okunur; okunamazsa null (bağlantı yine kurulur).
     *
     * @return array{yenileme_anahtari: string, eposta: string|null}
     *
     * @throws HedefHatasi
     */
    public static function kodDegistir(string $kod, string $donusAdresi): array
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

        return ['yenileme_anahtari' => $yenileme, 'eposta' => self::eposta($erisim)];
    }

    /** Kimlik doğrulaması ve yedek klasörü (yoksa açılır, kimliği kaydedilir). */
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
     * Yedek klasörünün kimliği. Kayıtlı kimlik geçerliyse o; değilse (ilk
     * bağlantı, klasör silinmiş) uygulamanın daha önce açtığı aynı adlı klasör
     * aranır, yoksa açılır. Kimlik `baglanti.klasor_kimligi`'ne yazılır.
     */
    private function klasor(): string
    {
        if ($this->klasorKimligi !== null) {
            return $this->klasorKimligi;
        }

        $kayitli = $this->hedef->baglanti['klasor_kimligi'] ?? null;

        if (is_string($kayitli) && preg_match(self::KIMLIK, $kayitli) === 1 && $this->klasorKullanilabilir($kayitli)) {
            return $this->klasorKimligi = $kayitli;
        }

        $kimlik = $this->klasorBul() ?? $this->klasorAc();
        $this->baglantiyiGuncelle(['klasor_kimligi' => $kimlik]);

        return $this->klasorKimligi = $kimlik;
    }

    private function klasorKullanilabilir(string $kimlik): bool
    {
        $yanit = $this->istek('GET', self::API.'/files/'.$kimlik, ['fields' => 'id,mimeType,trashed']);

        if ($yanit->status() === 404) {
            return false;
        }

        if (! $yanit->successful()) {
            throw self::hata($yanit, HedefHatasi::ERISILEMEDI, 'klasör sorgusu');
        }

        return $yanit->json('mimeType') === self::KLASOR_TURU && $yanit->json('trashed') !== true;
    }

    private function klasorBul(): ?string
    {
        $ad = str_replace(['\\', "'"], ['\\\\', "\\'"], $this->klasorAdi());
        $yanit = $this->istek('GET', self::API.'/files', [
            'q' => "mimeType = '".self::KLASOR_TURU."' and name = '{$ad}' and trashed = false",
            'fields' => 'files(id)',
            'pageSize' => '10',
            'spaces' => 'drive',
        ]);

        if (! $yanit->successful()) {
            throw self::hata($yanit, HedefHatasi::ERISILEMEDI, 'klasör araması');
        }

        $kimlik = $yanit->json('files.0.id');

        return is_string($kimlik) && preg_match(self::KIMLIK, $kimlik) === 1 ? $kimlik : null;
    }

    /** Dosya oluşturan istek: yeniden denenmez (yinelenen klasör açılmasın). */
    private function klasorAc(): string
    {
        $yanit = $this->istek('POST', self::API.'/files', ['fields' => 'id'], fn (PendingRequest $istemci): PendingRequest => $istemci
            ->withBody((string) json_encode(['name' => $this->klasorAdi(), 'mimeType' => self::KLASOR_TURU]), 'application/json; charset=UTF-8'), yenidenDene: false);
        $kimlik = $yanit->successful() ? $yanit->json('id') : null;

        if (! is_string($kimlik) || preg_match(self::KIMLIK, $kimlik) !== 1) {
            throw $yanit->successful()
                ? new HedefHatasi(HedefHatasi::ERISILEMEDI, 'Google Drive klasör yanıtında kimlik yok.')
                : self::hata($yanit, HedefHatasi::YAZILAMADI, 'klasör açma');
        }

        return $kimlik;
    }

    private function klasorAdi(): string
    {
        $ad = $this->hedef->baglanti['klasor_adi'] ?? null;

        return is_string($ad) && trim($ad) !== '' ? trim($ad) : self::VARSAYILAN_KLASOR_ADI;
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
     */
    private static function istemciKur(YedeklemeYapilandirmasi $yapilandirma, bool $dosyaTasir, bool $yenidenDene): PendingRequest
    {
        $kopyaSuresi = $yapilandirma->sureSiniri('kopya');
        $istemci = Http::connectTimeout($yapilandirma->sureSiniri('baglanti'))
            ->timeout($dosyaTasir ? $kopyaSuresi : min(self::KISA_ISTEK_SURESI, $kopyaSuresi))
            ->withoutRedirecting();

        if (! $yenidenDene) {
            return $istemci;
        }

        return $istemci->retry(
            self::YENIDEN_DENEME_MS,
            0,
            fn (?Throwable $hata): bool => $hata instanceof ConnectionException
                || ($hata instanceof RequestException
                    && ($hata->response->serverError() || $hata->response->status() === 429)),
            throw: false,
        );
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
