<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

use RuntimeException;
use Throwable;

/**
 * Testlerin yerel FTP sunucusu: ayrı bir PHP sürecinde 127.0.0.1'de dinler,
 * dosyaları verilen kök klasörde tutar. FtpBaglayicisi'nin GERÇEK curl
 * çağrılarını (geçici ada yükle → RNFR/RNTO, NLST, RETR, DELE, pasif ve
 * aktif kip) uçtan uca sınamak içindir; gerçek bir sunucuyla yapılacak
 * denemenin yerini tutmaz. AUTH TLS yalnız el sıkışmaya kadar uygulanır
 * (sertifika reddini görmek için).
 *
 * Seçenekler:
 *  - kullanici, sifre: kabul edilen giriş
 *  - gunluk: alınan komutların yazıldığı dosya
 *  - stor_kopar: ilk STOR'da bu kadar bayt okunduktan sonra bağlantı koparılır
 *  - stor_asili: STOR'da veri okunmaz, yanıt verilmez (aktarım ortasında asılı sunucu)
 *  - rnto_reddet: RNTO her zaman reddedilir
 *  - tls: AUTH TLS'te kullanılacak sertifika + anahtar (PEM) dosyası; yoksa AUTH reddedilir
 */
final class SahteFtpSunucusu
{
    /**
     * @param  resource  $surec
     * @param  array<int, resource>  $borular
     */
    private function __construct(public readonly int $port, private $surec, private array $borular) {}

    /**
     * @param  array<string, mixed>  $secenekler
     */
    public static function baslat(string $kok, array $secenekler): self
    {
        $kod = 'require $argv[1]; \Tests\Yedekleme\SahteFtpSunucusu::dinle($argv[2], json_decode($argv[3], true, 512, JSON_THROW_ON_ERROR));';
        $surec = proc_open(
            [PHP_BINARY, '-r', $kod, base_path('vendor/autoload.php'), $kok, json_encode($secenekler, JSON_THROW_ON_ERROR)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $borular,
        );

        if (! is_resource($surec)) {
            throw new RuntimeException('Sahte FTP sunucusu başlatılamadı.');
        }

        $oku = [$borular[1]];
        $yok = null;
        $port = stream_select($oku, $yok, $yok, 10) === 1 ? (int) fgets($borular[1]) : 0;

        if ($port < 1) {
            proc_terminate($surec, 9);
            throw new RuntimeException('Sahte FTP sunucusu portunu bildirmedi.');
        }

        return new self($port, $surec, $borular);
    }

    public function durdur(): void
    {
        proc_terminate($this->surec, 9);
        foreach ($this->borular as $boru) {
            @fclose($boru);
        }
        proc_close($this->surec);
    }

    /**
     * Alt süreçte çalışır: bağlantıları sırayla kabul eder (tek istemci).
     *
     * @param  array<string, mixed>  $secenekler
     */
    public static function dinle(string $kok, array $secenekler): void
    {
        $sunucu = stream_socket_server('tcp://127.0.0.1:0', $no, $mesaj);
        if ($sunucu === false) {
            exit(1);
        }

        $ad = (string) stream_socket_get_name($sunucu, false);
        fwrite(STDOUT, substr($ad, (int) strrpos($ad, ':') + 1)."\n");
        fflush(STDOUT);
        $durum = ['stor_koptu' => false];

        while (true) {
            $baglanti = @stream_socket_accept($sunucu, 3600);
            if ($baglanti === false) {
                continue;
            }

            try {
                self::oturum($baglanti, rtrim($kok, '/'), $secenekler, $durum);
            } catch (Throwable) {
                // Oturum hatası sunucuyu durdurmaz
            }
            @fclose($baglanti);
        }
    }

    /**
     * @param  resource  $c
     * @param  array<string, mixed>  $s
     * @param  array{stor_koptu: bool}  $durum
     */
    private static function oturum($c, string $kok, array $s, array &$durum): void
    {
        $cevap = static function (string $satir) use ($c): void {
            fwrite($c, $satir."\r\n");
        };
        $girdi = false;
        $kullanici = null;
        $cwd = '/';
        $veri = null;
        $rnfr = null;

        $sanal = static function (string $arg) use (&$cwd): ?string {
            $parcalar = [];
            foreach (explode('/', str_starts_with($arg, '/') ? $arg : $cwd.'/'.$arg) as $p) {
                if ($p === '' || $p === '.') {
                    continue;
                }
                if ($p === '..') {
                    if ($parcalar === []) {
                        return null;
                    }
                    array_pop($parcalar);

                    continue;
                }
                $parcalar[] = $p;
            }

            return '/'.implode('/', $parcalar);
        };
        $gercek = static fn (?string $yol): string => $yol === null ? '/yok/olmayan' : $kok.$yol;
        $veriAc = static function () use (&$veri) {
            [$tur, $deger] = $veri ?? [null, null];
            $veri = null;

            if ($tur === 'pasif') {
                $d = @stream_socket_accept($deger, 5);
                fclose($deger);

                return $d;
            }

            return $tur === 'aktif' ? @stream_socket_client('tcp://'.$deger, $no, $mesaj, 5) : false;
        };

        $cevap('220 sahte ftp');

        while (($satir = fgets($c)) !== false) {
            $satir = rtrim($satir, "\r\n");
            [$komut, $arg] = array_pad(explode(' ', $satir, 2), 2, '');
            $komut = strtoupper($komut);

            if (isset($s['gunluk'])) {
                file_put_contents((string) $s['gunluk'], $satir."\n", FILE_APPEND);
            }

            if ($komut === 'AUTH') {
                if (! isset($s['tls'])) {
                    $cevap('504 AUTH desteklenmiyor');

                    continue;
                }
                $cevap('234 TLS');
                stream_context_set_option($c, 'ssl', 'local_cert', (string) $s['tls']);
                if (! @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
                    return;
                }

                continue;
            }

            if ($komut === 'USER') {
                $kullanici = $arg;
                $cevap('331 parola');

                continue;
            }

            if ($komut === 'PASS') {
                $girdi = $kullanici === ($s['kullanici'] ?? null) && $arg === ($s['sifre'] ?? null);
                $cevap($girdi ? '230 tamam' : '530 giris reddedildi');

                continue;
            }

            if ($komut === 'QUIT') {
                $cevap('221 hosca kal');

                return;
            }

            if (! $girdi) {
                $cevap('530 once giris');

                continue;
            }

            switch ($komut) {
                case 'PWD':
                    $cevap('257 "'.$cwd.'"');
                    break;
                case 'CWD':
                    $yol = $sanal($arg);
                    if ($yol !== null && is_dir($gercek($yol))) {
                        $cwd = $yol;
                        $cevap('250 tamam');
                    } else {
                        $cevap('550 klasor yok');
                    }
                    break;
                case 'TYPE':
                case 'PBSZ':
                case 'PROT':
                    $cevap('200 tamam');
                    break;
                case 'EPSV':
                case 'PASV':
                    $dinleyici = stream_socket_server('tcp://127.0.0.1:0');
                    $ad = (string) stream_socket_get_name($dinleyici, false);
                    $port = (int) substr($ad, (int) strrpos($ad, ':') + 1);
                    $veri = ['pasif', $dinleyici];
                    $cevap($komut === 'EPSV'
                        ? "229 Entering Extended Passive Mode (|||{$port}|)"
                        : '227 Entering Passive Mode (127,0,0,1,'.intdiv($port, 256).','.($port % 256).')');
                    break;
                case 'EPRT':
                    $p = explode('|', $arg);
                    $veri = ['aktif', ($p[2] ?? '').':'.($p[3] ?? '')];
                    $cevap('200 tamam');
                    break;
                case 'PORT':
                    $p = explode(',', $arg);
                    $veri = ['aktif', implode('.', array_slice($p, 0, 4)).':'.(((int) ($p[4] ?? 0)) * 256 + (int) ($p[5] ?? 0))];
                    $cevap('200 tamam');
                    break;
                case 'SIZE':
                    $yol = $gercek($sanal($arg));
                    $cevap(is_file($yol) ? '213 '.filesize($yol) : '550 yok');
                    break;
                case 'STOR':
                    $d = $veriAc();
                    if ($d === false) {
                        $cevap('425 veri baglantisi yok');
                        break;
                    }
                    $cevap('150 gonder');
                    if (! empty($s['stor_asili'])) {
                        // Veri okunmaz, yanıt verilmez: istemci yalnız kendi süre sınırıyla kurtulur
                        sleep(3600);

                        return;
                    }
                    $f = fopen($gercek($sanal($arg)), 'wb');
                    if (isset($s['stor_kopar']) && ! $durum['stor_koptu']) {
                        $durum['stor_koptu'] = true;
                        $okunan = 0;
                        while ($okunan < (int) $s['stor_kopar'] && ($parca = fread($d, 8192)) !== false && $parca !== '') {
                            fwrite($f, $parca);
                            $okunan += strlen($parca);
                        }
                        fclose($f);
                        fclose($d);

                        // Bağlantı kopar: 226 yok, yarım dosya geçici adda kalır
                        return;
                    }
                    stream_copy_to_stream($d, $f);
                    fclose($f);
                    fclose($d);
                    $cevap('226 tamam');
                    break;
                case 'RETR':
                    $yol = $gercek($sanal($arg));
                    if (! is_file($yol)) {
                        $cevap('550 yok');
                        break;
                    }
                    $d = $veriAc();
                    $cevap('150 gonderiliyor');
                    $f = fopen($yol, 'rb');
                    stream_copy_to_stream($f, $d);
                    fclose($f);
                    fclose($d);
                    $cevap('226 tamam');
                    break;
                case 'NLST':
                    $d = $veriAc();
                    $cevap('150 liste');
                    foreach (scandir($gercek($cwd)) ?: [] as $ad) {
                        if (! str_starts_with($ad, '.')) {
                            fwrite($d, $ad."\r\n");
                        }
                    }
                    fclose($d);
                    $cevap('226 tamam');
                    break;
                case 'DELE':
                    $yol = $gercek($sanal($arg));
                    $cevap(is_file($yol) && unlink($yol) ? '250 silindi' : '550 yok');
                    break;
                case 'RNFR':
                    $rnfr = $sanal($arg);
                    $cevap($rnfr !== null && file_exists($gercek($rnfr)) ? '350 hazir' : '550 yok');
                    break;
                case 'RNTO':
                    $ok = empty($s['rnto_reddet']) && $rnfr !== null && rename($gercek($rnfr), $gercek($sanal($arg)));
                    $rnfr = null;
                    $cevap($ok ? '250 tamam' : '553 reddedildi');
                    break;
                default:
                    $cevap('502 bilinmeyen komut');
            }
        }
    }
}
