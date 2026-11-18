<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Support\KayitSilme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Yedekleme veri modelinin sözleşmeleri (BY-004). Hedef / plan uçları henüz
 * yok (BY-016, BY-017): silme, uçların kullanacağı KayitSilme üzerinden sınanır.
 */
final class YedeklemeVeriModeliTest extends TestCase
{
    use RefreshDatabase;

    public function test_hedef_sirri_sifreli_saklanir_ve_serilestirmede_donmez(): void
    {
        $hedef = YedekHedefi::factory()->ftp()->create();

        $ham = (string) DB::table('yedek_hedefleri')->where('id', $hedef->id)->value('gizli_bilgiler');
        $this->assertStringNotContainsString('deneme-ftp-sifresi', $ham);
        $this->assertSame(['sifre' => 'deneme-ftp-sifresi'], $hedef->fresh()?->gizli_bilgiler);

        $this->assertArrayNotHasKey('gizli_bilgiler', $hedef->toArray());
        $this->assertStringNotContainsString('deneme-ftp-sifresi', $hedef->toJson());
    }

    public function test_plana_bagli_hedef_silinemez_ve_409_doner(): void
    {
        $hedef = YedekHedefi::factory()->create();
        YedekPlani::factory()->create()->hedefler()->attach($hedef, ['sakla_son_adet' => 14]);

        $this->silinemez($hedef);
    }

    public function test_kopyasi_olan_hedef_silinemez_ve_409_doner(): void
    {
        $kopya = YedekKopyasi::factory()->create(['yedek_plani_id' => null]);

        $this->silinemez($kopya->hedef);
    }

    public function test_kopyasi_olan_plan_silinemez_ve_409_doner(): void
    {
        $kopya = YedekKopyasi::factory()->create();

        $this->silinemez($kopya->plan);
    }

    public function test_kopyasi_olmayan_plan_silinince_hedef_baglari_gider_hedef_kalir(): void
    {
        $hedef = YedekHedefi::factory()->create();
        $plan = YedekPlani::factory()->create();
        $plan->hedefler()->attach($hedef, ['sakla_gun' => 30]);

        KayitSilme::sil($plan);

        $this->assertModelMissing($plan);
        $this->assertDatabaseMissing('yedek_plan_hedefleri', ['yedek_plani_id' => $plan->id]);
        $this->assertModelExists($hedef);
    }

    public function test_kullanilmayan_hedef_silinir(): void
    {
        $hedef = YedekHedefi::factory()->create();

        KayitSilme::sil($hedef);

        $this->assertModelMissing($hedef);
    }

    public function test_yedek_kaydi_silinince_kopyalari_da_gider_hedef_ve_plan_kalir(): void
    {
        $kopya = YedekKopyasi::factory()->create();

        $kopya->yedek->delete();

        $this->assertModelMissing($kopya);
        $this->assertModelExists($kopya->hedef);
        $this->assertModelExists($kopya->plan);
    }

    /** Silme 409 + KAYIT_KULLANILIYOR ile reddedilmeli ve kayıt yerinde kalmalı. */
    private function silinemez(?Model $kayit): void
    {
        $this->assertNotNull($kayit);

        try {
            KayitSilme::sil($kayit);
            $this->fail('Kullanılan kayıt silindi.');
        } catch (HttpResponseException $hata) {
            TestResponse::fromBaseResponse($hata->getResponse())
                ->assertConflict()
                ->assertJsonPath('kod', 'KAYIT_KULLANILIYOR');
        }

        $this->assertModelExists($kayit);
    }
}
