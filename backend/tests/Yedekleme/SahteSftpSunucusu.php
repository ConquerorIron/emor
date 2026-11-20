<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeoutException;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Process::fake için sahte `sftp`: `-b` ile verilen toplu komut dosyasını
 * yerel bir klasör üzerinde uygular (cd, pwd, put, get, rename, rm, ls -1,
 * df). Her çağrının komutunu, ortamını, süre sınırını ve geçici klasördeki
 * dosyaları (izin + içerik) çağrı anında kaydeder: sırların yalnız 0600
 * dosyalarda durduğu ve işlem sonrası silindiği bununla sınanır.
 *
 * Ssh katmanını (anahtar doğrulama, kimlik) taklit etmez; o yollar için
 * `zorunluHata` ile ssh'in hata çıktısı verilir.
 */
final class SahteSftpSunucusu
{
    /**
     * @var list<array{komut: list<string>, ortam: array<string, mixed>, zaman: mixed, toplu: list<string>, klasor: string, klasorIzni: int, dosyalar: array<string, array{izin: int, icerik: string}>, askpass: string|null}>
     */
    public array $cagrilar = [];

    /** Bu komutta bağlantı kopar (put: yarım dosya kalır) */
    public ?string $kopacakKomut = null;

    /** Bu komut sunucu tarafından reddedilir */
    public ?string $reddedilecekKomut = null;

    /** Bu komut takılır: süre sınırı aşılır */
    public ?string $takilacakKomut = null;

    /** @var array{0: string, 1: int}|null her çağrıda dönen [ssh hata çıktısı, çıkış kodu] */
    public ?array $zorunluHata = null;

    public function __construct(public readonly string $kok) {}

    public function isle(PendingProcess $surec): FakeProcessResult|ProcessTimedOutException
    {
        $komut = array_values(array_map('strval', (array) $surec->command));
        $toplu = $komut[(int) array_search('-b', $komut, true) + 1];
        $klasor = dirname($toplu);
        $dosyalar = [];
        foreach (array_diff(scandir($klasor) ?: [], ['.', '..']) as $ad) {
            $dosyalar[$ad] = ['izin' => fileperms($klasor.'/'.$ad) & 0777, 'icerik' => (string) file_get_contents($klasor.'/'.$ad)];
        }
        $askpass = $surec->environment['SSH_ASKPASS'] ?? null;
        $satirlar = file($toplu, FILE_IGNORE_NEW_LINES) ?: [];

        $this->cagrilar[] = [
            'komut' => $komut,
            'ortam' => $surec->environment,
            'zaman' => $surec->timeout,
            'toplu' => $satirlar,
            'klasor' => $klasor,
            'klasorIzni' => fileperms($klasor) & 0777,
            'dosyalar' => $dosyalar,
            // Betik gerçekten çalıştırılır: ssh'in alacağı parola budur
            'askpass' => is_string($askpass) ? (string) shell_exec(escapeshellarg($askpass).' "Password:"') : null,
        ];

        if ($this->zorunluHata !== null) {
            return new FakeProcessResult(errorOutput: $this->zorunluHata[0], exitCode: $this->zorunluHata[1]);
        }

        return $this->uygula($satirlar);
    }

    /**
     * @param  list<string>  $satirlar
     */
    private function uygula(array $satirlar): FakeProcessResult|ProcessTimedOutException
    {
        $cwd = $this->kok;
        $cikti = '';
        // Ok fonksiyonu $cwd'yi tanımlandığı andaki değeriyle yakalardı (A2)
        $yol = function (string $uzak) use (&$cwd): string {
            return str_starts_with($uzak, '/') ? $this->kok.$uzak : $cwd.'/'.$uzak;
        };

        foreach ($satirlar as $satir) {
            preg_match_all('/"([^"]*)"|(\S+)/', ltrim($satir, '@-'), $eslesmeler, PREG_SET_ORDER);
            $arg = array_map(fn (array $e): string => ($e[2] ?? '') !== '' ? $e[2] : $e[1], $eslesmeler);
            $ad = array_shift($arg);

            if ($ad === $this->takilacakKomut) {
                return new ProcessTimedOutException(
                    new SymfonyTimeoutException(new SymfonyProcess(['sftp']), SymfonyTimeoutException::TYPE_GENERAL),
                    new FakeProcessResult,
                );
            }

            if ($ad === $this->reddedilecekKomut) {
                return new FakeProcessResult(output: $cikti, errorOutput: "Couldn't {$ad}: Permission denied\n", exitCode: 1);
            }

            switch ($ad) {
                case 'cd':
                    if (! is_dir($yol($arg[0]))) {
                        return new FakeProcessResult(output: $cikti, errorOutput: "Can't change directory: No such file or directory\n", exitCode: 1);
                    }
                    $cwd = $yol($arg[0]);
                    break;
                case 'pwd':
                    $cikti .= "Remote working directory: /\n";
                    break;
                case 'put':
                    if ($ad === $this->kopacakKomut) {
                        $icerik = (string) file_get_contents($arg[0]);
                        file_put_contents($yol($arg[1]), substr($icerik, 0, intdiv(strlen($icerik), 2)));

                        return new FakeProcessResult(output: $cikti, errorOutput: "client_loop: send disconnect: Broken pipe\nConnection closed\n", exitCode: 255);
                    }
                    copy($arg[0], $yol($arg[1]));
                    break;
                case 'get':
                    if (! is_file($yol($arg[0]))) {
                        return new FakeProcessResult(output: $cikti, errorOutput: "File \"{$arg[0]}\" not found.\n", exitCode: 1);
                    }
                    copy($yol($arg[0]), $arg[1]);
                    break;
                case 'rename':
                    rename($yol($arg[0]), $yol($arg[1]));
                    break;
                case 'rm':
                    if (! is_file($yol($arg[0]))) {
                        return new FakeProcessResult(output: $cikti, errorOutput: "Couldn't delete file: No such file or directory\n", exitCode: 1);
                    }
                    unlink($yol($arg[0]));
                    break;
                case 'ls':
                    foreach (array_diff(scandir($cwd) ?: [], ['.', '..']) as $dosya) {
                        if (! str_starts_with($dosya, '.')) {
                            $cikti .= $dosya."\n";
                        }
                    }
                    break;
                case 'df':
                    $cikti .= "        Size         Used        Avail       (root)    %Capacity\n"
                        ."   102687672     10520848     86907380     86907380          10%\n";
                    break;
                default:
                    return new FakeProcessResult(output: $cikti, errorOutput: "Invalid command.\n", exitCode: 1);
            }
        }

        return new FakeProcessResult(output: $cikti);
    }
}
