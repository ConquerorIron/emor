<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\YedekKilidi;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Yedekleme kilidi (BY-005): işletim sistemi dosya kilidi (flock). Diğer süreç
 * olarak gerçek bir PHP süreci ve `flock` komutu (deploy.sh'ın kullanacağı)
 * kullanılır — aynı süreç içindeki iki tutamaç süreçler arası davranışı
 * kanıtlamaz.
 *
 * Ortam varsayımları korunur (Dikkat_Edilecekler T11): izin kaldırarak sınayan
 * test root altında, `flock` komutuna dayanan test komut yoksa atlanır; başlatılan
 * alt süreçler doğrulama düşse de tearDown'da öldürülür.
 */
final class YedekKilidiTest extends TestCase
{
    /** pcntl eklentisine bağlı olmamak için sabitin değeri (Symfony Process de böyle yapar) */
    private const SIGKILL = 9;

    /**
     * Kurulumun kalıcı kıldığı kök (KaliciKlasor). Sistem geçici klasörü:
     * izni kaldırılan test klasörü eşitleme zincirinde kalır (BY-005-R9).
     */
    private string $kaliciKok;

    private string $klasor;

    private string $yol;

    /** @var list<InvokedProcess> */
    private array $surecler = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kaliciKok = sys_get_temp_dir();
        $this->klasor = $this->kaliciKok.'/yedek-kilit-'.bin2hex(random_bytes(4));
        $this->yol = $this->klasor.'/kilit';
    }

    protected function tearDown(): void
    {
        foreach ($this->surecler as $surec) {
            if ($surec->running()) {
                $surec->signal(self::SIGKILL);
                $surec->wait();
            }
        }

        if (is_dir($this->klasor)) {
            chmod($this->klasor, 0700);
            File::deleteDirectory($this->klasor);
        }

        parent::tearDown();
    }

    /** Kilidi tutan ayrı bir süreç başlatır; kilidi aldığını bildirene kadar bekler. */
    private function kilidiTutanSurec(int $saniye): InvokedProcess
    {
        $kod = '$f = fopen($argv[1], "r"); flock($f, LOCK_EX); echo "tutuyor\n"; sleep((int) $argv[2]);';
        $surec = Process::timeout(60)->start([PHP_BINARY, '-r', $kod, $this->yol, (string) $saniye]);
        $this->surecler[] = $surec;
        $surec->waitUntil(fn (string $tur, string $cikti): bool => str_contains($cikti, 'tutuyor'));

        return $surec;
    }

    private function kilitDosyasiAc(): void
    {
        File::ensureDirectoryExists($this->klasor, 0750);
        touch($this->yol);
    }

    private function flockKomutuGerekli(): void
    {
        if ((new ExecutableFinder)->find('flock') === null) {
            $this->markTestSkipped('`flock` komutu yok (util-linux); deploy.sh uyumu sınanamıyor.');
        }
    }

    private function rootDegilGerekli(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root dosya izinlerini aşar; izin kaldırarak sınanamaz.');
        }
    }

    public function test_kilit_tutulurken_ikinci_tutamac_alamaz_birakilinca_alir(): void
    {
        $birinci = new YedekKilidi($this->yol, $this->kaliciKok);
        $ikinci = new YedekKilidi($this->yol, $this->kaliciKok);

        $this->assertTrue($birinci->al());
        $this->assertFalse($ikinci->al());

        $birinci->birak();

        $this->assertTrue($ikinci->al());
    }

    public function test_dosyayi_tutuyor_mu_yalniz_o_dosyanin_kilidi_alinmisken_dogru(): void
    {
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);
        $ayniDosyaIkinci = new YedekKilidi($this->yol, $this->kaliciKok);
        $baskaDosya = new YedekKilidi($this->klasor.'/baska-kilit', $this->kaliciKok);

        $this->assertFalse($kilit->dosyayiTutuyorMu($this->yol));
        $kilit->al();
        $this->assertTrue($kilit->dosyayiTutuyorMu($this->yol));
        // Aynı dosya, farklı yazılmış yol
        $this->assertTrue($kilit->dosyayiTutuyorMu($this->klasor.'/./kilit'));
        $ayniDosyaIkinci->al();
        $this->assertFalse($ayniDosyaIkinci->dosyayiTutuyorMu($this->yol));
        $baskaDosya->al();
        $this->assertFalse($baskaDosya->dosyayiTutuyorMu($this->yol));
        $kilit->birak();
        $this->assertFalse($kilit->dosyayiTutuyorMu($this->yol));
    }

    public function test_baska_surec_tutarken_alinamaz_surec_olunce_kilit_duser(): void
    {
        $this->kilitDosyasiAc();
        $surec = $this->kilidiTutanSurec(60);
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);

        $this->assertFalse($kilit->al());

        $surec->signal(self::SIGKILL);
        $surec->wait();

        $this->assertTrue($kilit->al());
    }

    /**
     * YF-36 (D1): kilidi tutan süreç bir alt süreç (pg_dump gibi) başlatıp
     * öldürülürse kilit hemen düşer; alt süreç kilidin tutamacını miras almaz.
     */
    public function test_alt_surec_baslatan_surec_olunce_kilit_alt_surec_bitmeden_duser(): void
    {
        $kod = 'require $argv[3]; $k = new App\Services\Yedekleme\YedekKilidi($argv[1], $argv[2]); $k->al() || exit(1);'
            .' $bos = ["file", "/dev/null", "r"]; $yaz = ["file", "/dev/null", "w"];'
            .' $c = proc_open(["sleep", "60"], [$bos, $yaz, $yaz], $p); echo "cocuk ".proc_get_status($c)["pid"]."\n"; sleep(60);';
        $surec = Process::timeout(60)->start([PHP_BINARY, '-r', $kod, $this->yol, $this->kaliciKok, base_path('vendor/autoload.php')]);
        $this->surecler[] = $surec;
        $surec->waitUntil(fn (string $tur, string $cikti): bool => str_contains($cikti, "\n"));
        $this->assertSame(1, preg_match('/cocuk (\d+)/', $surec->output(), $eslesme));
        $cocuk = (int) $eslesme[1];

        try {
            $surec->signal(self::SIGKILL);
            $surec->wait();

            $this->assertTrue((new YedekKilidi($this->yol, $this->kaliciKok))->al());
        } finally {
            Process::run(['kill', '-9', (string) $cocuk]);
        }
    }

    public function test_kilit_tutulurken_flock_komutu_da_alamaz(): void
    {
        $this->flockKomutuGerekli();
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);
        $this->assertTrue($kilit->al());

        $this->assertSame(1, Process::run(['flock', '-n', $this->yol, 'true'])->exitCode());

        $kilit->birak();

        $this->assertSame(0, Process::run(['flock', '-n', $this->yol, 'true'])->exitCode());
    }

    public function test_bekleyerek_al_kilit_birakilinca_alir(): void
    {
        $this->kilitDosyasiAc();
        $surec = $this->kilidiTutanSurec(1);
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);

        $baslangic = microtime(true);
        $alindi = $kilit->bekleyerekAl(10);
        $gecen = microtime(true) - $baslangic;
        $surec->wait();

        // Süreç kilidi ~1 sn tuttu: hemen değil, bırakılınca alınmış olmalı
        $this->assertTrue($alindi);
        $this->assertGreaterThan(0.5, $gecen);
        $this->assertLessThan(5, $gecen);
    }

    public function test_bekleyerek_al_sure_dolunca_vazgecer(): void
    {
        $this->kilitDosyasiAc();
        $this->kilidiTutanSurec(60);
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);

        $baslangic = microtime(true);
        $alindi = $kilit->bekleyerekAl(0.5);
        $gecen = microtime(true) - $baslangic;

        $this->assertFalse($alindi);
        $this->assertGreaterThanOrEqual(0.5, $gecen);
        $this->assertLessThan(3, $gecen);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function gecersizSureler(): array
    {
        return [
            'sonsuz' => [INF],
            'sayı değil' => [NAN],
            'negatif' => [-1.0],
            // Nanosaniyeye çevrilince tam sayıya sığmaz: uyarıyla 0'a düşüp
            // hemen vazgeçmesin (Fable incelemesi F15)
            'çok büyük sonlu' => [1e300],
            'bir günden uzun' => [86_400.5],
        ];
    }

    #[DataProvider('gecersizSureler')]
    public function test_bekleyerek_al_gecersiz_sureyi_beklemeden_reddeder(float $saniye): void
    {
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);

        $this->expectException(InvalidArgumentException::class);

        $kilit->bekleyerekAl($saniye);
    }

    public function test_yazma_izni_olmayan_kilit_dosyasinda_da_kilit_alinir(): void
    {
        // Sunucuda yayın kullanıcısı kilit dosyasına yalnız okuma iznine sahip (BY-003)
        $this->flockKomutuGerekli();
        $this->kilitDosyasiAc();
        chmod($this->yol, 0440);
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);

        $this->assertTrue($kilit->al());
        $this->assertSame(1, Process::run(['flock', '-n', $this->yol, 'true'])->exitCode());
    }

    public function test_kilit_dosyasi_yoksa_klasoruyle_birlikte_acilir(): void
    {
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);

        $this->assertTrue($kilit->al());
        $this->assertFileExists($this->yol);
        $this->assertSame('640', substr(sprintf('%o', fileperms($this->yol)), -3));
    }

    /**
     * Üst klasör diske eşitlenemeyen ilk deneme klasörü açıp hata verir;
     * yeniden deneme klasörü VAR bulsa da üst klasörü yine eşitlemeli, yoksa
     * kesintide klasör girdisi kaybolabilirken başarı döner (BY-005-R9, D3).
     * Hata gerçek: üst klasör yazılabilir ama okunamaz (0300) — alt klasör
     * açılır, üst klasör eşitleme için açılamaz.
     */
    public function test_ust_klasor_esitlenemezse_yeniden_denemede_de_kilit_alinmaz(): void
    {
        $this->rootDegilGerekli();
        File::ensureDirectoryExists($this->klasor, 0700);
        $yol = $this->klasor.'/durum/kilit';
        chmod($this->klasor, 0300);

        try {
            foreach (['ilk deneme', 'yeniden deneme'] as $deneme) {
                try {
                    (new YedekKilidi($yol, $this->kaliciKok))->al();
                    $this->fail("Üst klasör eşitlenemezken kilit alındı ({$deneme}).");
                } catch (RuntimeException $hata) {
                    $this->assertStringContainsString('eşitlenemedi', $hata->getMessage(), $deneme);
                }
            }
        } finally {
            chmod($this->klasor, 0700);
        }

        $this->assertTrue((new YedekKilidi($yol, $this->kaliciKok))->al());
    }

    /**
     * Eşitleme kalıcı kökte durur (BY-005-R11): kökün üstü yalnız geçilebilir
     * olsa da (BY-003) kökün altındaki kilit klasörü ve dosyası açılır.
     */
    public function test_kalici_kokun_ustunde_yalniz_gecis_izni_varken_kilit_acilir(): void
    {
        $this->rootDegilGerekli();
        $kok = $this->klasor.'/kok';
        File::ensureDirectoryExists($kok, 0750);
        chmod($this->klasor, 0100);

        try {
            $alindi = (new YedekKilidi($kok.'/durum/kilit', $kok))->al();
        } finally {
            chmod($this->klasor, 0700);
        }

        $this->assertTrue($alindi);
        $this->assertFileExists($kok.'/durum/kilit');
    }

    /**
     * Kalıcı kökün dışındaki ya da kökü kurulmamış bir yolda klasör açılmaz:
     * kökün üstünde açılan girdiyi kimse eşitlemezdi (BY-005-R11).
     */
    public function test_kalici_kokun_disinda_ya_da_kok_yokken_kilit_klasoru_acilmaz(): void
    {
        $kok = $this->klasor.'/kok';
        File::ensureDirectoryExists($kok, 0750);
        $durumlar = [
            'kökün dışında' => [$this->klasor.'/baska/kilit', $kok, InvalidArgumentException::class],
            'adı kökle başlıyor, altında değil' => [$kok.'2/kilit', $kok, InvalidArgumentException::class],
            'kökün üstüne çıkıyor' => [$kok.'/../baska/kilit', $kok, InvalidArgumentException::class],
            'kök kurulmamış' => [$this->klasor.'/yok/durum/kilit', $this->klasor.'/yok', RuntimeException::class],
        ];

        foreach ($durumlar as $durum => [$yol, $kalici, $beklenen]) {
            $hata = null;
            try {
                (new YedekKilidi($yol, $kalici))->al();
            } catch (InvalidArgumentException|RuntimeException $yakalanan) {
                $hata = $yakalanan;
            }
            $this->assertInstanceOf($beklenen, $hata, "Kilit açıldı ya da başka hata verdi ({$durum}).");
        }

        $this->assertSame(['kok'], array_values(array_diff(scandir($this->klasor), ['.', '..'])));
        $this->assertSame([], array_values(array_diff(scandir($kok), ['.', '..'])));
    }

    /**
     * Kimlik denetimi PHP'nin stat önbelleğine güvenmemeli: başka süreç kilit
     * dosyasını yenisiyle değiştirince eski tutamaç o dosyayı tutmuyor
     * sayılmalı (BY-005-R10).
     */
    public function test_kilit_dosyasi_baska_surecte_degistirilince_eski_tutamac_tutmuyor_sayilir(): void
    {
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);
        $kilit->al();
        $this->assertTrue($kilit->dosyayiTutuyorMu($this->yol));

        // exec: arada PHP'nin başka bir stat çağrısı (Process sınıfınınki gibi)
        // tek girdilik önbelleği temizleyip sınamayı boşa çıkarmasın
        $yeni = escapeshellarg($this->yol.'.yeni');
        exec("touch {$yeni} && mv {$yeni} ".escapeshellarg($this->yol), result_code: $kod);

        $this->assertSame(0, $kod);
        $this->assertFalse($kilit->dosyayiTutuyorMu($this->yol));
    }

    public function test_kilit_dosyasi_acilamazsa_mesgul_sanilmaz_hata_verir(): void
    {
        $this->rootDegilGerekli();
        File::ensureDirectoryExists($this->klasor, 0750);
        chmod($this->klasor, 0500);
        $kilit = new YedekKilidi($this->yol, $this->kaliciKok);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->yol);

        $kilit->al();
    }
}
