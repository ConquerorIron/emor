<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\YedekKilidi;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

/**
 * Yedekleme kilidi (BY-005): işletim sistemi dosya kilidi (flock). Diğer süreç
 * olarak gerçek bir PHP süreci ve `flock` komutu (deploy.sh'ın kullanacağı)
 * kullanılır — aynı süreç içindeki iki tutamaç süreçler arası davranışı
 * kanıtlamaz.
 */
final class YedekKilidiTest extends TestCase
{
    private string $klasor;

    private string $yol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->klasor = sys_get_temp_dir().'/yedek-kilit-'.bin2hex(random_bytes(4));
        $this->yol = $this->klasor.'/kilit';
    }

    protected function tearDown(): void
    {
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
        $surec->waitUntil(fn (string $tur, string $cikti): bool => str_contains($cikti, 'tutuyor'));

        return $surec;
    }

    private function kilitDosyasiAc(): void
    {
        File::ensureDirectoryExists($this->klasor, 0750);
        touch($this->yol);
    }

    public function test_kilit_tutulurken_ikinci_tutamac_alamaz_birakilinca_alir(): void
    {
        $birinci = new YedekKilidi($this->yol);
        $ikinci = new YedekKilidi($this->yol);

        $this->assertTrue($birinci->al());
        $this->assertFalse($ikinci->al());

        $birinci->birak();

        $this->assertTrue($ikinci->al());
    }

    public function test_baska_surec_tutarken_alinamaz_surec_olunce_kilit_duser(): void
    {
        $this->kilitDosyasiAc();
        $surec = $this->kilidiTutanSurec(60);
        $kilit = new YedekKilidi($this->yol);

        $this->assertFalse($kilit->al());

        $surec->signal(SIGKILL);
        $surec->wait();

        $this->assertTrue($kilit->al());
    }

    public function test_kilit_tutulurken_flock_komutu_da_alamaz(): void
    {
        $kilit = new YedekKilidi($this->yol);
        $this->assertTrue($kilit->al());

        $this->assertSame(1, Process::run(['flock', '-n', $this->yol, 'true'])->exitCode());

        $kilit->birak();

        $this->assertSame(0, Process::run(['flock', '-n', $this->yol, 'true'])->exitCode());
    }

    public function test_bekleyerek_al_kilit_birakilinca_alir(): void
    {
        $this->kilitDosyasiAc();
        $surec = $this->kilidiTutanSurec(1);
        $kilit = new YedekKilidi($this->yol);

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
        $surec = $this->kilidiTutanSurec(60);
        $kilit = new YedekKilidi($this->yol);

        $baslangic = microtime(true);
        $alindi = $kilit->bekleyerekAl(0.5);
        $gecen = microtime(true) - $baslangic;

        $this->assertFalse($alindi);
        $this->assertGreaterThanOrEqual(0.5, $gecen);
        $this->assertLessThan(3, $gecen);

        $surec->signal(SIGKILL);
        $surec->wait();
    }

    public function test_yazma_izni_olmayan_kilit_dosyasinda_da_kilit_alinir(): void
    {
        // Sunucuda yayın kullanıcısı kilit dosyasına yalnız okuma iznine sahip (BY-003)
        $this->kilitDosyasiAc();
        chmod($this->yol, 0440);
        $kilit = new YedekKilidi($this->yol);

        $this->assertTrue($kilit->al());
        $this->assertSame(1, Process::run(['flock', '-n', $this->yol, 'true'])->exitCode());
    }

    public function test_kilit_dosyasi_yoksa_klasoruyle_birlikte_acilir(): void
    {
        $kilit = new YedekKilidi($this->yol);

        $this->assertTrue($kilit->al());
        $this->assertFileExists($this->yol);
        $this->assertSame('640', substr(sprintf('%o', fileperms($this->yol)), -3));
    }

    public function test_kilit_dosyasi_acilamazsa_mesgul_sanilmaz_hata_verir(): void
    {
        File::ensureDirectoryExists($this->klasor, 0750);
        chmod($this->klasor, 0500);
        $kilit = new YedekKilidi($this->yol);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->yol);

        $kilit->al();
    }
}
