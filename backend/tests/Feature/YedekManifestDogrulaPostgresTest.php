<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\YedekImzasi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use PDOException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * BY-031-R2: elle kurtarmada (kurtarma.md §4.9) `pg_restore --no-owner`
 * veritabanının bağlanma yetkilerini getirmez; `yedek:manifest-dogrula`
 * imzalı manifestteki ACL'yi GERÇEK PostgreSQL'de uygular ve açılıştan önce
 * değiştirmeden denetler. Geçici roller (`ajan_b1_*`) ve veritabanları
 * (`erp_ajan_b1_*`) yerel sunucuda yönetici (`sudo -n -u postgres`) ile
 * açılır, test sonunda silinir; uygulamanın veritabanlarına dokunulmaz.
 *
 * Yalnız açıkça istenince çalışır: `YEDEKLEME_GERCEK_PG=1 php artisan test
 * --filter=YedekManifestDogrulaPostgresTest` (CI'da PostgreSQL yöneticisi yok).
 */
final class YedekManifestDogrulaPostgresTest extends TestCase
{
    private const APP = 'ajan_b1_app';

    private const SVC = 'ajan_b1_svc';

    private const DIGER = 'ajan_b1_diger';

    private const YABANCI = 'ajan_b1_yabanci';

    private const DUMP_ADI = 'erp_elle_2026-11-21_101500+0300.dump';

    /** @var array<string, string> rol => parola */
    private array $parolalar = [];

    /** @var list<string> */
    private array $veritabanlari = [];

    private string $klasor;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('YEDEKLEME_GERCEK_PG') !== '1') {
            $this->markTestSkipped('Gerçek PostgreSQL testi: YEDEKLEME_GERCEK_PG=1 ile çalışır (yerel yönetici gerekir).');
        }

        $this->klasor = sys_get_temp_dir().'/emor-b1-pg-'.bin2hex(random_bytes(4));
        mkdir($this->klasor, 0700);
        $this->rolleriKaldir();

        foreach ([self::APP, self::SVC, self::DIGER, self::YABANCI] as $rol) {
            $this->parolalar[$rol] = bin2hex(random_bytes(12));
        }
        $this->yonetici(sprintf(
            "CREATE ROLE %s LOGIN PASSWORD '%s'; CREATE ROLE %s LOGIN CREATEDB PASSWORD '%s' IN ROLE %s; CREATE ROLE %s LOGIN PASSWORD '%s'; CREATE ROLE %s LOGIN PASSWORD '%s';",
            self::APP, $this->parolalar[self::APP], self::SVC, $this->parolalar[self::SVC], self::APP,
            self::DIGER, $this->parolalar[self::DIGER], self::YABANCI, $this->parolalar[self::YABANCI],
        ));

        $pgpass = $this->klasor.'/pgpass';
        file_put_contents($pgpass, '127.0.0.1:5432:*:'.self::SVC.':'.$this->parolalar[self::SVC]."\n");
        chmod($pgpass, 0600);

        config([
            'yedekleme.klasor' => $this->klasor,
            'yedekleme.kalici_kok' => sys_get_temp_dir(),
            'yedekleme.imza_anahtari' => $this->klasor.'/imza.key',
            'yedekleme.baglanti' => 'yedek_test',
            'yedekleme.rol' => self::SVC,
            'yedekleme.parola_dosyasi' => $pgpass,
            'yedekleme.araclar' => ['pg_dump' => '/usr/bin/pg_dump', 'pg_restore' => '/usr/bin/pg_restore', 'psql' => '/usr/bin/psql'],
        ]);
        app(YedekImzasi::class)->anahtarUret();
    }

    protected function tearDown(): void
    {
        if (getenv('YEDEKLEME_GERCEK_PG') === '1') {
            DB::purge('yedek_test');
            foreach ($this->veritabanlari as $ad) {
                $this->yonetici("DROP DATABASE IF EXISTS {$ad} WITH (FORCE);");
            }
            $this->rolleriKaldir();
            File::deleteDirectory($this->klasor);
        }

        parent::tearDown();
    }

    public function test_daraltilmis_baglanma_yetkileri_elle_kurtarilan_veritabanina_esdeger_uygulanir_acilistan_once_denetlenir(): void
    {
        // Kaynak: PUBLIC'in bağlanması kaldırılmış, yalnız DIGER'e verilmiş; manifest bu ACL'yi taşır
        $kaynak = $this->veritabaniAc();
        $this->yonetici("REVOKE ALL ON DATABASE {$kaynak} FROM PUBLIC; GRANT CONNECT ON DATABASE {$kaynak} TO ".self::DIGER.';');
        $acl = $this->yoneticiDeger("SELECT datacl::text FROM pg_database WHERE datname = '{$kaynak}'");
        $dump = $this->klasor.'/'.self::DUMP_ADI;
        file_put_contents($dump, 'dump!');
        app(ManifestDeposu::class)->yaz(YedekManifestiTest::manifest(dosyaAdi: self::DUMP_ADI, baglanmaYetkileri: $acl), $this->klasor);
        // Kurtarılan: createdb ile açılmış (varsayılan yetkiler: herkes bağlanır)
        $kurtarilan = $this->veritabaniAc();
        $this->baglantiyiAyarla($kurtarilan);
        $this->baglan($kurtarilan, self::YABANCI)->query('SELECT 1');

        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump, '--yetkileri-denetle' => $kurtarilan])
            ->expectsOutputToContain('eşdeğer DEĞİL')->assertFailed();
        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump, '--yetkileri-uygula' => $kurtarilan])
            ->expectsOutputToContain('YETKILER_UYGULANDI')->assertSuccessful();
        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump, '--yetkileri-denetle' => $kurtarilan])
            ->expectsOutputToContain('YETKILER_ESDEGER')->assertSuccessful();

        $this->baglan($kurtarilan, self::DIGER)->query('SELECT 1');
        $this->baglan($kurtarilan, self::APP)->query('SELECT 1');
        $this->assertBaglanamaz($kurtarilan, self::YABANCI);
    }

    private function veritabaniAc(): string
    {
        $ad = 'erp_ajan_b1_'.bin2hex(random_bytes(3));
        $this->veritabanlari[] = $ad;
        $this->yonetici("CREATE DATABASE {$ad} OWNER ".self::APP.';');

        return $ad;
    }

    /** Kurtarmadaki gibi: kurtarılan veritabanı uygulamanın (canlı) veritabanıdır */
    private function baglantiyiAyarla(string $ad): void
    {
        config(['database.connections.yedek_test' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => $ad,
            'username' => self::APP, 'password' => $this->parolalar[self::APP], 'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]]);
        DB::purge('yedek_test');
    }

    private function baglan(string $vt, string $rol): PDO
    {
        return new PDO("pgsql:host=127.0.0.1;port=5432;dbname={$vt}", $rol, $this->parolalar[$rol], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function assertBaglanamaz(string $vt, string $rol): void
    {
        try {
            $this->baglan($vt, $rol);
            $this->fail("{$rol} {$vt} veritabanına bağlanabildi.");
        } catch (PDOException $hata) {
            $this->assertStringContainsString('permission denied for database', $hata->getMessage());
        }
    }

    private function yonetici(string $sql): void
    {
        $sonuc = Process::timeout(60)->run(['sudo', '-n', '-u', 'postgres', 'psql', '-XAtq', '-v', 'ON_ERROR_STOP=1', '-d', 'postgres', '-c', $sql]);
        if (! $sonuc->successful()) {
            throw new RuntimeException('Yönetici SQL başarısız: '.$sonuc->errorOutput());
        }
    }

    private function yoneticiDeger(string $sql): string
    {
        $sonuc = Process::timeout(60)->run(['sudo', '-n', '-u', 'postgres', 'psql', '-XAtq', '-v', 'ON_ERROR_STOP=1', '-d', 'postgres', '-c', $sql]);
        if (! $sonuc->successful()) {
            throw new RuntimeException('Yönetici SQL başarısız: '.$sonuc->errorOutput());
        }

        return trim($sonuc->output());
    }

    private function rolleriKaldir(): void
    {
        try {
            foreach ([self::SVC, self::DIGER, self::YABANCI, self::APP] as $rol) {
                $this->yonetici("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$rol}') THEN EXECUTE 'DROP OWNED BY {$rol} CASCADE'; EXECUTE 'DROP ROLE {$rol}'; END IF; END \$\$;");
            }
        } catch (Throwable $hata) {
            fwrite(STDERR, $hata->getMessage());
        }
    }
}
