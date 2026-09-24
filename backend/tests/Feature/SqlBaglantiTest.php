<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SqlBaglanti;
use App\Models\User;
use Illuminate\Database\Connectors\ConnectorInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SqlBaglantiTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        $user = User::factory()->yonetici()->create();
        $this->actingAs($user);

        return $user;
    }

    private function kayitliTanim(bool $aktif = false): SqlBaglanti
    {
        return SqlBaglanti::query()->create([
            'ortam' => 'test',
            'sunucu' => 'sql.local',
            'port' => 1433,
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
            'sifre' => 'ilk-sifre',
            'aktif' => $aktif,
        ]);
    }

    public function test_oturumsuz_erisim_engellenir(): void
    {
        $this->getJson('/api/v1/ayarlar/sql-baglantilari')->assertStatus(401);
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function yoneticiUclari(): array
    {
        $govde = ['sunucu' => 'baska.sunucu', 'veritabani' => 'X', 'kullanici_adi' => 'sa', 'sifre' => 's'];

        return [
            'listeleme' => ['GET', '/api/v1/ayarlar/sql-baglantilari', []],
            'güncelleme' => ['PUT', '/api/v1/ayarlar/sql-baglantilari/test', $govde],
            'sınama' => ['POST', '/api/v1/ayarlar/sql-baglantilari/test/sina', $govde],
            'aktif yapma' => ['POST', '/api/v1/ayarlar/sql-baglantilari/aktif', ['ortam' => 'test']],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('yoneticiUclari')]
    public function test_standart_kullanici_yonetici_uclarinda_403_alir_ve_tanim_degismez(
        string $yontem,
        string $url,
        array $govde,
    ): void {
        $tanim = $this->kayitliTanim();
        $this->actingAs(User::factory()->create());

        $this->json($yontem, $url, $govde)
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');

        $tanim->refresh();
        $this->assertSame('sql.local', $tanim->sunucu);
        $this->assertSame('ilk-sifre', $tanim->sifre);
        $this->assertFalse($tanim->aktif);
    }

    public function test_aktif_ortam_her_kullaniciya_yalniz_ortam_adini_doner(): void
    {
        $this->kayitliTanim(aktif: true);
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/aktif-ortam')
            ->assertOk()
            ->assertExactJson(['data' => ['aktif_ortam' => 'test']]);
    }

    public function test_aktif_ortam_oturumsuz_401_doner(): void
    {
        $this->getJson('/api/v1/aktif-ortam')->assertStatus(401);
    }

    public function test_bos_durumda_index_null_tanimlar_doner(): void
    {
        $this->yonetici();

        $this->getJson('/api/v1/ayarlar/sql-baglantilari')
            ->assertOk()
            ->assertJson([
                'data' => ['test' => null, 'canli' => null, 'aktif_ortam' => null],
            ]);
    }

    public function test_ilk_kayitta_sifre_zorunludur(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/sql-baglantilari/test', [
            'sunucu' => 'sql.local',
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
        ])->assertStatus(422)->assertJsonStructure(['hatalar' => ['sifre']]);
    }

    public function test_tanim_olusturulur_ve_sifre_gizli_kalir(): void
    {
        $this->yonetici();

        $yanit = $this->putJson('/api/v1/ayarlar/sql-baglantilari/test', [
            'sunucu' => 'sql.local',
            'port' => 1433,
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
            'sifre' => 'cok-gizli',
        ]);

        $yanit->assertOk()
            ->assertJsonPath('data.ortam', 'test')
            ->assertJsonPath('data.sifre_dolu', true)
            ->assertJsonMissingPath('data.sifre');

        // Şifre veritabanında düz metin durmaz (encrypted cast)
        $ham = SqlBaglanti::query()->where('ortam', 'test')->first();
        $this->assertNotNull($ham);
        $this->assertSame('cok-gizli', $ham->sifre);
        $this->assertNotSame('cok-gizli', $ham->getRawOriginal('sifre'));
    }

    public function test_guncelleme_kim_tarafindan_yapildigi_loglanir_sifre_loga_girmez(): void
    {
        Log::spy();
        $yonetici = $this->yonetici();

        $this->putJson('/api/v1/ayarlar/sql-baglantilari/test', [
            'sunucu' => 'sql.local',
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
            'sifre' => 'cok-gizli',
        ])->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $mesaj, array $baglam): bool => $baglam === [
                'ortam' => 'test',
                'kullanici_id' => $yonetici->id,
                'sunucu' => 'sql.local',
                'sifre_degisti' => true,
            ])
            ->once();
    }

    /**
     * Hedef (sunucu + port + kullanıcı) aynı kaldıkça boş şifre kayıtlıyı korur.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function ayniHedefGovdeleri(): array
    {
        return [
            'yalnız veritabanı değişti' => [['sunucu' => 'sql.local', 'veritabani' => 'ERPYENI']],
            'sunucu adı büyük harfle yazıldı' => [['sunucu' => 'SQL.LOCAL', 'veritabani' => 'ERPTEST']],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('ayniHedefGovdeleri')]
    public function test_ayni_hedefte_bos_sifre_kayitli_sifreyi_korur(array $govde): void
    {
        $this->yonetici();
        $this->kayitliTanim();

        $this->putJson('/api/v1/ayarlar/sql-baglantilari/test', [
            'port' => 1433,
            'kullanici_adi' => 'sa',
            'sifre' => '',
            ...$govde,
        ])->assertOk()->assertJsonPath('data.veritabani', $govde['veritabani']);

        $this->assertSame('ilk-sifre', SqlBaglanti::query()->where('ortam', 'test')->first()?->sifre);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function farkliHedefGovdeleri(): array
    {
        return [
            'sunucu' => [['sunucu' => 'saldirgan.example', 'port' => 1433, 'kullanici_adi' => 'sa']],
            'port' => [['sunucu' => 'sql.local', 'port' => 14330, 'kullanici_adi' => 'sa']],
            'kullanıcı' => [['sunucu' => 'sql.local', 'port' => 1433, 'kullanici_adi' => 'baska']],
        ];
    }

    /**
     * @param  array<string, mixed>  $hedef
     */
    #[DataProvider('farkliHedefGovdeleri')]
    public function test_hedef_degisince_bos_sifreyle_guncelleme_reddedilir(array $hedef): void
    {
        $this->yonetici();
        $this->kayitliTanim();

        $this->putJson('/api/v1/ayarlar/sql-baglantilari/test', [
            ...$hedef,
            'veritabani' => 'ERPTEST',
            'sifre' => '',
        ])
            ->assertStatus(422)
            ->assertJsonPath('hatalar.sifre.0', 'Sunucu, port veya kullanıcı adı değiştiğinde SQL şifresi yeniden girilmelidir.');

        $tanim = SqlBaglanti::query()->where('ortam', 'test')->firstOrFail();
        $this->assertSame('sql.local', $tanim->sunucu);
        $this->assertSame(1433, $tanim->port);
        $this->assertSame('sa', $tanim->kullanici_adi);
    }

    /**
     * Kayıtlı şifre formdan yazılan başka bir sunucuya gönderilmez: istek
     * bağlantı denenmeden şifre hatasıyla döner (bağlantı denenseydi hata
     * `sunucu` alanında "Bağlantı kurulamadı" olurdu).
     *
     * @param  array<string, mixed>  $hedef
     */
    #[DataProvider('farkliHedefGovdeleri')]
    public function test_hedef_degisince_bos_sifreyle_sinama_baglanti_denemeden_reddedilir(array $hedef): void
    {
        $this->yonetici();
        $this->kayitliTanim();

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/test/sina', [...$hedef, 'sifre' => ''])
            ->assertStatus(422)
            ->assertJsonPath('hatalar.sifre.0', 'Sunucu, port veya kullanıcı adı değiştiğinde SQL şifresi yeniden girilmelidir.')
            ->assertJsonMissingPath('hatalar.sunucu');
    }

    public function test_port_bosaltilinca_bos_sifreyle_sinama_baglanti_denemeden_422_doner(): void
    {
        $this->yonetici();
        $tanim = $this->kayitliTanim();
        $this->mssqlConnector()->shouldNotReceive('connect');

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/test/sina', ['port' => null])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', 'Sunucu, port veya kullanıcı adı değiştiğinde SQL şifresi yeniden girilmelidir.');

        $this->assertSame(1433, $tanim->refresh()->port);
    }

    /** @return array<string, array{array<string, mixed>, int|null, string}> */
    public static function sinamaGovdeleri(): array
    {
        return [
            'kayıtlı tanım' => [[], 1433, 'ilk-sifre'],
            'port boşaltıldı ve şifre yeniden girildi' => [['port' => null, 'sifre' => 'yeni-sifre'], null, 'yeni-sifre'],
        ];
    }

    /** @param array<string, mixed> $govde */
    #[DataProvider('sinamaGovdeleri')]
    public function test_sinama_istenen_baglanti_ile_basarili_olur_ve_tanimi_degistirmez(
        array $govde,
        ?int $beklenenPort,
        string $beklenenSifre,
    ): void {
        $this->yonetici();
        $tanim = $this->kayitliTanim();
        $statement = Mockery::mock(PDOStatement::class);
        $statement->shouldReceive('setFetchMode')->once()->with(PDO::FETCH_OBJ)->andReturnTrue();
        $statement->shouldReceive('execute')->once()->with()->andReturnTrue();
        $statement->shouldReceive('fetchAll')->once()->with()->andReturn([
            (object) ['surum' => "SQL Server test\nAyrıntı", 'veritabani' => 'ERPTEST', 'kullanici' => 'sa'],
        ]);
        $pdo = Mockery::mock(PDO::class);
        $pdo->shouldReceive('prepare')->once()
            ->with('SELECT @@VERSION AS surum, DB_NAME() AS veritabani, SUSER_SNAME() AS kullanici')
            ->andReturn($statement);
        $this->mssqlConnector()->shouldReceive('connect')->once()
            ->with(Mockery::on(fn (array $config): bool => $config['host'] === 'sql.local'
                && ($config['port'] ?? null) === $beklenenPort
                && $config['database'] === 'ERPTEST'
                && $config['username'] === 'sa'
                && $config['password'] === $beklenenSifre))
            ->andReturn($pdo);

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/test/sina', $govde)
            ->assertOk()
            ->assertExactJson(['data' => ['surum' => 'SQL Server test', 'veritabani' => 'ERPTEST', 'kullanici' => 'sa']]);

        $tanim->refresh();
        $this->assertSame(1433, $tanim->port);
        $this->assertSame('ilk-sifre', $tanim->sifre);
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function dsnEnjeksiyonuIstekleri(): array
    {
        return [
            'güncellemede veritabanından hedef değiştirme' => ['PUT', '/api/v1/ayarlar/sql-baglantilari/test', 'veritabani', 'ERPTEST;Server=baska.example', ''],
            'sınamada veritabanından hedef değiştirme' => ['POST', '/api/v1/ayarlar/sql-baglantilari/test/sina', 'veritabani', 'ERPTEST;Server=baska.example', ''],
            'güncellemede sunucuya seçenek ekleme' => ['PUT', '/api/v1/ayarlar/sql-baglantilari/test', 'sunucu', 'sql.local;Database=ERP', 'yeni-sifre'],
            'sınamada sunucuya seçenek ekleme' => ['POST', '/api/v1/ayarlar/sql-baglantilari/test/sina', 'sunucu', 'sql.local;Database=ERP', 'yeni-sifre'],
            'süslü parantez' => ['PUT', '/api/v1/ayarlar/sql-baglantilari/test', 'veritabani', '{ERPTEST}', ''],
            'kontrol karakteri' => ['PUT', '/api/v1/ayarlar/sql-baglantilari/test', 'veritabani', "ERP\0TEST", ''],
        ];
    }

    #[DataProvider('dsnEnjeksiyonuIstekleri')]
    public function test_dsn_karakterleri_kayit_ve_baglanti_oncesinde_422_ile_reddedilir(
        string $yontem,
        string $url,
        string $alan,
        string $deger,
        string $sifre,
    ): void {
        $this->yonetici();
        $tanim = $this->kayitliTanim();
        $this->mssqlConnector()->shouldNotReceive('connect');

        $this->json($yontem, $url, [
            'sunucu' => 'sql.local',
            'port' => 1433,
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
            'sifre' => $sifre,
            $alan => $deger,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.'.$alan.'.0', 'Sunucu ve veritabanı adında noktalı virgül, süslü parantez veya kontrol karakteri kullanılamaz.');

        $tanim->refresh();
        $this->assertSame('sql.local', $tanim->sunucu);
        $this->assertSame('ERPTEST', $tanim->veritabani);
        $this->assertSame('ilk-sifre', $tanim->sifre);
    }

    private function mssqlConnector(): MockInterface
    {
        $connector = $this->mock(ConnectorInterface::class);
        $this->app->instance('db.connector.sqlsrv', $connector);

        return $connector;
    }

    public function test_gecersiz_ortam_404_doner(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/sql-baglantilari/staging', [
            'sunucu' => 'x',
            'veritabani' => 'y',
            'kullanici_adi' => 'z',
            'sifre' => 's',
        ])->assertStatus(404);
    }

    public function test_aktif_ortam_degistirilir_ve_tek_aktif_kalir(): void
    {
        $this->yonetici();

        foreach (['test', 'canli'] as $ortam) {
            SqlBaglanti::query()->create([
                'ortam' => $ortam,
                'sunucu' => 'sql.local',
                'veritabani' => 'ERP',
                'kullanici_adi' => 'sa',
                'sifre' => 's',
            ]);
        }

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/aktif', ['ortam' => 'test'])
            ->assertOk()
            ->assertJsonPath('data.ortam', 'test')
            ->assertJsonPath('data.aktif', true);

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/aktif', ['ortam' => 'canli'])
            ->assertOk()
            ->assertJsonPath('data.aktif', true);

        $this->assertSame(1, SqlBaglanti::query()->where('aktif', true)->count());
        $this->assertSame('canli', SqlBaglanti::query()->where('aktif', true)->first()?->ortam);

        $this->getJson('/api/v1/ayarlar/sql-baglantilari')
            ->assertOk()
            ->assertJsonPath('data.aktif_ortam', 'canli');
    }

    public function test_tanimsiz_ortam_aktif_yapilamaz(): void
    {
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/aktif', ['ortam' => 'test'])
            ->assertStatus(422);
    }
}
