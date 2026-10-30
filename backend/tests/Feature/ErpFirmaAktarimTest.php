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
 * Firmalar → ERP'den Getir: kimlik GUID, VKN ile eşleşme yalnız tek adayla,
 * kod uygulamaya ait (ERP aktarımı ezmez). Ortak akış (seçim, ERP hataları,
 * canlı tanımı) ErpProjeAktarimTest'te sınanır.
 */
final class ErpFirmaAktarimTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/firmalar/erp';

    private const G_AYNI = '11111111-1111-4111-8111-111111111111';

    private const G_DEGISEN = '22222222-2222-4222-8222-222222222222';

    private const G_VKN_ILE = '33333333-3333-4333-8333-333333333333';

    private const G_BASKA_KART = '44444444-4444-4444-8444-444444444444';

    private const G_YENI = '55555555-5555-4555-8555-555555555555';

    private const G_IKIZ_1 = '66666666-6666-4666-8666-666666666666';

    private const G_IKIZ_2 = '77777777-7777-4777-8777-777777777777';

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
        // Kodu uygulamada Excel'e göre değiştirilmiş; başka bilgisi ERP ile aynı
        Firma::factory()->create(['kod' => 'A-KISA', 'ad' => 'A Aynı', 'vergi_kimlik_no' => '1000000001', 'erp_guid' => self::G_AYNI]);
        Firma::factory()->create(['kod' => 'B-KISA', 'ad' => 'B Değişen', 'vergi_kimlik_no' => '1000000002', 'erp_guid' => self::G_DEGISEN]);
        // Elle açılmış, tek aday: VKN ile bağlanır
        Firma::factory()->create(['kod' => 'ELLE-C', 'ad' => 'C Elle', 'vergi_kimlik_no' => '1000000003', 'erp_guid' => null]);
        // Aynı VKN'li başka bir ERP kartı zaten var: yeni kart ayrı firma olarak gelir
        Firma::factory()->create(['kod' => 'D1', 'ad' => 'D Ana Kart', 'vergi_kimlik_no' => '1000000004', 'erp_guid' => '99999999-9999-4999-8999-999999999999']);
        // ERP'de VKN iki kartta: tek aday olmadığından elle açılan bağlanmaz
        Firma::factory()->create(['kod' => 'ELLE-G', 'ad' => 'G Elle', 'vergi_kimlik_no' => '3000000000', 'erp_guid' => null]);

        $this->erpFirmalari([
            self::erpFirmasi('A1', 'A Aynı', self::G_AYNI, '1000000001'),
            self::erpFirmasi('B1', 'B Değişen', self::G_DEGISEN, '1000000002', ['eposta' => 'yeni@b.test']),
            // VKN'de bölünmez boşluk: temizlenip eşleşir
            self::erpFirmasi('C1', 'C ERP', self::G_VKN_ILE, "1000000003\u{00A0}", ['vergi_dairesi' => 'Kadıköy']),
            self::erpFirmasi('D1', 'D Makine Kartı', self::G_BASKA_KART, '1000000004'),
            self::erpFirmasi('F1', 'F Yeni', self::G_YENI, 'GBN155HD11', ['gsm_no' => '0532']),
            self::erpFirmasi('G1', 'G İkiz 1', self::G_IKIZ_1, '3000000000'),
            self::erpFirmasi('G1', 'G İkiz 2', self::G_IKIZ_2, '3000000000'),
            self::erpFirmasi('H1', str_repeat('H', 201), 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', null),
        ]);
    }

    public function test_onizleme_guid_ve_tek_adayli_vkn_ile_eslesir_tekrarlayan_kod_ve_vkn_engel_olmaz(): void
    {
        $this->karisikDurum();

        /** @var list<array{ad: string, durum: string, neden: string|null, mevcut: array<string, string|null>|null}> $satirlar */
        $satirlar = $this->getJson(self::ADRES)->assertOk()->assertJsonCount(8, 'data')->json('data');
        $durumlar = [];
        foreach ($satirlar as $satir) {
            $durumlar[mb_substr($satir['ad'], 0, 14)] = $satir['durum'].':'.($satir['neden'] ?? '-');
        }

        $this->assertSame([
            'A Aynı' => 'ayni:-',
            'B Değişen' => 'guncellenecek:-',
            'C ERP' => 'eslesecek:-',
            'D Makine Kartı' => 'yeni:-',
            'F Yeni' => 'yeni:-',
            'G İkiz 1' => 'yeni:-',
            'G İkiz 2' => 'yeni:-',
            str_repeat('H', 14) => 'gecersiz:ad_uzun',
        ], $durumlar);

        $eslesen = $satirlar[2];
        $this->assertSame('ELLE-C', $eslesen['mevcut']['kod'] ?? null);
    }

    public function test_aktarim_erp_bilgilerini_yazar_ama_uygulamadaki_kodu_ezmez(): void
    {
        $this->karisikDurum();

        $this->postJson(self::ADRES, ['guidler' => [self::G_DEGISEN, self::G_VKN_ILE, self::G_YENI, self::G_BASKA_KART, self::G_IKIZ_1, self::G_IKIZ_2]])
            ->assertOk()
            ->assertJsonPath('data', ['eklenen' => 4, 'guncellenen' => 1, 'eslesen' => 1, 'atlanan' => 0]);

        // Güncellenen ve eşleşen firmanın kodu uygulamadaki gibi kalır
        $this->assertDatabaseHas('firmalar', ['kod' => 'B-KISA', 'eposta' => 'yeni@b.test', 'erp_guid' => self::G_DEGISEN]);
        $this->assertDatabaseHas('firmalar', ['kod' => 'ELLE-C', 'ad' => 'C ERP', 'vergi_kimlik_no' => '1000000003', 'vergi_dairesi' => 'Kadıköy', 'erp_guid' => self::G_VKN_ILE]);
        // Yeni gelen firma ERP koduyla açılır; aynı kod ve VKN başka kartta da olabilir
        $this->assertDatabaseHas('firmalar', ['kod' => 'D1', 'ad' => 'D Makine Kartı', 'erp_guid' => self::G_BASKA_KART]);
        $this->assertDatabaseHas('firmalar', ['kod' => 'F1', 'vergi_kimlik_no' => 'GBN155HD11', 'erp_guid' => self::G_YENI]);
        $this->assertSame(2, Firma::query()->where('kod', 'G1')->where('vergi_kimlik_no', '3000000000')->count());
        $this->assertDatabaseHas('firmalar', ['kod' => 'ELLE-G', 'erp_guid' => null]);
        $this->assertDatabaseCount('firmalar', 9);
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
