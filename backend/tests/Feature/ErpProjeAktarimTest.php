<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Proje;
use App\Models\Rol;
use App\Models\SqlBaglanti;
use App\Models\User;
use App\Services\ErpOkumaHatasi;
use App\Services\Kesinti\ErpProjeKaynagi;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\TestCase;

/**
 * Projeler → ERP'den Getir: önizleme durumları ve seçilenlerin aktarımı.
 * ERP sahte kaynakla taklit edilir; gerçek sorgunun yalnız canlı tanımı
 * denetimi (ERP'ye gitmeden) burada sınanır.
 */
final class ErpProjeAktarimTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/projeler/erp';

    private const G_AYNI = '11111111-1111-4111-8111-111111111111';

    private const G_DEGISEN = '22222222-2222-4222-8222-222222222222';

    private const G_ELLE_ACILMIS = '33333333-3333-4333-8333-333333333333';

    private const G_CAKISAN = '44444444-4444-4444-8444-444444444444';

    private const G_YENI = '55555555-5555-4555-8555-555555555555';

    private const G_TEKRAR_KOD_1 = '66666666-6666-4666-8666-666666666666';

    private const G_TEKRAR_KOD_2 = '77777777-7777-4777-8777-777777777777';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
    }

    /**
     * @param  list<array{kod: string, ad: string, guid: string}>  $satirlar
     */
    private function erpProjeleri(array $satirlar): void
    {
        $this->app->instance(ErpProjeKaynagi::class, new class($satirlar) implements ErpProjeKaynagi
        {
            /**
             * @param  list<array{kod: string, ad: string, guid: string}>  $satirlar
             */
            public function __construct(private readonly array $satirlar) {}

            public function projeler(): array
            {
                return $this->satirlar;
            }
        });
    }

    private function erpOkunamaz(ErpOkumaHatasi $hata): void
    {
        $this->app->instance(ErpProjeKaynagi::class, new class($hata) implements ErpProjeKaynagi
        {
            public function __construct(private readonly ErpOkumaHatasi $hata) {}

            public function projeler(): array
            {
                throw $this->hata;
            }
        });
    }

    /**
     * Uygulamadaki projeler + ERP: her önizleme durumundan bir örnek.
     */
    private function karisikDurum(): void
    {
        Proje::factory()->create(['kod' => 'A1', 'ad' => 'Aynı Proje', 'erp_guid' => self::G_AYNI]);
        Proje::factory()->create(['kod' => 'B1', 'ad' => 'Eski Ad', 'erp_guid' => self::G_DEGISEN]);
        Proje::factory()->create(['kod' => 'C1', 'ad' => 'Elle Açılan', 'erp_guid' => null]);
        Proje::factory()->create(['kod' => 'D1', 'ad' => 'Başka ERP Projesi', 'erp_guid' => '99999999-9999-4999-8999-999999999999']);

        $this->erpProjeleri([
            ['kod' => 'A1', 'ad' => 'Aynı Proje', 'guid' => self::G_AYNI],
            ['kod' => 'B1', 'ad' => 'Yeni Ad', 'guid' => self::G_DEGISEN],
            // ERP kodu küçük harfli ve GUID süslü parantezli/büyük harfli gelebilir
            ['kod' => 'c1', 'ad' => 'ERP Adı', 'guid' => '{'.strtoupper(self::G_ELLE_ACILMIS).'}'],
            ['kod' => 'D1', 'ad' => 'Çakışan', 'guid' => self::G_CAKISAN],
            ['kod' => 'E1', 'ad' => 'Yeni Proje', 'guid' => self::G_YENI],
            ['kod' => 'F1', 'ad' => 'Tekrar 1', 'guid' => self::G_TEKRAR_KOD_1],
            ['kod' => 'F1', 'ad' => 'Tekrar 2', 'guid' => self::G_TEKRAR_KOD_2],
            ['kod' => 'G1', 'ad' => 'Bozuk GUID', 'guid' => 'gecersiz'],
            ['kod' => str_repeat('H', 51), 'ad' => 'Uzun Kod', 'guid' => '88888888-8888-4888-8888-888888888888'],
        ]);
    }

    public function test_onizleme_her_erp_projesinin_aktarimda_ne_olacagini_doner(): void
    {
        $this->karisikDurum();

        $yanit = $this->getJson(self::ADRES)->assertOk()->assertJsonCount(9, 'data');

        /** @var list<array{guid: string, kod: string, durum: string, neden: string|null, mevcut_ad: string|null}> $satirlar */
        $satirlar = $yanit->json('data');
        $durumlar = array_map(fn (array $satir): string => "{$satir['kod']}:{$satir['durum']}:".($satir['neden'] ?? '-'), $satirlar);

        $this->assertSame([
            'A1:ayni:-',
            'B1:guncellenecek:-',
            'C1:eslesecek:-',
            'D1:cakisma:kod_baska_projede',
            'E1:yeni:-',
            'F1:gecersiz:kod_tekrar',
            'F1:gecersiz:kod_tekrar',
            'G1:gecersiz:guid_gecersiz',
            str_repeat('H', 51).':gecersiz:kod_uzun',
        ], $durumlar);

        $degisen = $satirlar[1];
        $this->assertSame('Eski Ad', $degisen['mevcut_ad']);
        $this->assertSame(self::G_ELLE_ACILMIS, $satirlar[2]['guid']);
        $this->assertArrayNotHasKey('proje_id', $degisen);
    }

    public function test_aktarim_yalniz_secilen_ve_uygulanabilir_projeleri_erpdeki_veriyle_kaydeder(): void
    {
        $this->karisikDurum();

        $this->postJson(self::ADRES, ['guidler' => [
            self::G_DEGISEN,
            strtoupper(self::G_ELLE_ACILMIS),
            self::G_YENI,
            // Değişikliği olmayan ve çakışan seçilse de uygulanmaz
            self::G_AYNI,
            self::G_CAKISAN,
        ]])
            ->assertOk()
            ->assertJsonPath('data', ['eklenen' => 1, 'guncellenen' => 1, 'eslesen' => 1, 'atlanan' => 2]);

        $this->assertDatabaseHas('projeler', ['kod' => 'B1', 'ad' => 'Yeni Ad', 'erp_guid' => self::G_DEGISEN]);
        $this->assertDatabaseHas('projeler', ['kod' => 'C1', 'ad' => 'ERP Adı', 'erp_guid' => self::G_ELLE_ACILMIS]);
        $this->assertDatabaseHas('projeler', ['kod' => 'E1', 'ad' => 'Yeni Proje', 'erp_guid' => self::G_YENI]);
        $this->assertDatabaseHas('projeler', ['kod' => 'D1', 'ad' => 'Başka ERP Projesi']);
        $this->assertDatabaseMissing('projeler', ['kod' => 'F1']);
        $this->assertDatabaseCount('projeler', 5);
    }

    public function test_aktarimda_secilmeyen_yeni_proje_eklenmez(): void
    {
        $this->erpProjeleri([
            ['kod' => 'E1', 'ad' => 'Seçilen', 'guid' => self::G_YENI],
            ['kod' => 'E2', 'ad' => 'Seçilmeyen', 'guid' => self::G_AYNI],
        ]);

        $this->postJson(self::ADRES, ['guidler' => [self::G_YENI]])
            ->assertOk()
            ->assertJsonPath('data.eklenen', 1);

        $this->assertSame(['E1'], Proje::query()->pluck('kod')->all());
    }

    public function test_aktarim_gecersiz_guidi_422_ile_reddeder(): void
    {
        $this->erpProjeleri([]);

        $this->postJson(self::ADRES, ['guidler' => ['gecersiz']])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('guidler.0', 'hatalar');
    }

    public function test_erp_okuma_yetkisi_yoksa_502_ve_anlasilir_kod_doner(): void
    {
        $pdo = new PDOException('SQLSTATE[42000]: The SELECT permission was denied on the object TOHOM_PROJEMIZ');
        $pdo->errorInfo = ['42000', 229, 'The SELECT permission was denied on the object TOHOM_PROJEMIZ'];
        $this->erpOkunamaz(ErpOkumaHatasi::sorgudan(new QueryException('erp', 'SELECT 1', [], $pdo)));

        $this->getJson(self::ADRES)
            ->assertStatus(502)
            ->assertJsonPath('kod', 'ERP_OKUMA_YETKISI_YOK')
            ->assertJsonPath('mesaj', 'ERP veritabanı kullanıcısının bu veriyi okuma yetkisi yok. Veritabanı yöneticisinden okuma (SELECT) yetkisi isteyin.');
    }

    public function test_erp_baglanti_hatasi_genel_okuma_hatasi_olarak_doner_ve_hicbir_sey_yazilmaz(): void
    {
        $pdo = new PDOException('SQLSTATE[08001]: Login timeout expired');
        $pdo->errorInfo = ['08001', 0, 'Login timeout expired'];
        $this->erpOkunamaz(ErpOkumaHatasi::sorgudan(new QueryException('erp', 'SELECT 1', [], $pdo)));

        $this->postJson(self::ADRES, ['guidler' => [self::G_YENI]])
            ->assertStatus(502)
            ->assertJsonPath('kod', 'ERP_OKUMA_HATASI');

        $this->assertDatabaseCount('projeler', 0);
    }

    public function test_canli_erp_baglantisi_tanimli_degilse_erpye_gitmeden_422_doner(): void
    {
        // Sahte kaynak bağlanmaz: gerçek ErpProjeSorgusu, aktif ortam test olsa da canlıyı ister
        SqlBaglanti::query()->create([
            'ortam' => 'test', 'sunucu' => 'sql.local', 'veritabani' => 'ERP',
            'kullanici_adi' => 'sa', 'sifre' => 's', 'aktif' => true,
        ]);

        $this->getJson(self::ADRES)
            ->assertUnprocessable()
            ->assertJsonPath('kod', 'ERP_CANLI_TANIMSIZ');
    }

    public function test_yalniz_goruntuleme_izniyle_erp_aktarimi_yapilamaz(): void
    {
        $rol = Rol::query()->create(['ad' => 'Proje izleyici']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'projeler.goruntule']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);
        $this->erpProjeleri([['kod' => 'E1', 'ad' => 'Yeni', 'guid' => self::G_YENI]]);

        $this->actingAs($kullanici)->getJson(self::ADRES)->assertForbidden();
        $this->actingAs($kullanici)->postJson(self::ADRES, ['guidler' => [self::G_YENI]])->assertForbidden();

        $this->assertDatabaseCount('projeler', 0);
    }
}
