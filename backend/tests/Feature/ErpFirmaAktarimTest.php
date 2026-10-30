<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\Rol;
use App\Models\User;
use App\Services\Kesinti\ErpFirmaKaynagi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Firmalar → ERP'den Getir: GUID ya da VKN ile eşleşme, önizleme durumları
 * ve seçilenlerin aktarımı. Ortak akış (seçim, ERP hataları, canlı tanımı)
 * ErpProjeAktarimTest'te sınanır.
 */
final class ErpFirmaAktarimTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/firmalar/erp';

    private const G_AYNI = '11111111-1111-4111-8111-111111111111';

    private const G_DEGISEN = '22222222-2222-4222-8222-222222222222';

    private const G_VKN_ILE = '33333333-3333-4333-8333-333333333333';

    private const G_VKN_BASKA_ERPDE = '44444444-4444-4444-8444-444444444444';

    private const G_KOD_CAKISAN = '55555555-5555-4555-8555-555555555555';

    private const G_YENI = '66666666-6666-4666-8666-666666666666';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
    }

    /**
     * @param  array<string, string|null>  $ek
     * @return array{kod: string, ad: string, guid: string, vergi_kimlik_no: string|null, vergi_dairesi: string|null, eposta: string|null, gsm_no: string|null}
     */
    private static function erpFirmasi(string $kod, string $ad, string $guid, ?string $vkn, array $ek = []): array
    {
        return [...['kod' => $kod, 'ad' => $ad, 'guid' => $guid, 'vergi_kimlik_no' => $vkn, 'vergi_dairesi' => null, 'eposta' => null, 'gsm_no' => null], ...$ek];
    }

    /**
     * @param  list<array<string, string|null>>  $satirlar
     */
    private function erpFirmalari(array $satirlar): void
    {
        $this->app->instance(ErpFirmaKaynagi::class, new class($satirlar) implements ErpFirmaKaynagi
        {
            /**
             * @param  list<array<string, string|null>>  $satirlar
             */
            public function __construct(private readonly array $satirlar) {}

            public function firmalar(): array
            {
                /** @var list<array{kod: string, ad: string, guid: string, vergi_kimlik_no: string|null, vergi_dairesi: string|null, eposta: string|null, gsm_no: string|null}> */
                return $this->satirlar;
            }
        });
    }

    private function karisikDurum(): void
    {
        Firma::factory()->create(['kod' => 'A1', 'ad' => 'A Aynı', 'vergi_kimlik_no' => '1000000001', 'erp_guid' => self::G_AYNI]);
        Firma::factory()->create(['kod' => 'B1', 'ad' => 'B Değişen', 'vergi_kimlik_no' => '1000000002', 'erp_guid' => self::G_DEGISEN]);
        // Elle açılmış, kodu ERP'dekinden farklı: VKN ile bağlanır
        Firma::factory()->create(['kod' => 'ELLE-C', 'ad' => 'C Elle', 'vergi_kimlik_no' => '1000000003', 'erp_guid' => null]);
        // Aynı VKN başka bir ERP firmasına bağlı
        Firma::factory()->create(['kod' => 'D1', 'ad' => 'D Başka', 'vergi_kimlik_no' => '1000000004', 'erp_guid' => '99999999-9999-4999-8999-999999999999']);
        Firma::factory()->create(['kod' => 'E1', 'ad' => 'E Kodu Dolu', 'vergi_kimlik_no' => '1000000005', 'erp_guid' => null]);

        $this->erpFirmalari([
            self::erpFirmasi('A1', 'A Aynı', self::G_AYNI, '1000000001'),
            self::erpFirmasi('B1', 'B Değişen', self::G_DEGISEN, '1000000002', ['eposta' => 'yeni@b.test']),
            // VKN'de bölünmez boşluk: temizlenip eşleşir
            self::erpFirmasi('C1', 'C ERP', self::G_VKN_ILE, "1000000003\u{00A0}", ['vergi_dairesi' => 'Kadıköy']),
            self::erpFirmasi('D2', 'D ERP', self::G_VKN_BASKA_ERPDE, '1000000004'),
            self::erpFirmasi('E1', 'E ERP', self::G_KOD_CAKISAN, '2000000005'),
            self::erpFirmasi('F1', 'F Yeni', self::G_YENI, 'GBN155HD11', ['gsm_no' => '0532']),
            self::erpFirmasi('G1', 'G Tekrar 1', '77777777-7777-4777-8777-777777777777', '3000000000'),
            self::erpFirmasi('G2', 'G Tekrar 2', '88888888-8888-4888-8888-888888888888', '3000000000'),
            self::erpFirmasi('H1', str_repeat('H', 201), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', null),
        ]);
    }

    public function test_onizleme_guid_ya_da_vkn_ile_eslesmeyi_ve_cakismalari_gosterir(): void
    {
        $this->karisikDurum();

        /** @var list<array{kod: string, durum: string, neden: string|null, mevcut: array<string, string|null>|null, vergi_kimlik_no: string|null}> $satirlar */
        $satirlar = $this->getJson(self::ADRES)->assertOk()->assertJsonCount(9, 'data')->json('data');
        $durumlar = [];
        foreach ($satirlar as $satir) {
            $durumlar[$satir['kod']] = $satir['durum'].':'.($satir['neden'] ?? '-');
        }

        $this->assertSame([
            'A1' => 'ayni:-',
            'B1' => 'guncellenecek:-',
            'C1' => 'eslesecek:-',
            'D2' => 'cakisma:vkn_baska_firmada',
            'E1' => 'cakisma:kod_baska_kayitta',
            'F1' => 'yeni:-',
            'G1' => 'gecersiz:vkn_tekrar',
            'G2' => 'gecersiz:vkn_tekrar',
            'H1' => 'gecersiz:ad_uzun',
        ], $durumlar);

        $eslesen = array_values(array_filter($satirlar, fn (array $satir): bool => $satir['kod'] === 'C1'))[0];
        $this->assertSame('1000000003', $eslesen['vergi_kimlik_no']);
        $this->assertSame('ELLE-C', $eslesen['mevcut']['kod'] ?? null);
    }

    public function test_aktarim_secilenleri_erpdeki_bilgilerle_kaydeder_ve_vkn_ile_eslesen_firmayi_baglar(): void
    {
        $this->karisikDurum();

        $this->postJson(self::ADRES, ['guidler' => [self::G_DEGISEN, self::G_VKN_ILE, self::G_YENI, self::G_KOD_CAKISAN]])
            ->assertOk()
            ->assertJsonPath('data', ['eklenen' => 1, 'guncellenen' => 1, 'eslesen' => 1, 'atlanan' => 1]);

        $this->assertDatabaseHas('firmalar', ['kod' => 'B1', 'eposta' => 'yeni@b.test', 'erp_guid' => self::G_DEGISEN]);
        // Elle açılan firma ERP'ye bağlanır, kodu ve bilgileri ERP'deki olur
        $this->assertDatabaseHas('firmalar', ['kod' => 'C1', 'ad' => 'C ERP', 'vergi_kimlik_no' => '1000000003', 'vergi_dairesi' => 'Kadıköy', 'erp_guid' => self::G_VKN_ILE]);
        $this->assertDatabaseMissing('firmalar', ['kod' => 'ELLE-C']);
        $this->assertDatabaseHas('firmalar', ['kod' => 'F1', 'vergi_kimlik_no' => 'GBN155HD11', 'gsm_no' => '0532', 'erp_guid' => self::G_YENI]);
        $this->assertDatabaseHas('firmalar', ['kod' => 'E1', 'ad' => 'E Kodu Dolu', 'erp_guid' => null]);
        $this->assertDatabaseCount('firmalar', 6);
    }

    public function test_yalniz_goruntuleme_izniyle_erp_aktarimi_yapilamaz(): void
    {
        $rol = Rol::query()->create(['ad' => 'Firma izleyici']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'firmalar.goruntule']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);
        $this->erpFirmalari([self::erpFirmasi('F1', 'F Yeni', self::G_YENI, null)]);

        $this->actingAs($kullanici)->getJson(self::ADRES)->assertForbidden();
        $this->actingAs($kullanici)->postJson(self::ADRES, ['guidler' => [self::G_YENI]])->assertForbidden();

        $this->assertDatabaseCount('firmalar', 0);
    }
}
