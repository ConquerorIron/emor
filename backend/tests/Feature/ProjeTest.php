<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Proje;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Projeler (elle tanım). ERP aktarımı ErpProjeAktarimTest'te,
 * ekran izinlerinin matrisi EkranYetkisiTest'tedir.
 */
final class ProjeTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/projeler';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
    }

    public function test_liste_koda_gore_sirali_ve_erp_bagiyla_doner(): void
    {
        Proje::factory()->create(['kod' => 'Z10', 'ad' => 'Zone 10']);
        $erp = Proje::factory()->erp()->create(['kod' => 'A01', 'ad' => 'Ana Proje']);

        $this->getJson(self::ADRES)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', ['id' => $erp->id, 'kod' => 'A01', 'ad' => 'Ana Proje', 'erp_guid' => $erp->erp_guid])
            ->assertJsonPath('data.1.kod', 'Z10')
            ->assertJsonPath('data.1.erp_guid', null);
    }

    public function test_kod_turkce_buyuk_harfe_cevrilerek_olusturulur_ve_erp_bagi_formdan_verilemez(): void
    {
        $this->postJson(self::ADRES, ['kod' => ' zone-i ', 'ad' => 'İstanbul Zone', 'erp_guid' => fake()->uuid()])
            ->assertCreated()
            ->assertJsonPath('data.kod', 'ZONE-İ')
            ->assertJsonPath('data.erp_guid', null);

        $this->assertDatabaseHas('projeler', ['kod' => 'ZONE-İ', 'ad' => 'İstanbul Zone', 'erp_guid' => null]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizProjeler(): array
    {
        return [
            'kod boş' => [['kod' => '', 'ad' => 'Proje'], 'kod'],
            'kod 50 karakteri aşıyor' => [['kod' => str_repeat('K', 51), 'ad' => 'Proje'], 'kod'],
            'ad boş' => [['kod' => 'P1', 'ad' => ''], 'ad'],
            'ad 200 karakteri aşıyor' => [['kod' => 'P1', 'ad' => str_repeat('a', 201)], 'ad'],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('gecersizProjeler')]
    public function test_gecersiz_proje_422_ile_reddedilir(array $govde, string $alan): void
    {
        $this->postJson(self::ADRES, $govde)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor($alan, 'hatalar');

        $this->assertDatabaseCount('projeler', 0);
    }

    public function test_kod_buyuk_kucuk_harf_farkiyla_tekrar_kullanilamaz_ama_proje_kendi_koduyla_guncellenir(): void
    {
        $proje = Proje::factory()->create(['kod' => 'Z10']);

        $this->postJson(self::ADRES, ['kod' => 'z10', 'ad' => 'Başka'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.kod.0', 'Bu kod başka bir projede kullanılıyor.');

        $this->putJson(self::ADRES."/{$proje->id}", ['kod' => 'z10', 'ad' => 'Zone 10 Kaba'])
            ->assertOk()
            ->assertJsonPath('data.ad', 'Zone 10 Kaba');

        $this->assertDatabaseCount('projeler', 1);
    }

    public function test_guncelleme_erp_bagini_korur(): void
    {
        $proje = Proje::factory()->erp()->create(['kod' => 'A01']);

        $this->putJson(self::ADRES."/{$proje->id}", ['kod' => 'A01', 'ad' => 'Yeni Ad', 'erp_guid' => null])
            ->assertOk()
            ->assertJsonPath('data.erp_guid', $proje->erp_guid);
    }

    public function test_proje_silinir_ve_204_doner(): void
    {
        $proje = Proje::factory()->create();

        $this->deleteJson(self::ADRES."/{$proje->id}")->assertNoContent();

        $this->assertModelMissing($proje);
    }
}
