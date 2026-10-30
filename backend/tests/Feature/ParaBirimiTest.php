<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IsgucuKesintisi;
use App\Models\ParaBirimi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Para Birimleri. İzin matrisi EkranYetkisiTest'tedir.
 */
final class ParaBirimiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/para-birimleri';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
    }

    public function test_excelde_gecen_para_birimleri_hazir_gelir(): void
    {
        $this->getJson(self::ADRES)
            ->assertOk()
            ->assertJsonPath('data.*.kod', ['EUR', 'TL', 'USD']);
    }

    public function test_kod_buyuk_harfe_cevrilir_ve_harf_farkiyla_tekrar_kullanilamaz(): void
    {
        $this->postJson(self::ADRES, ['kod' => 'gbp', 'ad' => 'İngiliz Sterlini'])
            ->assertCreated()
            ->assertJsonPath('data.kod', 'GBP');

        $this->postJson(self::ADRES, ['kod' => 'tl', 'ad' => 'Lira'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.kod.0', 'Bu kod başka bir para biriminde kullanılıyor.');
    }

    public function test_kesinti_satirinda_kullanilan_para_birimi_silinemez_ve_409_doner(): void
    {
        $kesinti = IsgucuKesintisi::factory()->create();
        $tl = ParaBirimi::query()->findOrFail($kesinti->para_birimi_id);

        $this->deleteJson(self::ADRES."/{$tl->id}")
            ->assertStatus(409)
            ->assertJsonPath('kod', 'KAYIT_KULLANILIYOR');

        $this->assertModelExists($tl);
    }

    public function test_kullanilmayan_para_birimi_silinir(): void
    {
        $para = ParaBirimi::factory()->create();

        $this->deleteJson(self::ADRES."/{$para->id}")->assertNoContent();

        $this->assertModelMissing($para);
    }
}
