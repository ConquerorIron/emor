<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\YedekImzasi;
use Closure;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BY-024-R2: kurtarmada SQL çalıştırmadan önce manifest imzası ve yedek
 * dosyasının özeti doğrulanır (kurtarma.md §4.6, README §5.2).
 */
final class YedekManifestDogrulaTest extends TestCase
{
    private const DUMP_ADI = 'erp_elle_2026-11-21_101500+0300.dump';

    private string $klasor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->klasor = sys_get_temp_dir().'/yedek-manifest-dogrula-'.bin2hex(random_bytes(4));
        mkdir($this->klasor, 0750);
        config([
            'yedekleme.klasor' => $this->klasor,
            'yedekleme.kalici_kok' => sys_get_temp_dir(),
            'yedekleme.imza_anahtari' => $this->klasor.'/anahtar/imza.key',
        ]);
        app(YedekImzasi::class)->anahtarUret();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->klasor);

        parent::tearDown();
    }

    /** Kurtarmadaki gibi: çözülmüş dump ve imzalı manifesti yan yana */
    private function yedek(string $icerik = 'dump!', ?string $acl = null): string
    {
        $dump = $this->klasor.'/'.self::DUMP_ADI;
        file_put_contents($dump, $icerik);
        app(ManifestDeposu::class)->yaz(YedekManifestiTest::manifest(dosyaAdi: self::DUMP_ADI, baglanmaYetkileri: $acl), $this->klasor);

        return $dump;
    }

    /** Yetki seçeneklerinin bağlantısı: servis kipindeki gibi yedekleme bağlantısı (psql sahte) */
    private function yedekBaglantisi(): void
    {
        config([
            'yedekleme.baglanti' => 'yedek_test',
            'yedekleme.parola_dosyasi' => null,
            'database.connections.yedek_test' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => 'erp', 'username' => 'erp', 'password' => 'parola'],
            'yedekleme.araclar' => ['pg_dump' => '/usr/bin/pg_dump', 'pg_restore' => '/usr/bin/pg_restore', 'psql' => '/usr/bin/psql'],
        ]);
    }

    public function test_imzasi_ve_ozeti_tutan_yedek_dogrulanir_createdb_degerleri_yazilir(): void
    {
        $this->artisan('yedek:manifest-dogrula', ['dump' => $this->yedek()])
            ->expectsOutputToContain('DOGRULANDI')
            ->expectsOutputToContain('--encoding=UTF8 --lc-collate=en_US.UTF-8 --lc-ctype=en_US.UTF-8')
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{Closure(self, string): void}>
     */
    public static function guvenilmeyenManifestler(): array
    {
        return [
            // Özet de değiştirilse imza tutmaz: bozuk dump'a uydurulmuş manifest geçmez
            'özeti değiştirilmiş manifest' => [function (self $test, string $manifest): void {
                file_put_contents($manifest, str_replace(hash('sha256', 'dump!'), hash('sha256', 'dumP!'), (string) file_get_contents($manifest)));
            }],
            'yanlış imza anahtarı' => [function (self $test, string $manifest): void {
                unlink(config('yedekleme.imza_anahtari'));
                $test->app->forgetInstance(YedekImzasi::class);
                app(YedekImzasi::class)->anahtarUret();
            }],
            'imza anahtarı yok' => [function (self $test, string $manifest): void {
                unlink(config('yedekleme.imza_anahtari'));
                $test->app->forgetInstance(YedekImzasi::class);
            }],
        ];
    }

    /**
     * @param  Closure(self, string): void  $boz
     */
    #[DataProvider('guvenilmeyenManifestler')]
    public function test_imzasi_dogrulanamayan_manifestte_basarisiz_doner(Closure $boz): void
    {
        $dump = $this->yedek();
        $boz($this, ManifestDeposu::jsonYolu($dump));

        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump])
            ->expectsOutputToContain('GERI_YUKLEME_IMZA_GECERSIZ')
            ->doesntExpectOutputToContain('DOGRULANDI')
            ->assertFailed();
    }

    public function test_ozeti_manifestle_tutmayan_yedek_dosyasi_reddedilir(): void
    {
        $dump = $this->yedek();
        // Aynı boyutta tek bayt farkı (bozuk indirme)
        file_put_contents($dump, 'dumP!');

        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump])
            ->expectsOutputToContain('GERI_YUKLEME_OZET_TUTMUYOR')
            ->assertFailed();
    }

    /** BY-031-R2: imza doğrulanmadan veritabanına hiç bağlanılmaz, yetkiler değişmez. */
    public function test_imzasi_tutmayan_manifestte_baglanma_yetkilerine_hic_dokunulmaz(): void
    {
        Process::fake();
        $dump = $this->yedek();
        $manifest = ManifestDeposu::jsonYolu($dump);
        file_put_contents($manifest, str_replace(hash('sha256', 'dump!'), hash('sha256', 'dumP!'), (string) file_get_contents($manifest)));

        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump, '--yetkileri-uygula' => 'erp'])
            ->expectsOutputToContain('GERI_YUKLEME_IMZA_GECERSIZ')
            ->doesntExpectOutputToContain('YETKILER_UYGULANDI')
            ->assertFailed();

        Process::assertNothingRan();
    }

    /** BY-031-R2: doğrulanan imzalı manifestteki bağlanma yetkileri, ekrandaki geri yüklemenin koduyla veritabanına gider. */
    public function test_dogrulanan_manifestin_baglanma_yetkileri_veritabanina_uygulanir(): void
    {
        $this->yedekBaglantisi();
        Process::fake(['*' => '']);
        $dump = $this->yedek(acl: '{erp=CTc/erp,diger=c/erp}');

        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump, '--yetkileri-uygula' => 'erp'])
            ->expectsOutputToContain('DOGRULANDI')
            ->expectsOutputToContain('YETKILER_UYGULANDI')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $p): bool => str_ends_with((string) ((array) $p->command)[0], 'psql')
            && in_array('--set=ad=erp', (array) $p->command, true)
            && in_array('--set=acl={erp=CTc/erp,diger=c/erp}', (array) $p->command, true)
            && in_array('--set=varsayilan=0', (array) $p->command, true));
    }

    /** BY-031-R2: açılıştan önceki denetim eşdeğer olmayan yetkide başarısız döner ve açılışa izin vermez. */
    public function test_baglanma_yetkileri_esdeger_degilse_denetim_basarisiz_doner(): void
    {
        $this->yedekBaglantisi();
        Process::fake(['*psql*' => "f\n", '*' => '']);
        $dump = $this->yedek(acl: '{erp=CTc/erp,diger=c/erp}');

        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump, '--yetkileri-denetle' => 'erp'])
            ->expectsOutputToContain('eşdeğer DEĞİL')
            ->doesntExpectOutputToContain('YETKILER_ESDEGER')
            ->assertFailed();
    }

    public function test_baska_yedegin_imzali_manifesti_kabul_edilmez(): void
    {
        $dump = $this->yedek();
        $baska = $this->klasor.'/erp_elle_2026-11-22_101500+0300.dump';
        copy($dump, $baska);

        $this->artisan('yedek:manifest-dogrula', ['dump' => $baska, '--manifest' => ManifestDeposu::jsonYolu($dump)])
            ->expectsOutputToContain('başka bir yedeğin')
            ->assertFailed();
    }
}
