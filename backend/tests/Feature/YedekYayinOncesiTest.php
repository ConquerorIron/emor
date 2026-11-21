<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-013: `deploy.sh`'ın yayın öncesi yedeği (`yedek:yayin-oncesi`). Çıkış
 * kodu 0 değilse yayın durur (deploy.sh testi: scripts/tests/deploy.test.sh).
 */
final class YedekYayinOncesiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true, 'yedekleme.calistirici' => 'dogrudan', 'yedekleme.sure_siniri.kilit_bekleme' => 1]);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function pgDumpCalisti(): bool
    {
        try {
            Process::assertRan(fn (PendingProcess $p): bool => str_contains((string) json_encode($p->command), 'pg_dump') && ! in_array('--version', (array) $p->command, true));

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_modul_kapaliyken_eski_klasore_predeploy_yedegi_parola_dosyasiyla_alinir(): void
    {
        config(['yedekleme.aktif' => false]);
        $this->sahtePgAraclari();
        $eski = $this->yedekKoku.'/eski';

        $this->artisan('yedek:yayin-oncesi', ['--eski-klasor' => $eski])->assertSuccessful();

        $dosyalar = glob($eski.'/erp_predeploy_*.dump') ?: [];
        $this->assertCount(1, $dosyalar);
        $this->assertMatchesRegularExpression('/^erp_predeploy_\d{4}-\d{2}-\d{2}_\d{6}\.dump$/D', basename($dosyalar[0]));
        $this->assertStringStartsWith('PGDMP', (string) file_get_contents($dosyalar[0]));
        $this->assertSame(0, Yedek::query()->count());
        // Parola komut satırında ya da PGPASSWORD'de değil, PGPASSFILE'da (D2)
        Process::assertRan(function (PendingProcess $p): bool {
            $komut = implode(' ', (array) $p->command);

            return str_contains($komut, 'pg_dump') && str_contains($komut, '--format=custom')
                && isset($p->environment['PGPASSFILE']) && ($p->environment['PGPASSWORD'] ?? false) === false
                && ! str_contains($komut, 'gizli');
        });
    }

    public function test_modul_kapaliyken_pg_dump_basarisizsa_cikis_kodu_hata_yarim_dosya_kalmaz(): void
    {
        config(['yedekleme.aktif' => false]);
        $this->sahtePgAraclari(['*pg_dump*--format=custom*' => function (PendingProcess $surec) {
            $this->dosyaArgumaninaYaz($surec, 'yarim');

            return Process::result(errorOutput: 'pg_dump: error: connection failed', exitCode: 1);
        }]);
        $eski = $this->yedekKoku.'/eski';

        $this->artisan('yedek:yayin-oncesi', ['--eski-klasor' => $eski])
            ->expectsOutputToContain('pg_dump başarısız')
            ->assertFailed();

        $this->assertSame([], glob($eski.'/*') ?: []);
    }

    public function test_modul_acikken_yedek_imzali_ve_yayin_oncesi_kaynagiyla_alinir(): void
    {
        $this->sahtePgAraclari();

        $this->artisan('yedek:yayin-oncesi')->assertSuccessful();

        $yedek = Yedek::query()->sole();
        $this->assertSame(YedekKaynagi::YayinOncesi, $yedek->kaynak);
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertTrue($yedek->imzali);
        $this->assertStringStartsWith('erp_yayin_oncesi_', $yedek->dosya_adi);
    }

    public function test_baska_islem_kilidi_tutarken_yayin_anlasilir_mesajla_durur_yedek_alinmaz(): void
    {
        $this->sahtePgAraclari();
        $this->kilitAl();

        $this->artisan('yedek:yayin-oncesi')
            ->expectsOutputToContain('Başka bir yedekleme / geri yükleme işlemi sürüyor')
            ->assertFailed();

        $this->assertFalse($this->pgDumpCalisti());
        $this->assertSame(0, Yedek::query()->count());
    }

    public function test_acik_ustune_geri_yukleme_varken_yayin_baslamaz(): void
    {
        $this->sahtePgAraclari();
        $kilit = $this->kilitAl();
        app(YedeklemeYapilandirmasi::class)->islemDurumu()->yaz(new IslemKaydi(
            kimlik: (string) Str::uuid(),
            tur: IslemTuru::GeriYuklemeUstune,
            adim: 'onay_bekliyor',
            acik: true,
            baslangic: CarbonImmutable::now('UTC'),
            sonGuncelleme: CarbonImmutable::now('UTC'),
            hata: null,
            erisimAnahtariOzeti: null,
        ), $kilit);
        $kilit->birak();
        $this->tutulanKilit = null;

        $this->artisan('yedek:yayin-oncesi')
            ->expectsOutputToContain('Açık bir geri yükleme işlemi var')
            ->assertFailed();

        $this->assertFalse($this->pgDumpCalisti());
    }

    public function test_servis_calistiricisinda_istek_birakilir_sonuc_beklenir(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        Process::fake();
        Sleep::fake();
        $kutu = app(IstekKutusu::class);
        $istek = null;
        // Çalıştırıcı: ilk beklemede isteği okur, sonucu yazar
        Sleep::whenFakingSleep(function () use ($kutu, &$istek): void {
            foreach ($kutu->bekleyenler() as $yol) {
                $istek = json_decode((string) file_get_contents($yol), true);
                $kutu->sil($yol);
                $kutu->sonucYaz($istek['kimlik'], 'tamam');
            }
        });

        $this->artisan('yedek:yayin-oncesi')->assertSuccessful();

        $this->assertSame('yedek_al', $istek['tur']);
        $this->assertSame('yayin_oncesi', $istek['parametreler']['kaynak']);
        Process::assertNothingRan();
    }

    /**
     * G1 / BY-014-R2: istek yayımlandı ama klasör eşitlenemedi — yayın durmaz,
     * aynı isteğin sonucu beklenir; ikinci yedek isteği bırakılmaz.
     */
    public function test_istek_klasoru_esitlenemese_de_yayimlanan_istegin_sonucu_beklenir(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root klasör iznini aşar (T11).');
        }
        config(['yedekleme.calistirici' => 'servis']);
        Process::fake();
        Sleep::fake();
        $kutu = app(IstekKutusu::class);
        $klasor = config('yedekleme.istek_klasoru').'/bekleyen';
        @mkdir($klasor, 0770, true);
        // Yazılabilir ve geçilebilir ama okunamaz: taşıma olur, eşitlemek için açılamaz
        chmod($klasor, 0300);
        $istekler = [];
        Sleep::whenFakingSleep(function () use ($kutu, $klasor, &$istekler): void {
            chmod($klasor, 0770);
            foreach ($kutu->bekleyenler() as $yol) {
                $istekler[] = basename($yol, '.json');
                $kutu->sil($yol);
                $kutu->sonucYaz(basename($yol, '.json'), 'tamam');
            }
        });

        try {
            $this->artisan('yedek:yayin-oncesi')->assertSuccessful();
        } finally {
            chmod($klasor, 0770);
        }

        $this->assertCount(1, $istekler);
    }

    public function test_servis_yanit_vermezse_yayin_durur_istek_geri_cekilir(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        Process::fake();
        Sleep::fake();

        $this->artisan('yedek:yayin-oncesi')
            ->expectsOutputToContain('zamanında işlenmedi')
            ->assertFailed();

        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_servisten_basarisiz_sonuc_gelirse_yayin_durur(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        Sleep::fake();
        $kutu = app(IstekKutusu::class);
        Sleep::whenFakingSleep(function () use ($kutu): void {
            foreach ($kutu->bekleyenler() as $yol) {
                $kutu->sil($yol);
                $kutu->sonucYaz(basename($yol, '.json'), 'basarisiz', 'DISK_DOLU', 'Diskte yer yok');
            }
        });

        $this->artisan('yedek:yayin-oncesi')
            ->expectsOutputToContain('Diskte yer yok')
            ->assertFailed();
    }
}
