<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;

/**
 * FTP ve FTPS hedefi (README §4.6, BY-020). Ek paket yok: PHP'nin curl
 * eklentisi kullanılır. `ftp_ssl_connect` sertifikayı doğrulamaz; FTPS'te
 * curl sertifikayı ve alan adını doğrular, şifreli bağlantı kurulamazsa
 * kullanıcı adı / parola hiç gönderilmez (CURLUSESSL_ALL: düz FTP'ye düşmez).
 *
 * FTPS "açık" kiptedir (AUTH TLS, aynı port); örtük kip (990) desteklenmez.
 * Her istek ayrı bağlantıdır ve bağlanma + toplam süre sınırı taşır (D1).
 * Hata mesajı parolayı ya da sunucunun yanıtını taşımaz; yalnız curl hata
 * numarası (D2).
 */
final class FtpBaglayicisi implements HedefBaglayicisi
{
    /** Alan adı ya da IPv4: yalnız harf, rakam, nokta, tire; tireyle başlamaz (G15) */
    public const SUNUCU_DESENI = '/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/D';

    public const KULLANICI_DESENI = '/^[A-Za-z0-9._@+][A-Za-z0-9._@+-]{0,127}$/D';

    /**
     * Uzak klasör: boş (giriş klasörü), "/" ya da noktayla başlamayan
     * parçalar — ".." ile klasörün dışına çıkılamaz.
     */
    public const KLASOR_DESENI = '#^(/|/?[A-Za-z0-9_+][A-Za-z0-9._+-]*(/[A-Za-z0-9_+][A-Za-z0-9._+-]*)*/?)$#D';

    /** Sır içinde satır sonu / NUL: FTP komutuna ek komut sızdırır */
    public const SIR_DESENI = '/^[^\x00\r\n]{1,1024}$/D';

    private const CURLE_COULDNT_RESOLVE_HOST = 6;

    private const CURLE_COULDNT_CONNECT = 7;

    private const CURLE_REMOTE_ACCESS_DENIED = 9;

    private const CURLE_QUOTE_ERROR = 21;

    private const CURLE_OPERATION_TIMEDOUT = 28;

    private const CURLE_USE_SSL_FAILED = 64;

    private const CURLE_LOGIN_DENIED = 67;

    private const CURLE_REMOTE_DISK_FULL = 70;

    private const CURLE_REMOTE_FILE_NOT_FOUND = 78;

    /** Sertifika doğrulanamadı / sertifika dosyası / yayıncı / sabitlenmiş anahtar / durum */
    private const SERTIFIKA_HATALARI = [58, 60, 77, 83, 90, 91];

    public function __construct(
        private readonly YedekHedefi $hedef,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    public static function tanim(HedefTuru $tur): HedefTuruTanimi
    {
        return new HedefTuruTanimi($tur, [
            new HedefAlani('sunucu', 'yedekleme.hedef.alan.sunucu', zorunlu: true, kurallar: ['nullable', 'string', 'max:253', 'regex:'.self::SUNUCU_DESENI]),
            new HedefAlani('port', 'yedekleme.hedef.alan.port', HedefAlani::SAYI, kurallar: ['nullable', 'integer', 'between:1,65535'], varsayilan: 21),
            new HedefAlani('kullanici_adi', 'yedekleme.hedef.alan.kullanici_adi', zorunlu: true, kurallar: ['nullable', 'string', 'max:128', 'regex:'.self::KULLANICI_DESENI]),
            new HedefAlani('sifre', 'yedekleme.hedef.alan.sifre', HedefAlani::PAROLA, zorunlu: true, gizli: true, kurallar: ['nullable', 'string', 'regex:'.self::SIR_DESENI]),
            new HedefAlani('klasor', 'yedekleme.hedef.alan.klasor', kurallar: ['nullable', 'string', 'max:500', 'regex:'.self::KLASOR_DESENI]),
            new HedefAlani('pasif', 'yedekleme.hedef.alan.pasif', HedefAlani::EVET_HAYIR, varsayilan: true),
        ], sunucuDisi: true, uyari: $tur === HedefTuru::Ftp ? 'yedekleme.hedef.uyari.duz_ftp' : null);
    }

    public function baglan(): void
    {
        $no = $this->istek($this->adres(), [CURLOPT_NOBODY => true], aktarim: false)[1];

        if ($no !== 0) {
            throw $this->hata($no, HedefHatasi::ERISILEMEDI, 'bağlantı');
        }
    }

    public function yaz(string $yerelYol, string $ad): string
    {
        $this->adDenetle($ad);
        $gecici = '.'.$ad.'.'.bin2hex(random_bytes(6)).'.tmp';
        $giris = @fopen($yerelYol, 'rb');

        if ($giris === false) {
            throw new HedefHatasi(HedefHatasi::YAZILAMADI, 'Yüklenecek yerel dosya açılamadı.');
        }

        try {
            $no = $this->istek($this->adres($gecici), [
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $giris,
                CURLOPT_INFILESIZE => (int) filesize($yerelYol),
                // Yükleme bittikten sonra, aynı oturumda ve aynı klasörde
                CURLOPT_POSTQUOTE => ["RNFR {$gecici}", "RNTO {$ad}"],
            ], aktarim: true)[1];
        } finally {
            fclose($giris);
        }

        if ($no !== 0) {
            $hata = $this->hata($no, HedefHatasi::YAZILAMADI, 'yazma');

            // Kimlik / sertifika reddinde yükleme başlamadı; ikinci giriş denemesi hesabı kilitletebilir (D8)
            if (in_array($hata->kod, [HedefHatasi::YAZILAMADI, HedefHatasi::KOTA], true)) {
                $this->geciciyiSil($gecici);
            }

            throw $hata;
        }

        return $ad;
    }

    public function oku(string $uzakKimlik, string $yerelYol): void
    {
        $this->adDenetle($uzakKimlik);
        $cikis = @fopen($yerelYol, 'wb');

        if ($cikis === false) {
            throw new HedefHatasi(HedefHatasi::OKUNAMADI, 'İndirilecek yerel dosya açılamadı.');
        }

        try {
            $no = $this->istek($this->adres($uzakKimlik), [CURLOPT_FILE => $cikis], aktarim: true)[1];
        } finally {
            fclose($cikis);
        }

        if ($no !== 0) {
            @unlink($yerelYol);

            throw $this->hata($no, HedefHatasi::OKUNAMADI, 'okuma');
        }
    }

    public function sil(string $uzakKimlik): void
    {
        $this->adDenetle($uzakKimlik);
        $no = $this->dele($uzakKimlik);

        // DELE reddi dosyanın zaten olmamasından olabilir: yoksa silinmiş sayılır
        if ($no === 0 || ($no === self::CURLE_QUOTE_ERROR && ! in_array($uzakKimlik, $this->adlar(), true))) {
            return;
        }

        throw $this->hata($no, HedefHatasi::SILINEMEDI, 'silme');
    }

    public function listele(): array
    {
        return array_values(array_filter($this->adlar(), fn (string $ad): bool => preg_match('/^erp_.+\.(dump|gpg)$/D', $ad) === 1));
    }

    /** FTP kalan alanı bildirmez. */
    public function kalanAlan(): ?int
    {
        return null;
    }

    /**
     * Klasördeki dosya adları (NLST).
     *
     * @return list<string>
     */
    private function adlar(): array
    {
        [$cikti, $no] = $this->istek($this->adres(), [CURLOPT_DIRLISTONLY => true, CURLOPT_RETURNTRANSFER => true], aktarim: false);

        if ($no !== 0 || ! is_string($cikti)) {
            throw $this->hata($no, HedefHatasi::ERISILEMEDI, 'listeleme');
        }

        $adlar = [];
        foreach (preg_split('/\r?\n/', $cikti) ?: [] as $satir) {
            $ad = basename(trim($satir));
            if ($ad !== '' && $ad !== '.' && $ad !== '..') {
                $adlar[] = $ad;
            }
        }

        return $adlar;
    }

    /** Yarım kalan geçici dosyayı temizler; bağlantı koptuysa yapılacak bir şey yok. */
    private function geciciyiSil(string $gecici): void
    {
        $this->dele($gecici);
    }

    /**
     * DELE, klasöre girmeyen bir oturumda tam yolla gönderilir: QUOTE
     * komutları CWD'den önce, giriş klasöründe çalışır.
     */
    private function dele(string $ad): int
    {
        return $this->istek($this->kokAdres(), [CURLOPT_NOBODY => true, CURLOPT_QUOTE => ['DELE '.$this->uzakYol($ad)]], aktarim: false)[1];
    }

    /**
     * @param  array<int, mixed>  $secenekler
     * @return array{0: string|bool, 1: int} [curl_exec sonucu, curl hata numarası]
     */
    private function istek(string $adres, array $secenekler, bool $aktarim): array
    {
        $baglanti = $this->yapilandirma->sureSiniri('baglanti');
        $ortak = [
            CURLOPT_URL => $adres,
            CURLOPT_PROTOCOLS_STR => 'ftp',
            CURLOPT_USERNAME => $this->kullanici(),
            CURLOPT_PASSWORD => $this->sifre(),
            CURLOPT_CONNECTTIMEOUT => $baglanti,
            // Aktarım dışı komutlar kısa sürer; asılı sunucu kilidi tutmasın (D1, D9)
            CURLOPT_TIMEOUT => $aktarim ? $this->yapilandirma->sureSiniri('kopya') : $baglanti * 3,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_FRESH_CONNECT => true,
            // Pasif kipte sunucunun bildirdiği adres yok sayılır (FTP sıçratma)
            CURLOPT_FTP_SKIP_PASV_IP => true,
            CURLOPT_USE_SSL => $this->hedef->tur === HedefTuru::Ftps ? CURLUSESSL_ALL : CURLUSESSL_NONE,
            CURLOPT_FTPSSLAUTH => CURLFTPAUTH_TLS,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if (! $this->pasif()) {
            $ortak[CURLOPT_FTPPORT] = '-';
        }

        $curl = curl_init();

        if ($curl === false || ! curl_setopt_array($curl, array_replace($ortak, $secenekler))) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'FTP isteği hazırlanamadı (curl seçeneği desteklenmiyor).');
        }

        $sonuc = curl_exec($curl);

        return [$sonuc, curl_errno($curl)];
    }

    private function hata(int $no, string $varsayilanKod, string $islem): HedefHatasi
    {
        [$kod, $neden] = match (true) {
            $no === self::CURLE_LOGIN_DENIED => [HedefHatasi::KIMLIK, 'kullanıcı adı ya da parola kabul edilmedi'],
            in_array($no, self::SERTIFIKA_HATALARI, true) => [HedefHatasi::SERTIFIKA, 'sunucunun sertifikası doğrulanamadı'],
            $no === self::CURLE_USE_SSL_FAILED => [HedefHatasi::YAPILANDIRMA, 'sunucu şifreli bağlantıyı (AUTH TLS) desteklemiyor'],
            $no === self::CURLE_REMOTE_DISK_FULL => [HedefHatasi::KOTA, 'sunucuda yer kalmadı'],
            $no === self::CURLE_OPERATION_TIMEDOUT => [$varsayilanKod, 'zaman aşımı'],
            in_array($no, [self::CURLE_COULDNT_RESOLVE_HOST, self::CURLE_COULDNT_CONNECT], true) => [$varsayilanKod, 'sunucuya bağlanılamadı'],
            $no === self::CURLE_REMOTE_ACCESS_DENIED => [$varsayilanKod, 'klasöre erişilemedi'],
            $no === self::CURLE_REMOTE_FILE_NOT_FOUND => [$varsayilanKod, 'dosya bulunamadı'],
            default => [$varsayilanKod, 'işlem başarısız'],
        };

        return new HedefHatasi($kod, "{$this->etiket()} {$islem}: {$neden} (curl {$no}).");
    }

    private function etiket(): string
    {
        return $this->hedef->tur === HedefTuru::Ftps ? 'FTPS' : 'FTP';
    }

    /** Klasörün adresi (sonda "/") ya da klasördeki bir dosyanın adresi */
    private function adres(string $ad = ''): string
    {
        $klasor = $this->klasor();
        $yol = '';

        if ($klasor !== '') {
            $parcalar = array_values(array_filter(explode('/', $klasor), fn (string $p): bool => $p !== ''));
            // Mutlak klasör: ilk parça %2F → curl önce "CWD /" yapar
            $yol = (str_starts_with($klasor, '/') ? '%2F/' : '').implode('/', array_map('rawurlencode', $parcalar));
            $yol = rtrim($yol, '/').'/';
        }

        return $this->kokAdres().$yol.rawurlencode($ad);
    }

    private function kokAdres(): string
    {
        return 'ftp://'.$this->sunucu().':'.$this->port().'/';
    }

    /** Giriş klasörüne göre dosya yolu (QUOTE komutları için) */
    private function uzakYol(string $ad): string
    {
        $klasor = $this->klasor();

        return match ($klasor) {
            '' => $ad,
            '/' => '/'.$ad,
            default => $klasor.'/'.$ad,
        };
    }

    private function sunucu(): string
    {
        $sunucu = $this->hedef->baglanti['sunucu'] ?? null;

        if (! is_string($sunucu) || preg_match(self::SUNUCU_DESENI, $sunucu) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz sunucu adı.');
        }

        return $sunucu;
    }

    private function port(): int
    {
        $port = $this->hedef->baglanti['port'] ?? null;

        if ($port === null || $port === '') {
            return 21;
        }

        if (is_string($port) && preg_match('/^[1-9][0-9]{0,4}$/D', $port) === 1) {
            $port = (int) $port;
        }

        if (! is_int($port) || $port < 1 || $port > 65535) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz port.');
        }

        return $port;
    }

    private function kullanici(): string
    {
        $kullanici = $this->hedef->baglanti['kullanici_adi'] ?? null;

        if (! is_string($kullanici) || preg_match(self::KULLANICI_DESENI, $kullanici) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz kullanıcı adı.');
        }

        return $kullanici;
    }

    private function sifre(): string
    {
        $sifre = $this->hedef->gizli_bilgiler['sifre'] ?? null;

        if (! is_string($sifre) || preg_match(self::SIR_DESENI, $sifre) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Parola tanımlı değil ya da geçersiz karakter içeriyor.');
        }

        return $sifre;
    }

    /** Boş / tanımsız: pasif (varsayılan). Tanınmayan değer pasif sayılır (G9). */
    private function pasif(): bool
    {
        $pasif = $this->hedef->baglanti['pasif'] ?? null;

        return $pasif === null || filter_var($pasif, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /** Normalleştirilmiş klasör: "" (giriş klasörü), "/" ya da sonda "/" olmayan yol */
    private function klasor(): string
    {
        $klasor = $this->hedef->baglanti['klasor'] ?? '';

        if ($klasor === null || $klasor === '') {
            return '';
        }

        if (! is_string($klasor) || strlen($klasor) > 500 || preg_match(self::KLASOR_DESENI, $klasor) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz hedef klasörü.');
        }

        return $klasor === '/' ? '/' : rtrim($klasor, '/');
    }

    /** Yalnız düz dosya adı: hedef klasörünün dışına çıkılamaz */
    private function adDenetle(string $ad): void
    {
        if (preg_match('/^[A-Za-z0-9.][A-Za-z0-9._+-]{0,200}$/D', $ad) !== 1 || str_contains($ad, '..')) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz hedef dosya adı.');
        }
    }
}
