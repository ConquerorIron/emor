<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\KesintiTuru;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Kesinti Türleri. Ekran izinlerinin matrisi
 * EkranYetkisiTest'tedir.
 */
final class KesintiTuruTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/turler';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
    }

    public function test_liste_koda_gore_sirali_doner(): void
    {
        KesintiTuru::factory()->create(['kod' => 'YMK', 'ad' => 'Yemek']);
        KesintiTuru::factory()->create(['kod' => 'KMP', 'ad' => 'Kamp', 'kdv_orani' => '10', 'aciklama' => 'Ortak kamp']);

        $this->getJson(self::ADRES)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'id' => KesintiTuru::query()->where('kod', 'KMP')->value('id'),
                'kod' => 'KMP',
                'ad' => 'Kamp',
                'kdv_orani' => '10.00',
                'aciklama' => 'Ortak kamp',
                'icmal' => null,
            ])
            ->assertJsonPath('data.1.kod', 'YMK');
    }

    public function test_gecerli_tur_olusturulur_ve_201_doner(): void
    {
        $this->postJson(self::ADRES, [
            'kod' => ' OSGB ',
            'ad' => 'OSGB Kesintisi',
            'kdv_orani' => '20.5',
            'aciklama' => '',
        ])
            ->assertCreated()
            ->assertJsonPath('data.kod', 'OSGB')
            ->assertJsonPath('data.kdv_orani', '20.50')
            ->assertJsonPath('data.aciklama', null);

        $this->assertDatabaseHas('kesinti_turleri', ['kod' => 'OSGB', 'ad' => 'OSGB Kesintisi', 'aciklama' => null, 'icmal' => null]);
    }

    public function test_tur_bir_icmal_ekranina_baglanir_ve_secim_listesinde_ekraniyla_doner(): void
    {
        $this->postJson(self::ADRES, ['kod' => 'İŞGÜCÜ', 'ad' => 'İşgücü', 'kdv_orani' => '20', 'icmal' => 'isgucu'])
            ->assertCreated()
            ->assertJsonPath('data.icmal', 'isgucu');

        $this->getJson('/api/v1/kesinti/secenekler')
            ->assertOk()
            ->assertJsonPath('data.kesinti_turleri.0.icmal', 'isgucu');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizTurler(): array
    {
        $gecerli = ['kod' => 'KMP', 'ad' => 'Kamp', 'kdv_orani' => '20'];

        return [
            'kod boş' => [['kod' => ''] + $gecerli, 'kod'],
            'kod 20 karakteri aşıyor' => [['kod' => str_repeat('K', 21)] + $gecerli, 'kod'],
            'ad boş' => [['ad' => ''] + $gecerli, 'ad'],
            'ad 100 karakteri aşıyor' => [['ad' => str_repeat('a', 101)] + $gecerli, 'ad'],
            'kdv eksik' => [['kod' => 'KMP', 'ad' => 'Kamp'], 'kdv_orani'],
            'kdv sayı değil' => [['kdv_orani' => 'yirmi'] + $gecerli, 'kdv_orani'],
            'kdv negatif' => [['kdv_orani' => '-1'] + $gecerli, 'kdv_orani'],
            'kdv 100ü aşıyor' => [['kdv_orani' => '100.01'] + $gecerli, 'kdv_orani'],
            'kdv üç ondalıklı' => [['kdv_orani' => '20.125'] + $gecerli, 'kdv_orani'],
            'açıklama 500 karakteri aşıyor' => [['aciklama' => str_repeat('a', 501)] + $gecerli, 'aciklama'],
            'tanımsız ekran' => [['icmal' => 'olmayan_ekran'] + $gecerli, 'icmal'],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('gecersizTurler')]
    public function test_gecersiz_tur_422_ile_reddedilir(array $govde, string $alan): void
    {
        $this->postJson(self::ADRES, $govde)
            ->assertUnprocessable()
            ->assertJsonPath('kod', 'DOGRULAMA')
            ->assertJsonValidationErrorFor($alan, 'hatalar');

        $this->assertDatabaseCount('kesinti_turleri', 0);
    }

    public function test_ayni_kodla_ikinci_tur_acilamaz_ama_tur_kendi_koduyla_guncellenir(): void
    {
        $tur = KesintiTuru::factory()->create(['kod' => 'KMP']);

        $this->postJson(self::ADRES, ['kod' => 'KMP', 'ad' => 'Kamp 2', 'kdv_orani' => '20'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.kod.0', 'Bu kod başka bir kesinti türünde kullanılıyor.');

        $this->putJson(self::ADRES."/{$tur->id}", ['kod' => 'KMP', 'ad' => 'Kamp Konaklama', 'kdv_orani' => '10', 'aciklama' => 'Oda başı'])
            ->assertOk()
            ->assertJsonPath('data.ad', 'Kamp Konaklama')
            ->assertJsonPath('data.kdv_orani', '10.00');

        $this->assertDatabaseHas('kesinti_turleri', ['id' => $tur->id, 'ad' => 'Kamp Konaklama', 'aciklama' => 'Oda başı']);
    }

    public function test_kod_turkce_kurallarla_buyuk_harfe_cevrilerek_kaydedilir(): void
    {
        $this->postJson(self::ADRES, ['kod' => 'işgücü', 'ad' => 'İşgücü', 'kdv_orani' => '20'])
            ->assertCreated()
            ->assertJsonPath('data.kod', 'İŞGÜCÜ');

        $this->assertDatabaseHas('kesinti_turleri', ['kod' => 'İŞGÜCÜ']);
    }

    public function test_kod_buyuk_kucuk_harf_farkiyla_tekrar_kullanilamaz(): void
    {
        KesintiTuru::factory()->create(['kod' => 'KMP']);

        $this->postJson(self::ADRES, ['kod' => 'kmp', 'ad' => 'Kamp 2', 'kdv_orani' => '20'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.kod.0', 'Bu kod başka bir kesinti türünde kullanılıyor.');

        $this->assertDatabaseCount('kesinti_turleri', 1);
    }

    public function test_guncelleme_baska_turun_kodunu_alamaz(): void
    {
        KesintiTuru::factory()->create(['kod' => 'KMP']);
        $tur = KesintiTuru::factory()->create(['kod' => 'YMK']);

        $this->putJson(self::ADRES."/{$tur->id}", ['kod' => 'KMP', 'ad' => 'Yemek', 'kdv_orani' => '20'])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('kod', 'hatalar');

        $this->assertSame('YMK', $tur->fresh()?->kod);
    }

    public function test_tur_silinir_ve_204_doner(): void
    {
        $tur = KesintiTuru::factory()->create();

        $this->deleteJson(self::ADRES."/{$tur->id}")->assertNoContent();

        $this->assertModelMissing($tur);
    }

    public function test_olmayan_tur_404_doner(): void
    {
        $this->putJson(self::ADRES.'/999999', ['kod' => 'KMP', 'ad' => 'Kamp', 'kdv_orani' => '20'])
            ->assertNotFound()
            ->assertJsonPath('kod', 'BULUNAMADI');
    }
}
