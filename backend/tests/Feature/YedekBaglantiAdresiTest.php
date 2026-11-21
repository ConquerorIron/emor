<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\GeriYuklemeVeritabani;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YeniVeritabanindaMigration;
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
}
