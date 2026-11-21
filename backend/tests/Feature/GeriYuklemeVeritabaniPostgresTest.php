<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\AnlikGoruntu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYuklemeVeritabani;
use App\Services\Yedekleme\PgAraclari;
use App\Services\Yedekleme\PostgresKaynakVeritabani;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PDO;
use PDOException;
use Tests\TestCase;
use Throwable;

/**
 * Yazıcı bariyeri ve bağlanma yetkileri GERÇEK PostgreSQL'de (BY-026-R1,
 * BY-028-R1). Geçici roller (`ajan_a4_*`) ve veritabanları (`erp_ajan_a4_*`,
 * `erp_geri_*_a4*`) yerel sunucuda yönetici (`sudo -n -u postgres`) ile
 * açılır, test sonunda silinir; uygulamanın veritabanlarına dokunulmaz.
 *
 * Yalnız açıkça istenince çalışır: `YEDEKLEME_GERCEK_PG=1 php artisan test
 * --filter=GeriYuklemeVeritabaniPostgresTest` (CI'da PostgreSQL yöneticisi yok).
 */
final class GeriYuklemeVeritabaniPostgresTest extends TestCase
{
    private const APP = 'ajan_a4_app';

    private const SVC = 'ajan_a4_svc';

    private const DIGER = 'ajan_a4_diger';

    private const YABANCI = 'ajan_a4_yabanci';

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

        $this->klasor = sys_get_temp_dir().'/emor-a4-pg-'.bin2hex(random_bytes(4));
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
            'yedekleme.baglanti' => 'yedek_test',
            'yedekleme.rol' => self::SVC,
            'yedekleme.parola_dosyasi' => $pgpass,
            'yedekleme.araclar' => ['pg_dump' => '/usr/bin/pg_dump', 'pg_restore' => '/usr/bin/pg_restore', 'psql' => '/usr/bin/psql'],
        ]);
    }

    protected function tearDown(): void
    {
        if (getenv('YEDEKLEME_GERCEK_PG') === '1') {
            DB::purge('yedek_test');
            DB::purge('yedekleme_anlik_goruntu');
            foreach ($this->veritabanlari as $ad) {
                $this->yonetici("DROP DATABASE IF EXISTS {$ad} WITH (FORCE);");
            }
            $this->rolleriKaldir();
            File::deleteDirectory($this->klasor);
        }

        parent::tearDown();
    }

    public function test_bariyer_once_acilmis_yaziyi_arsivden_once_sonlandirir_geri_alir_kabul_edilen_arsivde_ozgun_yetkiler_aynen_doner(): void
    {
        $canli = $this->canliAc(ozelYetki: true);
        $vt = app(GeriYuklemeVeritabani::class);
        $ozgun = $vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($canli));
        $this->assertNotNull($ozgun);

        // Bakım başlamadan açılmış iki oturum: biri yazdı ve kabul edildi (COMMIT), biri açık işlemde
        $kabul = $this->baglan($canli, self::APP);
        $kabul->exec('INSERT INTO t VALUES (2)');
        $acik = $this->baglan($canli, self::APP);
        $acik->exec('BEGIN');
        $acik->exec('INSERT INTO t VALUES (1)');

        $vt->parolaylaCalis(fn () => $vt->bariyerKur($canli, self::SVC, []));

        // Açık işlem sonlandırıldı: istemci hata görür, yazı kabul edilmedi
        $this->assertBaglantiKoptu($acik);
        // Uygulama rolü ve diğer roller yeni bağlantı açamaz; servis rolü açar
        foreach ([self::APP, self::DIGER] as $rol) {
            $this->assertBaglanamaz($canli, $rol);
        }
        $this->baglan($canli, self::SVC)->query('SELECT 1');

        // Bariyerden sonra alınan arşiv: kabul edilmiş yazı içinde, sonlandırılan yok
        $dosya = $this->klasor.'/t.sql';
        $pg = app(PgAraclari::class);
        $sonuc = $pg->parolaylaCalis(fn () => $pg->calistir(['pg_dump', '--data-only', '--table=public.t', '--file='.$dosya, ...$pg->baglanti()->argumanlar()], 60));
        $this->assertTrue($sonuc->successful(), $sonuc->errorOutput());
        $this->assertSame(['2'], $this->copySatirlari((string) file_get_contents($dosya)));

        // Uygulamanın anlık görüntü bağlantısı bariyerde servis rolüyle (parola PHP'ye girmeden) açılır
        $goruntu = $vt->servisRoluyle(self::SVC, (string) config('yedekleme.parola_dosyasi'), fn (): AnlikGoruntu => app(PostgresKaynakVeritabani::class)->anlikGoruntuyle(fn (AnlikGoruntu $g): AnlikGoruntu => $g));
        $this->assertSame(1, $goruntu->satirSayilari['t']);
        // Bariyerde CONNECT yalnız servis rolünde (sahibin kalan yetkileri: CREATE, TEMP)
        $this->assertSame([self::APP.'=CT/'.self::APP, self::SVC.'=c/'.self::APP], $this->ogeler($goruntu->ozellikler['baglanma_yetkileri']));

        // Geri sarma: özgün yetkiler aynen (belirli role verilen CONNECT dahil)
        $vt->parolaylaCalis(fn () => $vt->yetkileriUygula($canli, $ozgun));
        $this->assertSame($this->ogeler($ozgun), $this->ogeler($vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($canli))));
        $this->baglan($canli, self::APP)->query('SELECT 1');
        $this->baglan($canli, self::DIGER)->query('SELECT 1');
        $this->assertBaglanamaz($canli, self::YABANCI);
    }

    public function test_varsayilan_yetkili_canlida_bariyer_geri_sarilinca_varsayilana_esdeger_doner(): void
    {
        $canli = $this->canliAc(ozelYetki: false);
        $vt = app(GeriYuklemeVeritabani::class);
        $this->assertNull($vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($canli)));

        $vt->parolaylaCalis(fn () => $vt->bariyerKur($canli, self::SVC, []));
        $this->assertBaglanamaz($canli, self::YABANCI);
        $vt->parolaylaCalis(fn () => $vt->yetkileriUygula($canli, null));

        // Varsayılan: sahibe her şey, PUBLIC'e bağlanma + geçici tablo
        $this->assertSame($this->ogeler('{=Tc/'.self::APP.','.self::APP.'=CTc/'.self::APP.'}'), $this->ogeler($vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($canli))));
        $this->baglan($canli, self::YABANCI)->query('SELECT 1');
    }

    public function test_kopya_kaynagin_baglanma_yetkilerine_esdeger_acilir_rol_yoksa_yetkiler_degismez(): void
    {
        $canli = $this->canliAc(ozelYetki: true);
        $vt = app(GeriYuklemeVeritabani::class);
        $kopya = 'erp_geri_20261121_000000_a4'.bin2hex(random_bytes(3));
        $this->veritabanlari[] = $kopya;

        $kaynak = $vt->parolaylaCalis(function () use ($vt, $canli, $kopya): ?string {
            $acl = $vt->yetkileriOku($canli);
            $vt->olustur($kopya, $vt->kaynakOzellikleri());
            $vt->yetkileriUygula($kopya, $acl);

            return $acl;
        });

        $this->assertSame($this->ogeler($kaynak), $this->ogeler($vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($kopya))));
        $this->baglan($kopya, self::DIGER)->query('SELECT 1');
        $this->assertBaglanamaz($kopya, self::YABANCI);

        // Yetkideki rol bu sunucuda yok: açık hata, kopyanın yetkileri değişmez (tek işlem)
        try {
            $vt->parolaylaCalis(fn () => $vt->yetkileriUygula($kopya, '{'.self::APP.'=CTc/'.self::APP.',ajan_a4_olmayan=c/'.self::APP.'}'));
            $this->fail('Olmayan role yetki uygulanmış sayıldı.');
        } catch (GeriYuklemeHatasi $hata) {
            $this->assertSame(GeriYuklemeHatasi::VERITABANI_YETKILERI, $hata->kod);
        }
        $this->assertSame($this->ogeler($kaynak), $this->ogeler($vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($kopya))));
    }

    /**
     * "Canlı": sahibi uygulama rolü; özel yetkide PUBLIC'in bağlanması kaldırılıp
     * yalnız belirli bir role (DIGER) verilmiş.
     */
    private function canliAc(bool $ozelYetki): string
    {
        $ad = 'erp_ajan_a4_'.bin2hex(random_bytes(3));
        $this->veritabanlari[] = $ad;
        $this->yonetici("CREATE DATABASE {$ad} OWNER ".self::APP.';');
        if ($ozelYetki) {
            $this->yonetici("REVOKE ALL ON DATABASE {$ad} FROM PUBLIC; GRANT CONNECT ON DATABASE {$ad} TO ".self::DIGER.';');
        }
        $this->baglan($ad, self::APP)->exec('CREATE TABLE t (x int)');

        config(['database.connections.yedek_test' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => $ad,
            'username' => self::APP, 'password' => $this->parolalar[self::APP], 'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]]);
        DB::purge('yedek_test');

        return $ad;
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

    private function assertBaglantiKoptu(PDO $baglanti): void
    {
        try {
            $baglanti->exec('COMMIT');
            $this->fail('Sonlandırılması gereken oturum işlemini tamamladı.');
        } catch (PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    /**
     * @return list<string> `COPY … FROM stdin` bloğundaki satırlar
     */
    private function copySatirlari(string $dokum): array
    {
        if (preg_match('/^COPY public\.t .*?FROM stdin;\n(.*?)^\\\\\.$/ms', $dokum, $m) !== 1) {
            return [];
        }

        return array_values(array_filter(explode("\n", $m[1]), fn (string $s): bool => $s !== ''));
    }

    /**
     * ACL metninin öğeleri (sırasız; veren dahil).
     *
     * @return list<string>
     */
    private function ogeler(?string $acl): array
    {
        $ogeler = $acl === null || $acl === '{}' ? [] : explode(',', trim($acl, '{}'));
        sort($ogeler);

        return $ogeler;
    }

    private function yonetici(string $sql): void
    {
        $sonuc = Process::timeout(60)->run(['sudo', '-n', '-u', 'postgres', 'psql', '-XAtq', '-v', 'ON_ERROR_STOP=1', '-d', 'postgres', '-c', $sql]);
        if (! $sonuc->successful()) {
            throw new \RuntimeException('Yönetici SQL başarısız: '.$sonuc->errorOutput());
        }
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
