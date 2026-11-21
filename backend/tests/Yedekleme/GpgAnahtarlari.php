<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Gerçek gpg ile testler için atılacak anahtar çiftleri (BY-019). Anahtarlar
 * sınıf başına bir kez, kendi geçici anahtarlığında üretilir (ed25519 /
 * cv25519: hızlı); sınıf bitince ajan kapatılır, klasör silinir. gpg yoksa
 * testler nedeniyle atlanır (Dikkat_Edilecekler T11).
 *
 * Anahtarlar: `a` ve `b` parolasız (b = "yanlış anahtar"), `p` parolalı,
 * `eski` süresi dolmuş (üretim anı 2020'ye kaydırılarak).
 */
trait GpgAnahtarlari
{
    public const TEST_PAROLASI = 'deneme parolası 1';

    protected static ?string $anahtarEvi = null;

    /** @var array<string, array{parmak_izi: string, acik: string, ozel: string}> */
    protected static array $anahtarlar = [];

    protected function gpgAnahtarlariniHazirla(): void
    {
        if (! is_executable('/usr/bin/gpg') || ! is_executable('/usr/bin/gpgconf')) {
            $this->markTestSkipped('gpg kurulu değil (/usr/bin/gpg); gerçek şifreleme testleri atlandı.');
        }

        if (self::$anahtarEvi !== null) {
            return;
        }

        $ev = sys_get_temp_dir().'/emor-gpg-test-'.bin2hex(random_bytes(5));
        mkdir($ev, 0700);
        self::$anahtarEvi = $ev;
        file_put_contents($ev.'/parola', self::TEST_PAROLASI."\n");
        chmod($ev.'/parola', 0600);

        $tanimlar = [
            'a' => [['--passphrase', ''], 'never', []],
            'b' => [['--passphrase', ''], 'never', []],
            'p' => [['--passphrase-file', $ev.'/parola'], 'never', []],
            'eski' => [['--passphrase', ''], '1d', ['--faked-system-time', '20200101T000000']],
        ];

        foreach ($tanimlar as $ad => [$parola, $sure, $ek]) {
            $kullanici = "Deneme {$ad} <{$ad}@example.invalid>";
            self::gpgTest([...$ek, '--pinentry-mode', 'loopback', ...$parola, '--quick-gen-key', $kullanici, 'future-default', 'default', $sure]);
            $liste = self::gpgTest(['--with-colons', '--list-keys', "={$kullanici}"]);
            preg_match('/^fpr:+([0-9A-F]{40,64}):/m', $liste, $parca);
            self::$anahtarlar[$ad] = [
                'parmak_izi' => $parca[1],
                'acik' => self::gpgTest(['--armor', '--export', $parca[1]]),
                'ozel' => self::gpgTest(['--pinentry-mode', 'loopback', ...$parola, '--armor', '--export-secret-keys', $parca[1]]),
            ];
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$anahtarEvi !== null) {
            (new Process(['/usr/bin/gpgconf', '--homedir', self::$anahtarEvi, '--kill', 'all']))->run();
            (new Process(['/usr/bin/gpgconf', '--homedir', self::$anahtarEvi, '--remove-socketdir']))->run();
            File::deleteDirectory(self::$anahtarEvi);
            self::$anahtarEvi = null;
            self::$anahtarlar = [];
        }

        parent::tearDownAfterClass();
    }

    /** Özel anahtarı dosyaya yazar (geri yüklemede operatörün verdiği dosya gibi) */
    protected function ozelAnahtarDosyasi(string $ad, string $klasor): string
    {
        $yol = $klasor.'/ozel-'.$ad.'.asc';
        file_put_contents($yol, self::$anahtarlar[$ad]['ozel']);
        chmod($yol, 0600);

        return $yol;
    }

    /**
     * @param  list<string>  $argumanlar
     */
    private static function gpgTest(array $argumanlar): string
    {
        $surec = new Process(['/usr/bin/gpg', '--homedir', self::$anahtarEvi, '--batch', '--no-tty', ...$argumanlar], env: ['GNUPGHOME' => false], timeout: 60);
        $surec->mustRun();

        return $surec->getOutput();
    }
}
