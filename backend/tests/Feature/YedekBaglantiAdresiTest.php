<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\GeriYuklemeVeritabani;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YeniVeritabanindaMigration;
use Illuminate\Database\Connectors\PostgresConnector;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-028-R4: bağlantısı adresle (DB_URL) tanımlı kurulum. Laravel bağlantıyı
 * açarken adresteki kullanıcı / parola / veritabanını ayrı alanların üstüne
 * yazar; yedeklemenin bağlantıyı değiştiren her yolu adresi önce somut tanıma
 * çevirmeli. Gerçek PostgreSQL'de bariyer altındaki öncesi yedek:
 * GeriYuklemeVeritabaniPostgresTest.
 */
final class YedekBaglantiAdresiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        // config/database.php'nin DB_URL'li kurulumdaki hâli: ayrı alanlar varsayılanda kalır
        config(['database.connections.yedek_test' => [
            'driver' => 'pgsql', 'url' => 'postgresql://app_rol:app%3Aparola@vt.ic:6432/erp_canli',
            'host' => '127.0.0.1', 'port' => '5432', 'database' => 'laravel', 'username' => 'root', 'password' => '',
            'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]]);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    public function test_servis_rolune_gecen_baglanti_adresteki_uygulama_rolunu_ve_parolasini_tasimaz(): void
    {
        $once = config('database.connections.yedek_test');

        // Bağlantı tembeldir: Laravel'in açacağı tanım sunucuya gitmeden okunur
        $gorulen = app(GeriYuklemeVeritabani::class)->servisRoluyle('emor_yedek', '/kok/pgpass', function (): array {
            DB::purge('yedek_test');
            $baglanti = DB::connection('yedek_test');

            return [$baglanti->getConfig('username'), $baglanti->getConfig('password'), $baglanti->getDatabaseName(), $baglanti->getConfig('host'), $baglanti->getConfig('port')];
        });
        DB::purge('yedek_test');

        $this->assertSame(['emor_yedek', null, 'erp_canli', 'vt.ic', 6432], $gorulen);
        $this->assertSame($once, config('database.connections.yedek_test'));
    }

    public function test_pg_dump_adresteki_sunucuya_veritabanina_gider_parola_dosyasi_adresteki_parolayi_tasir(): void
    {
        $parolaSatiri = null;
        $this->sahtePgAraclari([
            '*pg_dump*--file=*' => function (PendingProcess $surec) use (&$parolaSatiri): string {
                $parolaSatiri = trim((string) file_get_contents($surec->environment['PGPASSFILE']));
                $this->dosyaArgumaninaYaz($surec, 'PGDMP-sahte');

                return '';
            },
        ]);

        $yedek = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Elle);

        $this->assertSame(YedekDurumu::Tamam, $yedek->durum, (string) $yedek->hata_mesaji);
        Process::assertRan(fn (PendingProcess $p): bool => str_ends_with((string) ((array) $p->command)[0], 'pg_dump')
            && array_intersect(['--host=vt.ic', '--port=6432', '--username=app_rol', '--dbname=erp_canli'], (array) $p->command) === ['--host=vt.ic', '--port=6432', '--username=app_rol', '--dbname=erp_canli']);
        // Adresteki parola (yüzde kodu çözülmüş, `:` kaçışlı) — ham tanımın boş parolası değil
        $this->assertSame('vt.ic:6432:*:app_rol:app\\:parola', $parolaSatiri);
    }

    public function test_migration_yapilandirmasi_adresli_baglantiyi_yeni_veritabanina_cevirir_adres_kalmaz(): void
    {
        $klasor = $this->yedekKoku.'/migration-deneme';
        mkdir($klasor, 0700);

        $dosya = app(YeniVeritabanindaMigration::class)->yapilandirmaDosyasiYaz('erp_geri_20261121_103000', $klasor);
        $tanim = (require $dosya)['database']['connections']['yedek_test'];

        $this->assertArrayNotHasKey('url', $tanim);
        $this->assertSame(['erp_geri_20261121_103000', 'vt.ic', 6432, 'app_rol'], [$tanim['database'], $tanim['host'], $tanim['port'], $tanim['username']]);
    }

    /**
     * BY-028-R6: Laravel'in bağlayıcısı DSN'i `database` / `port`'tan önce
     * `connect_via_*`'dan, okuma / yazma / doğrudan alt tanımlarından kurar.
     * Gerçek bağlantı (current_database): GeriYuklemeVeritabaniPostgresTest.
     */
    public function test_migration_yapilandirmasi_dsn_yi_yonlendiren_alanlari_kaldirir_canliyi_gosteren_her_baglanti_yeni_veritabanina_gider(): void
    {
        $hedef = 'erp_geri_20261121_103000';
        config([
            'database.connections.yedek_test.url' => 'postgresql://app_rol:app%3Aparola@vt.ic:6432/erp_canli?connect_via_database=erp_canli&connect_via_port=6543',
            'database.connections.yedek_test.read' => ['host' => 'okuma.ic'],
            'database.connections.yedek_test.direct' => ['host' => 'dogrudan.ic', 'database' => 'erp_canli'],
            'database.connections.yedek_test.pooled' => true,
            // Canlıyı yalnız yazma alt tanımında gösteren bağlantı da çevrilir
            'database.connections.yan' => ['driver' => 'pgsql', 'host' => 'vt.ic', 'database' => 'baska', 'read' => ['host' => 'okuma.ic'], 'write' => [['database' => 'erp_canli']]],
            // Canlıyı göstermeyen bağlantıya dokunulmaz
            'database.connections.ilgisiz' => ['driver' => 'pgsql', 'host' => 'vt.ic', 'database' => 'baska', 'connect_via_database' => 'havuz'],
        ]);
        $klasor = $this->yedekKoku.'/migration-deneme';
        mkdir($klasor, 0700);

        $baglantilar = (require app(YeniVeritabanindaMigration::class)->yapilandirmaDosyasiYaz($hedef, $klasor))['database']['connections'];

        $dsn = new class extends PostgresConnector
        {
            /** @param  array<string, mixed>  $tanim */
            public function uret(array $tanim): string
            {
                return $this->getDsn($tanim);
            }
        };
        foreach (['yedek_test', 'yan'] as $ad) {
            $this->assertSame([], array_intersect(['connect_via_database', 'connect_via_port', 'read', 'write', 'direct', 'pooled'], array_keys($baglantilar[$ad])), $ad);
            $this->assertStringContainsString("dbname='{$hedef}'", $dsn->uret($baglantilar[$ad]), $ad);
        }
        // pg_restore'un kullandığı uç nokta (adresteki port), havuzun portu değil
        $this->assertStringStartsWith("pgsql:host=vt.ic;dbname='{$hedef}';port=6432;", $dsn->uret($baglantilar['yedek_test']));
        $this->assertSame(config('database.connections.ilgisiz'), $baglantilar['ilgisiz']);
    }
}
