<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\GeciciKlasor;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\SureButcesi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

/**
 * SFTP hedefi (README §4.6, BY-022). Ek paket yok: sunucudaki OpenSSH
 * `sftp` ve `ssh-keyscan` araçları argüman dizisiyle çalıştırılır (kabuk yok).
 *
 * Sunucu anahtarı: ilk "Bağlantıyı Sına"da (baglan) ssh-keyscan ile alınır,
 * anahtar ve SHA256 parmak izi `baglanti`'ya yazılır. Sonraki her bağlantıda
 * yalnız bu anahtar geçici bir known_hosts dosyasından kabul edilir
 * (StrictHostKeyChecking=yes); anahtar değişirse bağlantı reddedilir. Kayıtlı
 * anahtar yokken diğer işlemler bağlanmaz: gece kopyası gözden geçirilmemiş
 * bir anahtarı kendiliğinden kaydetmez.
 *
 * Sırlar (parola, özel anahtar) komut satırına ya da ortam değişkenine
 * girmez (D2): 0700 geçici klasörde 0600 dosyalara yazılır ve her işlemden
 * sonra silinir. Parola SSH_ASKPASS betiğiyle dosyadan okunur.
 */
final class SftpBaglayicisi implements HedefBaglayicisi
{
    /** Anahtar türleri, tercih sırasıyla */
    private const ANAHTAR_TURLERI = ['ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-rsa'];

    /** Port boşken kullanılan (tanımın varsayılanı da bu: konum karşılaştırması onu okur — HedefKonumu) */
    public const VARSAYILAN_PORT = 22;

    public function __construct(
        private readonly YedekHedefi $hedef,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    public static function tanim(HedefTuru $tur = HedefTuru::Sftp): HedefTuruTanimi
    {
        return new HedefTuruTanimi(HedefTuru::Sftp, [
            new HedefAlani('sunucu', 'yedekleme.hedef.alan.sunucu', zorunlu: true, kurallar: ['nullable', 'string', 'max:253', 'regex:'.FtpBaglayicisi::SUNUCU_DESENI]),
            new HedefAlani('port', 'yedekleme.hedef.alan.port', HedefAlani::SAYI, kurallar: ['nullable', 'integer', 'between:1,65535'], varsayilan: self::VARSAYILAN_PORT),
            new HedefAlani('kullanici_adi', 'yedekleme.hedef.alan.kullanici_adi', zorunlu: true, kurallar: ['nullable', 'string', 'max:128', 'regex:'.FtpBaglayicisi::KULLANICI_DESENI]),
            new HedefAlani('klasor', 'yedekleme.hedef.alan.klasor', kurallar: ['nullable', 'string', 'max:500', 'regex:'.FtpBaglayicisi::KLASOR_DESENI]),
            new HedefAlani('ozel_anahtar', 'yedekleme.hedef.alan.ozel_anahtar', HedefAlani::UZUN_METIN, gizli: true, kurallar: ['nullable', 'string', 'max:16384']),
            new HedefAlani('sifre', 'yedekleme.hedef.alan.sifre', HedefAlani::PAROLA, gizli: true, kurallar: ['nullable', 'string', 'regex:'.FtpBaglayicisi::SIR_DESENI]),
        ], sunucuDisi: true);
    }

    public function baglan(): void
    {
        // Tanım, sunucuya giden ilk istekten (ssh-keyscan) önce doğrulanır: kullanılamaz tanıma anahtar kaydedilmez (G15)
        $this->tanimiDogrula();

        if (! $this->anahtarKayitli()) {
            $this->sunucuAnahtariniKaydet();
        }

        $this->calistir(['@pwd'], HedefHatasi::ERISILEMEDI, 'bağlantı', aktarim: false);
    }

    public function yaz(string $yerelYol, string $ad): string
    {
        $this->adDenetle($ad);
        $yerel = $this->yerelYolDenetle($yerelYol);
        $gecici = '.'.$ad.'.'.bin2hex(random_bytes(6)).'.tmp';

        try {
            $this->calistir(["@put \"{$yerel}\" \"{$gecici}\"", "@rename \"{$gecici}\" \"{$ad}\""], HedefHatasi::YAZILAMADI, 'yazma', aktarim: true);
        } catch (HedefHatasi $hata) {
            // Kimlik / parmak izi reddinde yükleme başlamadı; ikinci giriş denemesi hesabı kilitletebilir (D8)
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
        $yerel = $this->yerelYolDenetle($yerelYol);

        try {
            $this->calistir(["@get \"{$uzakKimlik}\" \"{$yerel}\""], HedefHatasi::OKUNAMADI, 'okuma', aktarim: true);
        } catch (HedefHatasi $hata) {
            @unlink($yerelYol);

            throw $hata;
        }
    }

    public function sil(string $uzakKimlik): void
    {
        $this->adDenetle($uzakKimlik);

        try {
            $this->calistir(["@rm \"{$uzakKimlik}\""], HedefHatasi::SILINEMEDI, 'silme', aktarim: false);
        } catch (HedefHatasi $hata) {
            // Silme reddi dosyanın zaten olmamasından olabilir: yoksa silinmiş sayılır
            if ($hata->kod !== HedefHatasi::SILINEMEDI || in_array($uzakKimlik, $this->adlar(), true)) {
                throw $hata;
            }
        }
    }

    public function listele(): array
    {
        return array_values(array_filter($this->adlar(), fn (string $ad): bool => preg_match('/^erp_.+\.(dump|gpg)$/D', $ad) === 1));
    }

    /** Sunucu statvfs uzantısını desteklemiyorsa ya da okunamazsa null. */
    public function kalanAlan(): ?int
    {
        try {
            $cikti = $this->calistir(['@df'], HedefHatasi::ERISILEMEDI, 'alan sorgusu', aktarim: false)->output();
        } catch (HedefHatasi) {
            return null;
        }

        // Size Used Avail (root) %Capacity — 1 KiB birimiyle
        foreach (explode("\n", $cikti) as $satir) {
            if (preg_match('/^\s*\d+\s+\d+\s+(\d+)\s+\d+\s+\d+%\s*$/D', $satir, $eslesme) === 1) {
                return (int) $eslesme[1] * 1024;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function adlar(): array
    {
        $cikti = $this->calistir(['@ls -1'], HedefHatasi::ERISILEMEDI, 'listeleme', aktarim: false)->output();
        $adlar = [];

        foreach (explode("\n", $cikti) as $satir) {
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
        try {
            $this->calistir(["@rm \"{$gecici}\""], HedefHatasi::SILINEMEDI, 'temizlik', aktarim: false);
        } catch (HedefHatasi) {
            // Asıl hata yukarıda bildirilir
        }
    }

    /**
     * sftp'yi toplu komut dosyasıyla çalıştırır (`-b`: ilk hatada durur).
     *
     * @param  list<string>  $komutlar  klasöre geçildikten sonra çalışacak sftp komutları
     */
    private function calistir(array $komutlar, string $varsayilanKod, string $islem, bool $aktarim): ProcessResult
    {
        [$sunucu, $port, $kullanici, $klasor, $anahtar, $sifre] = $this->tanimiDogrula();

        if (! $this->anahtarKayitli()) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Sunucunun parmak izi kayıtlı değil; önce "Bağlantıyı Sına" çalıştırılmalı.');
        }

        // Sınamada süre sınırları ortak son zamana kısalır, son zaman geçtiyse süreç başlamaz (SureButcesi)
        $baglanti = $this->yapilandirma->sureSiniri('baglanti');
        $sure = $aktarim ? $this->yapilandirma->sureSiniri('kopya') : SureButcesi::sinirla($baglanti * 3);
        $calisma = $this->geciciKlasor();

        try {
            $bilinen = $calisma.'/known_hosts';
            $sunucuAdi = $port === 22 ? $sunucu : "[{$sunucu}]:{$port}";
            $this->dosyaYaz($bilinen, $sunucuAdi.' '.$this->hedef->baglanti['sunucu_anahtari']."\n", 0600);

            $toplu = $calisma.'/komutlar';
            $this->dosyaYaz($toplu, implode("\n", [...($klasor === '' ? [] : ["@cd \"{$klasor}\""]), ...$komutlar])."\n", 0600);

            $arguman = [
                $this->yapilandirma->arac('sftp'),
                '-F', '/dev/null',
                '-P', (string) $port,
                '-o', 'ConnectTimeout='.$baglanti,
                '-o', 'ServerAliveInterval=15',
                '-o', 'ServerAliveCountMax=3',
                '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile='.$bilinen,
                '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'UpdateHostKeys=no',
                '-o', 'User='.$kullanici,
                '-o', 'NumberOfPasswordPrompts=1',
                '-o', 'IdentitiesOnly=yes',
                '-o', 'PubkeyAuthentication='.($anahtar === null ? 'no' : 'yes'),
                '-o', 'PasswordAuthentication='.($sifre === null ? 'no' : 'yes'),
                '-o', 'KbdInteractiveAuthentication='.($sifre === null ? 'no' : 'yes'),
                // sftp -b kendisi BatchMode=yes ekler; ssh ilk değeri kullanır, bu yüzden önce
                '-o', 'BatchMode='.($sifre === null ? 'yes' : 'no'),
            ];
            $ortam = ['SSH_AUTH_SOCK' => false, 'SSH_ASKPASS' => false, 'SSH_ASKPASS_REQUIRE' => false, 'DISPLAY' => false];

            if ($anahtar !== null) {
                $anahtarYolu = $calisma.'/anahtar';
                $this->dosyaYaz($anahtarYolu, $anahtar, 0600);
                array_push($arguman, '-i', $anahtarYolu);
            }

            if ($sifre !== null) {
                $parolaYolu = $calisma.'/parola';
                $this->dosyaYaz($parolaYolu, $sifre, 0600);
                $betik = $calisma.'/askpass';
                $this->dosyaYaz($betik, "#!/bin/sh\nexec /bin/cat ".escapeshellarg($parolaYolu)."\n", 0700);
                $ortam = ['SSH_ASKPASS' => $betik, 'SSH_ASKPASS_REQUIRE' => 'force', 'DISPLAY' => ':0'] + $ortam;
            }

            array_push($arguman, '-b', $toplu, $sunucu);

            $sonuc = Process::timeout($sure)->env($ortam)->run($arguman);
        } catch (ProcessTimedOutException) {
            throw new HedefHatasi($varsayilanKod, "SFTP {$islem}: zaman aşımı ({$sure} sn).");
        } finally {
            $this->klasoruSil($calisma);
        }

        if (! $sonuc->successful()) {
            throw $this->hata($sonuc, $varsayilanKod, $islem);
        }

        return $sonuc;
    }

    /**
     * sftp / ssh hata çıktısını koda çevirir. Çıktının kendisi mesaja
     * girmez: sunucu bildirimi (banner) ya da ssh ayrıntısı taşıyabilir (D2).
     */
    private function hata(ProcessResult $sonuc, string $varsayilanKod, string $islem): HedefHatasi
    {
        $cikti = strtolower($sonuc->errorOutput());
        $icerir = static function (array $ifadeler) use ($cikti): bool {
            foreach ($ifadeler as $ifade) {
                if (str_contains($cikti, $ifade)) {
                    return true;
                }
            }

            return false;
        };

        [$kod, $neden] = match (true) {
            $icerir(['host key verification failed', 'remote host identification has changed', 'host key for', 'host key is known']) => [
                HedefHatasi::SUNUCU_PARMAK_IZI,
                'sunucu anahtarı kayıtlı parmak iziyle ('.$this->hedef->baglanti['sunucu_parmak_izi'].') uyuşmuyor; sunucu değişmiş ya da araya giren biri olabilir',
            ],
            $icerir(['permission denied (', 'too many authentication failures', 'load key', 'invalid format']) => [HedefHatasi::KIMLIK, 'kimlik doğrulanamadı'],
            $icerir(['no space left', 'quota exceeded']) => [HedefHatasi::KOTA, 'sunucuda yer kalmadı'],
            $icerir(['could not resolve hostname', 'connection refused', 'connection timed out', 'no route to host', 'network is unreachable', 'connection closed', 'connection reset', 'operation timed out']) => [$varsayilanKod, 'sunucuya bağlanılamadı'],
            default => [$varsayilanKod, 'işlem başarısız'],
        };

        return new HedefHatasi($kod, "SFTP {$islem}: {$neden} (çıkış {$sonuc->exitCode()}).");
    }

    /**
     * Bağlantı tanımının bütün alanları; geçersiz alan ya da kimlik bilgisi
     * yoksa hiçbir süreç başlamadan YAPILANDIRMA.
     *
     * @return array{0: string, 1: int, 2: string, 3: string, 4: string|null, 5: string|null} [sunucu, port, kullanıcı, klasör, özel anahtar, parola]
     */
    private function tanimiDogrula(): array
    {
        $tanim = [$this->sunucu(), $this->port(), $this->kullanici(), $this->klasor(), $this->ozelAnahtar(), $this->sifre()];

        if ($tanim[4] === null && $tanim[5] === null) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'SFTP için özel anahtar ya da parola tanımlanmalı.');
        }

        return $tanim;
    }

    private function anahtarKayitli(): bool
    {
        $b = $this->hedef->baglanti;

        return is_string($b['sunucu_parmak_izi'] ?? null) && $b['sunucu_parmak_izi'] !== ''
            && is_string($b['sunucu_anahtari'] ?? null) && $this->anahtarCoz($b['sunucu_anahtari']) !== null
            && ($b['sunucu_anahtari_icin'] ?? null) === $this->sunucu().':'.$this->port();
    }

    /**
     * İlk bağlantı: sunucunun anahtarını alır ve kaydeder (ilk kullanımda
     * güven). Birden çok tür dönerse tercih sırasındaki ilki saklanır.
     */
    private function sunucuAnahtariniKaydet(): void
    {
        $sunucu = $this->sunucu();
        $port = $this->port();
        $baglanti = $this->yapilandirma->sureSiniri('baglanti');

        try {
            $sonuc = Process::timeout(SureButcesi::sinirla($baglanti * 2 + 5))
                ->env(['SSH_AUTH_SOCK' => false])
                ->run([$this->yapilandirma->arac('ssh_keyscan'), '-T', (string) $baglanti, '-p', (string) $port, '-t', 'ed25519,ecdsa,rsa', $sunucu]);
        } catch (ProcessTimedOutException) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'SFTP sunucu anahtarı alınamadı: zaman aşımı.');
        }

        $bulunan = [];
        foreach (explode("\n", $sonuc->output()) as $satir) {
            $parca = preg_split('/\s+/', trim($satir));
            if ($parca === false || count($parca) < 3 || str_starts_with($parca[0], '#')) {
                continue;
            }
            $anahtar = $parca[1].' '.$parca[2];
            if ($this->anahtarCoz($anahtar) !== null) {
                $bulunan[$parca[1]] = $anahtar;
            }
        }

        $secilen = null;
        foreach (self::ANAHTAR_TURLERI as $tur) {
            if (isset($bulunan[$tur])) {
                $secilen = $bulunan[$tur];
                break;
            }
        }

        if ($secilen === null) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'SFTP sunucu anahtarı alınamadı.');
        }

        $anahtar = [
            'sunucu_anahtari' => $secilen,
            'sunucu_parmak_izi' => self::parmakIzi((string) $this->anahtarCoz($secilen)),
            'sunucu_anahtari_icin' => $sunucu.':'.$port,
        ];
        $this->hedef->baglanti = [...$this->hedef->baglanti, ...$anahtar];

        // Kaydedilmemiş hedef (formda sınama) yeni satır olarak eklenmez; anahtar modelde kalır.
        // Kayıtlı hedefte satır kilidi altında taze kayda yalnız bu üç anahtar yazılır: araya giren
        // güncellemenin değişikliği eski kopyayla ezilmez (YF-55, G18)
        if ($this->hedef->exists) {
            DB::transaction(function () use ($anahtar): void {
                $taze = YedekHedefi::query()->whereKey($this->hedef->getKey())->lockForUpdate()->first();
                if ($taze !== null) {
                    $taze->baglanti = [...($taze->baglanti ?? []), ...$anahtar];
                    $taze->save();
                }
            });
        }
    }

    /**
     * "tür base64" anahtarının ham baytları; tür izinli değilse ya da ham
     * baytların içindeki tür adı uyuşmuyorsa null.
     */
    private function anahtarCoz(string $anahtar): ?string
    {
        if (preg_match('#^([a-z0-9-]+) ([A-Za-z0-9+/]+={0,2})$#D', $anahtar, $eslesme) !== 1 || ! in_array($eslesme[1], self::ANAHTAR_TURLERI, true)) {
            return null;
        }

        $ham = base64_decode($eslesme[2], true);
        if ($ham === false || strlen($ham) < 4) {
            return null;
        }

        $uzunluk = unpack('N', substr($ham, 0, 4))[1];

        return substr($ham, 4, $uzunluk) === $eslesme[1] ? $ham : null;
    }

    /** OpenSSH biçiminde SHA256 parmak izi (ssh-keygen -l ile aynı) */
    private static function parmakIzi(string $ham): string
    {
        return 'SHA256:'.rtrim(base64_encode(hash('sha256', $ham, true)), '=');
    }

    private function sunucu(): string
    {
        $sunucu = $this->hedef->baglanti['sunucu'] ?? null;

        if (! is_string($sunucu) || preg_match(FtpBaglayicisi::SUNUCU_DESENI, $sunucu) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz sunucu adı.');
        }

        return $sunucu;
    }

    private function port(): int
    {
        $port = $this->hedef->baglanti['port'] ?? null;

        if ($port === null || $port === '') {
            return self::VARSAYILAN_PORT;
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

        if (! is_string($kullanici) || preg_match(FtpBaglayicisi::KULLANICI_DESENI, $kullanici) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz kullanıcı adı.');
        }

        return $kullanici;
    }

    private function sifre(): ?string
    {
        $sifre = $this->hedef->gizli_bilgiler['sifre'] ?? null;

        if ($sifre === null || $sifre === '') {
            return null;
        }

        if (! is_string($sifre) || preg_match(FtpBaglayicisi::SIR_DESENI, $sifre) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Parola geçersiz karakter içeriyor.');
        }

        return $sifre;
    }

    /**
     * OpenSSH anahtar dosyası LF satır sonu ve sonda satır sonu ister.
     * Parolalı (şifreli) anahtar desteklenmez: ssh, çözme parolasını soracak
     * bir yer bulamaz ve yalnız genel "kimlik doğrulanamadı" der. Sunucuya hiç
     * gidilmeden açık YAPILANDIRMA hatası verilir.
     */
    private function ozelAnahtar(): ?string
    {
        $anahtar = $this->hedef->gizli_bilgiler['ozel_anahtar'] ?? null;

        if (! is_string($anahtar) || trim($anahtar) === '') {
            return null;
        }

        $anahtar = trim(str_replace("\r\n", "\n", $anahtar))."\n";

        if (self::sifreliAnahtarMi($anahtar)) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Parolalı (şifreli) özel anahtar desteklenmiyor; parolasız bir anahtar ya da parolayla giriş kullanın.');
        }

        return $anahtar;
    }

    /**
     * PEM'de `Proc-Type: 4,ENCRYPTED` / `BEGIN ENCRYPTED PRIVATE KEY`;
     * OpenSSH biçiminde şifre adı `none` değilse şifrelidir
     * (`openssh-key-v1\0` + uint32 uzunluk + şifre adı). Ayrıştırılamayan
     * anahtar burada reddedilmez; kararı ssh verir.
     */
    private static function sifreliAnahtarMi(string $anahtar): bool
    {
        if (str_contains($anahtar, 'Proc-Type: 4,ENCRYPTED') || str_contains($anahtar, '-----BEGIN ENCRYPTED PRIVATE KEY-----')) {
            return true;
        }

        if (preg_match('/-----BEGIN OPENSSH PRIVATE KEY-----\n(.+?)\n-----END OPENSSH PRIVATE KEY-----/s', $anahtar, $parca) !== 1) {
            return false;
        }

        $ham = base64_decode(str_replace(["\n", ' '], '', $parca[1]), true);
        $baslik = "openssh-key-v1\0";

        if (! is_string($ham) || ! str_starts_with($ham, $baslik) || strlen($ham) < strlen($baslik) + 4) {
            return false;
        }

        $uzunluk = unpack('N', substr($ham, strlen($baslik), 4))[1];
        $sifre = substr($ham, strlen($baslik) + 4, $uzunluk);

        return $sifre !== '' && $sifre !== 'none';
    }

    /** Normalleştirilmiş klasör: "" (giriş klasörü), "/" ya da sonda "/" olmayan yol */
    private function klasor(): string
    {
        $klasor = $this->hedef->baglanti['klasor'] ?? '';

        if ($klasor === null || $klasor === '') {
            return '';
        }

        if (! is_string($klasor) || strlen($klasor) > 500 || preg_match(FtpBaglayicisi::KLASOR_DESENI, $klasor) !== 1) {
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

    /**
     * Yerel yol toplu komut dosyasına tırnak içinde yazılır: tırnak, ters
     * eğik çizgi, joker (sftp yerel yolda glob yapar) ve boşluk kabul edilmez.
     */
    private function yerelYolDenetle(string $yol): string
    {
        if (preg_match('#^/[A-Za-z0-9._+/-]{1,1000}$#D', $yol) !== 1 || str_contains($yol, '/../')) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz yerel dosya yolu.');
        }

        return $yol;
    }

    /**
     * Sırların yazıldığı geçici klasör (0700); yol ssh seçeneğine boşluksuz
     * girer. Önce, öldürülen bir süreçten (SIGKILL, kuyruk zaman aşımı) kalan
     * eski klasörler silinir: sır dosyaları `finally`'de silinir ama süreç
     * öldürülünce o adım çalışmaz.
     */
    private function geciciKlasor(): string
    {
        // Hiçbir işlemin kullanıyor olamayacağı kadar eski (en uzun aktarım süresi + 1 saat) klasörler
        GeciciKlasor::artiklariSil(sys_get_temp_dir(), 'emor-sftp-', 16, $this->yapilandirma->sureSiniri('kopya') + 3600);
        $yol = rtrim(sys_get_temp_dir(), '/').'/emor-sftp-'.bin2hex(random_bytes(8));

        if (preg_match('#^/[A-Za-z0-9._/-]+$#D', $yol) !== 1 || ! @mkdir($yol, 0700) || ! chmod($yol, 0700)) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'SFTP için geçici klasör açılamadı.');
        }

        return $yol;
    }

    /** İzin, içerik yazılmadan önce verilir */
    private function dosyaYaz(string $yol, string $icerik, int $izin): void
    {
        $tutamac = @fopen($yol, 'xb');

        if ($tutamac === false) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'SFTP için geçici dosya açılamadı.');
        }

        try {
            if (! chmod($yol, $izin) || fwrite($tutamac, $icerik) !== strlen($icerik)) {
                throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'SFTP için geçici dosya yazılamadı.');
            }
        } finally {
            fclose($tutamac);
        }
    }

    private function klasoruSil(string $klasor): void
    {
        foreach (array_diff(@scandir($klasor) ?: [], ['.', '..']) as $dosya) {
            @unlink($klasor.'/'.$dosya);
        }
        @rmdir($klasor);
    }
}
