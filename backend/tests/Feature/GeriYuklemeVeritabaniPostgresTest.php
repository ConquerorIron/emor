<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Services\Yedekleme\AnlikGoruntu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYuklemeVeritabani;
use App\Services\Yedekleme\KaynakVeritabani;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\PgAraclari;
use App\Services\Yedekleme\PostgresKaynakVeritabani;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;
use Throwable;

/**
 * Yazıcı bariyeri ve bağlanma yetkileri GERÇEK PostgreSQL'de (BY-026-R1,
 * BY-028-R1, BY-028-R4). Geçici roller (`emor_t<rastgele>_*`) ve veritabanları
 * (`erp_ajan_a4_*`, `erp_geri_*_a4*`) yerel sunucuda yönetici (`sudo -n -u
 * postgres`) ile açılır, test sonunda silinir; uygulamanın veritabanlarına
 * dokunulmaz.
 *
 * Uygulamanın kendi tabloları (yedek kaydı) test veritabanındadır
 * (LazilyRefreshDatabase); "canlı" ayrı, geçici veritabanıdır.
 *
 * Yalnız açıkça istenince çalışır: `YEDEKLEME_GERCEK_PG=1 php artisan test
 * --filter=GeriYuklemeVeritabaniPostgresTest` (CI'da PostgreSQL yöneticisi yok).
 */
final class GeriYuklemeVeritabaniPostgresTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    /**
     * Geçici roller sunucu genelidir: adları her koşuda benzersiz (aynı
     * sunucuda eşzamanlı koşular birbirinin rolünü silmesin).
     */
    private string $rolApp;

    private string $rolSvc;

    private string $rolDiger;

    private string $rolYabanci;

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

        $ek = bin2hex(random_bytes(4));
        [$this->rolApp, $this->rolSvc, $this->rolDiger, $this->rolYabanci] = ["emor_t{$ek}_app", "emor_t{$ek}_svc", "emor_t{$ek}_diger", "emor_t{$ek}_yabanci"];
        $this->klasor = sys_get_temp_dir().'/emor-pg-'.$ek;
        mkdir($this->klasor, 0700);

        foreach ([$this->rolApp, $this->rolSvc, $this->rolDiger, $this->rolYabanci] as $rol) {
            $this->parolalar[$rol] = bin2hex(random_bytes(12));
        }
        $this->yonetici(sprintf(
            "CREATE ROLE %s LOGIN PASSWORD '%s'; CREATE ROLE %s LOGIN CREATEDB PASSWORD '%s' IN ROLE %s; CREATE ROLE %s LOGIN PASSWORD '%s'; CREATE ROLE %s LOGIN PASSWORD '%s';",
            $this->rolApp, $this->parolalar[$this->rolApp], $this->rolSvc, $this->parolalar[$this->rolSvc], $this->rolApp,
            $this->rolDiger, $this->parolalar[$this->rolDiger], $this->rolYabanci, $this->parolalar[$this->rolYabanci],
        ));

        $pgpass = $this->klasor.'/pgpass';
        file_put_contents($pgpass, '127.0.0.1:5432:*:'.$this->rolSvc.':'.$this->parolalar[$this->rolSvc]."\n");
        chmod($pgpass, 0600);

        config([
            'yedekleme.baglanti' => 'yedek_test',
            'yedekleme.rol' => $this->rolSvc,
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
            $this->yedeklemeOrtaminiKaldir();
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
        $kabul = $this->baglan($canli, $this->rolApp);
        $kabul->exec('INSERT INTO t VALUES (2)');
        $acik = $this->baglan($canli, $this->rolApp);
        $acik->exec('BEGIN');
        $acik->exec('INSERT INTO t VALUES (1)');

        $vt->parolaylaCalis(fn () => $vt->bariyerKur($canli, $this->rolSvc, []));

        // Açık işlem sonlandırıldı: istemci hata görür, yazı kabul edilmedi
        $this->assertBaglantiKoptu($acik);
        // Uygulama rolü ve diğer roller yeni bağlantı açamaz; servis rolü açar
        foreach ([$this->rolApp, $this->rolDiger] as $rol) {
            $this->assertBaglanamaz($canli, $rol);
        }
        $this->baglan($canli, $this->rolSvc)->query('SELECT 1');

        // Bariyerden sonra alınan arşiv: kabul edilmiş yazı içinde, sonlandırılan yok
        $dosya = $this->klasor.'/t.sql';
        $pg = app(PgAraclari::class);
        $sonuc = $pg->parolaylaCalis(fn () => $pg->calistir(['pg_dump', '--data-only', '--table=public.t', '--file='.$dosya, ...$pg->baglanti()->argumanlar()], 60));
        $this->assertTrue($sonuc->successful(), $sonuc->errorOutput());
        $this->assertSame(['2'], $this->copySatirlari((string) file_get_contents($dosya)));

        // Uygulamanın anlık görüntü bağlantısı bariyerde servis rolüyle (parola PHP'ye girmeden) açılır
        $goruntu = $vt->servisRoluyle($this->rolSvc, (string) config('yedekleme.parola_dosyasi'), fn (): AnlikGoruntu => app(PostgresKaynakVeritabani::class)->anlikGoruntuyle(fn (AnlikGoruntu $g): AnlikGoruntu => $g));
        $this->assertSame(1, $goruntu->satirSayilari['t']);
        // Bariyerde CONNECT yalnız servis rolünde (sahibin kalan yetkileri: CREATE, TEMP)
        $this->assertSame([$this->rolApp.'=CT/'.$this->rolApp, $this->rolSvc.'=c/'.$this->rolApp], $this->ogeler($goruntu->ozellikler['baglanma_yetkileri']));

        // Geri sarma: özgün yetkiler aynen (belirli role verilen CONNECT dahil)
        $vt->parolaylaCalis(fn () => $vt->yetkileriUygula($canli, $ozgun));
        $this->assertSame($this->ogeler($ozgun), $this->ogeler($vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($canli))));
        $this->baglan($canli, $this->rolApp)->query('SELECT 1');
        $this->baglan($canli, $this->rolDiger)->query('SELECT 1');
        $this->assertBaglanamaz($canli, $this->rolYabanci);
    }

    /**
     * BY-028-R4: bağlantı adresle (DB_URL) tanımlı; ayrı alanlar config/database.php
     * varsayılanında. Bariyer CONNECT'i uygulama rolünden aldıktan sonra öncesi
     * yedek (anlık görüntü + pg_dump + doğrulama) servis rolüyle tamamlanır.
     */
    public function test_db_url_li_kurulumda_bariyer_altinda_oncesi_yedek_servis_roluyle_tamamlanir(): void
    {
        $pgpass = (string) config('yedekleme.parola_dosyasi');
        $this->yedeklemeOrtaminiKur();
        $this->app->instance(KaynakVeritabani::class, app(PostgresKaynakVeritabani::class));
        config(['yedekleme.parola_dosyasi' => $pgpass]);
        $canli = $this->canliAc(ozelYetki: false);
        $this->baglan($canli, $this->rolApp)->exec('CREATE TABLE migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch int NOT NULL); INSERT INTO t VALUES (7), (8)');
        config(['database.connections.yedek_test' => [
            'driver' => 'pgsql', 'url' => sprintf('postgresql://%s:%s@127.0.0.1:5432/%s', $this->rolApp, $this->parolalar[$this->rolApp], $canli),
            'host' => '127.0.0.1', 'port' => '5432', 'database' => 'laravel', 'username' => 'root', 'password' => '',
            'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer',
        ]]);
        DB::purge('yedek_test');
        $vt = app(GeriYuklemeVeritabani::class);

        $vt->parolaylaCalis(fn () => $vt->bariyerKur($canli, $this->rolSvc, []));
        $this->assertBaglanamaz($canli, $this->rolApp);

        $oncesi = $vt->servisRoluyle($this->rolSvc, $pgpass, fn (): Yedek => app(YedekAlici::class)->al(
            $this->kilitAl(), YedekKaynagi::GeriYuklemeOncesi, 'Üstüne geri yükleme öncesi', null, [], (string) Str::uuid(), ['baglanma_yetkileri' => null],
        ));

        $this->assertSame(YedekDurumu::Tamam, $oncesi->durum, (string) $oncesi->hata_mesaji);
        $manifest = app(ManifestDeposu::class)->oku(ManifestDeposu::jsonYolu(config('yedekleme.klasor').'/'.$oncesi->dosya_adi))->manifest;
        $this->assertSame([$canli, 2], [$manifest->veritabani, $manifest->satirSayilari['t']]);
    }

    public function test_varsayilan_yetkili_canlida_bariyer_geri_sarilinca_varsayilana_esdeger_doner(): void
    {
        $canli = $this->canliAc(ozelYetki: false);
        $vt = app(GeriYuklemeVeritabani::class);
        $this->assertNull($vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($canli)));

        $vt->parolaylaCalis(fn () => $vt->bariyerKur($canli, $this->rolSvc, []));
        $this->assertBaglanamaz($canli, $this->rolYabanci);
        $vt->parolaylaCalis(fn () => $vt->yetkileriUygula($canli, null));

        // Varsayılan: sahibe her şey, PUBLIC'e bağlanma + geçici tablo
        $this->assertSame($this->ogeler('{=Tc/'.$this->rolApp.','.$this->rolApp.'=CTc/'.$this->rolApp.'}'), $this->ogeler($vt->parolaylaCalis(fn (): ?string => $vt->yetkileriOku($canli))));
        $this->baglan($canli, $this->rolYabanci)->query('SELECT 1');
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
        $this->baglan($kopya, $this->rolDiger)->query('SELECT 1');
        $this->assertBaglanamaz($kopya, $this->rolYabanci);

        // Yetkideki rol bu sunucuda yok: açık hata, kopyanın yetkileri değişmez (tek işlem)
        try {
            $vt->parolaylaCalis(fn () => $vt->yetkileriUygula($kopya, '{'.$this->rolApp.'=CTc/'.$this->rolApp.',ajan_a4_olmayan=c/'.$this->rolApp.'}'));
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
        $this->yonetici("CREATE DATABASE {$ad} OWNER ".$this->rolApp.';');
        if ($ozelYetki) {
            $this->yonetici("REVOKE ALL ON DATABASE {$ad} FROM PUBLIC; GRANT CONNECT ON DATABASE {$ad} TO ".$this->rolDiger.';');
        }
        $this->baglan($ad, $this->rolApp)->exec('CREATE TABLE t (x int)');

        config(['database.connections.yedek_test' => [
            'driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => $ad,
            'username' => $this->rolApp, 'password' => $this->parolalar[$this->rolApp], 'charset' => 'utf8', 'search_path' => 'public', 'sslmode' => 'prefer',
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
            foreach ([$this->rolSvc, $this->rolDiger, $this->rolYabanci, $this->rolApp] as $rol) {
                $this->yonetici("DO \$\$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = '{$rol}') THEN EXECUTE 'DROP OWNED BY {$rol} CASCADE'; EXECUTE 'DROP ROLE {$rol}'; END IF; END \$\$;");
            }
        } catch (Throwable $hata) {
            fwrite(STDERR, $hata->getMessage());
        }
    }
}
