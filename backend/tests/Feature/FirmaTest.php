<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Firmalar (elle tanım). ERP aktarımı ErpFirmaAktarimTest'te,
 * ekran izinlerinin matrisi EkranYetkisiTest'tedir.
 */
final class FirmaTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/firmalar';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
    }

    public function test_liste_ada_gore_sirali_ve_tum_bilgilerle_doner(): void
    {
        Firma::factory()->create(['ad' => 'Zirve İnşaat']);
        $firma = Firma::factory()->erp()->create([
            'kod' => '1234567890', 'ad' => 'Aktaş Hafriyat', 'vergi_kimlik_no' => '1234567890',
            'vergi_dairesi' => 'Beyoğlu', 'eposta' => 'info@aktas.test', 'gsm_no' => '0532 000 00 00',
        ]);

        $this->getJson(self::ADRES)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'id' => $firma->id, 'kod' => '1234567890', 'ad' => 'Aktaş Hafriyat', 'vergi_kimlik_no' => '1234567890',
                'vergi_dairesi' => 'Beyoğlu', 'eposta' => 'info@aktas.test', 'gsm_no' => '0532 000 00 00', 'erp_guid' => $firma->erp_guid,
            ])
            ->assertJsonPath('data.1.ad', 'Zirve İnşaat');
    }

    public function test_kod_buyuk_harfe_vkn_bosluksuz_cevrilerek_olusturulur_ve_erp_bagi_formdan_verilemez(): void
    {
        $this->postJson(self::ADRES, [
            'kod' => 'mfk',
            'ad' => 'MFK İnşaat',
            // ERP'den kopyalanan VKN'de görünmeyen (bölünmez) boşluk olabilir
            'vergi_kimlik_no' => "6301 126332\u{00A0}",
            'vergi_dairesi' => '',
            'erp_guid' => fake()->uuid(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.kod', 'MFK')
            ->assertJsonPath('data.vergi_kimlik_no', '6301126332')
            ->assertJsonPath('data.vergi_dairesi', null)
            ->assertJsonPath('data.erp_guid', null);
    }

    public function test_yabanci_vergi_numarasi_kabul_edilir_ve_bos_vkn_null_kaydedilir(): void
    {
        $this->postJson(self::ADRES, ['kod' => 'GLB', 'ad' => 'Globax', 'vergi_kimlik_no' => 'GBN155HD11'])
            ->assertCreated()
            ->assertJsonPath('data.vergi_kimlik_no', 'GBN155HD11');

        $this->postJson(self::ADRES, ['kod' => 'BOS', 'ad' => 'VKN Yok', 'vergi_kimlik_no' => "\u{00A0}"])
            ->assertCreated()
            ->assertJsonPath('data.vergi_kimlik_no', null);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizFirmalar(): array
    {
        $gecerli = ['kod' => 'F1', 'ad' => 'Firma'];

        return [
            'kod boş' => [['kod' => ''] + $gecerli, 'kod'],
            'kod 50 karakteri aşıyor' => [['kod' => str_repeat('K', 51)] + $gecerli, 'kod'],
            'ad boş' => [['ad' => ''] + $gecerli, 'ad'],
            'ad 200 karakteri aşıyor' => [['ad' => str_repeat('a', 201)] + $gecerli, 'ad'],
            'VKN 20 karakteri aşıyor' => [['vergi_kimlik_no' => str_repeat('1', 21)] + $gecerli, 'vergi_kimlik_no'],
            'vergi dairesi 100 karakteri aşıyor' => [['vergi_dairesi' => str_repeat('v', 101)] + $gecerli, 'vergi_dairesi'],
            'GSM 30 karakteri aşıyor' => [['gsm_no' => str_repeat('5', 31)] + $gecerli, 'gsm_no'],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('gecersizFirmalar')]
    public function test_gecersiz_firma_422_ile_reddedilir(array $govde, string $alan): void
    {
        $this->postJson(self::ADRES, $govde)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor($alan, 'hatalar');

        $this->assertDatabaseCount('firmalar', 0);
    }

    public function test_ayni_kod_ve_vkn_birden_fazla_firmada_olabilir(): void
    {
        // ERP'de aynı firmanın birden fazla kartı aynı kodu ve VKN'yi taşır
        Firma::factory()->create(['kod' => 'MFK', 'vergi_kimlik_no' => '6201046150']);

        $this->postJson(self::ADRES, ['kod' => 'mfk', 'ad' => 'MFK Personel Destek', 'vergi_kimlik_no' => '6201046150'])
            ->assertCreated()
            ->assertJsonPath('data.kod', 'MFK');

        $this->assertDatabaseCount('firmalar', 2);
    }

    public function test_guncelleme_erp_bagini_korur(): void
    {
        $firma = Firma::factory()->erp()->create();

        $this->putJson(self::ADRES."/{$firma->id}", ['kod' => $firma->kod, 'ad' => 'Yeni Ad', 'erp_guid' => null])
            ->assertOk()
            ->assertJsonPath('data.erp_guid', $firma->erp_guid);
    }

    public function test_firma_silinir_ve_204_doner(): void
    {
        $firma = Firma::factory()->create();

        $this->deleteJson(self::ADRES."/{$firma->id}")->assertNoContent();

        $this->assertModelMissing($firma);
    }
}
