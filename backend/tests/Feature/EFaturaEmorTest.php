<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\ErpFaturaKaynagi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * eMOR kolonu: e-faturanın ERP'de karşılığı var mı. Gelen: TOHOM_FATURA
 * E_FATURA_ETTN; giden: ERP_GONDERILEN_E_FATURA_LISTESI (ETTN, yoksa belge no + VKN).
 */
final class EFaturaEmorTest extends TestCase
{
    use RefreshDatabase;

    private const ISLENMIS = 'eeb4f1a9-9bc7-4576-beb6-00000000000a';

    /**
     * @param  list<string>|RuntimeException  $gelen
     * @param  list<array{ettn: string|null, belge_no: string, vkn: string}>|RuntimeException  $giden
     * @param  array<string, string>  $istisnaKodlari  ETTN => vergi istisna kodu
     */
    private function erp(array|RuntimeException $gelen, array|RuntimeException $giden = [], array $istisnaKodlari = []): void
    {
        $this->app->instance(ErpFaturaKaynagi::class, new class($gelen, $giden, $istisnaKodlari) implements ErpFaturaKaynagi
        {
            /**
             * @param  list<string>|RuntimeException  $gelen
             * @param  list<array{ettn: string|null, belge_no: string, vkn: string}>|RuntimeException  $giden
             * @param  array<string, string>  $istisnaKodlari
             */
            public function __construct(
                private readonly array|RuntimeException $gelen,
                private readonly array|RuntimeException $giden,
                private readonly array $istisnaKodlari,
            ) {}

            public function islenmisGelenEttnler(): array
            {
                return $this->gelen instanceof RuntimeException ? throw $this->gelen : $this->gelen;
            }

            public function gelenIstisnaKodlari(): array
            {
                return $this->istisnaKodlari;
            }

            public function gonderilenFaturalar(): array
            {
                return $this->giden instanceof RuntimeException ? throw $this->giden : $this->giden;
            }
        });
    }

    private function tanim(): EntegratorBaglanti
    {
        return EntegratorBaglanti::factory()->aktif()->create();
    }

    public function test_erpde_ettni_olan_gelen_fatura_islendi_digerleri_islenmedi_olur(): void
    {
        $tanim = $this->tanim();
        // İzibiz ETTN'i küçük harfle saklanır; ERP büyük harf dönebilir
        $islenmis = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $islenmemis = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);
        $this->erp([strtoupper(self::ISLENMIS)]);

        $this->artisan('efatura:emor')
            ->expectsOutput('eMOR gelen: işlendi 1, işlenmedi 1, değişen 2')
            ->expectsOutput('eMOR giden: işlendi 0, işlenmedi 0, değişen 0')
            ->assertSuccessful();

        $this->assertTrue($islenmis->fresh()->emor_islendi);
        $this->assertFalse($islenmemis->fresh()->emor_islendi);
    }

    public function test_giden_fatura_ettn_ile_ya_da_ettnsiz_erp_satirinda_belge_no_ve_vkn_ile_eslesir(): void
    {
        $tanim = $this->tanim();
        $giden = fn (array $alanlar): EFatura => EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, ...$alanlar]);
        $ettnIle = $giden(['ettn' => self::ISLENMIS]);
        $noVknIle = $giden(['belge_no' => 'INS2026000000250', 'alici_vkn' => '0730433545']);
        // ERP satırında ETTN VAR ve farklı: belge no aynı olsa da eşleşmez
        $celisen = $giden(['belge_no' => 'KUR2026000000001', 'alici_vkn' => '1111111111']);
        $yok = $giden([]);
        // Gelen faturalar giden listesinden etkilenmez
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);

        $this->erp([], [
            ['ettn' => strtoupper(self::ISLENMIS), 'belge_no' => 'ABC', 'vkn' => '9'],
            ['ettn' => null, 'belge_no' => 'ins2026000000250', 'vkn' => '0730433545'],
            ['ettn' => 'ffffffff-0000-0000-0000-000000000001', 'belge_no' => 'KUR2026000000001', 'vkn' => '1111111111'],
        ]);

        $this->artisan('efatura:emor')
            ->expectsOutput('eMOR giden: işlendi 2, işlenmedi 2, değişen 4')
            ->assertSuccessful();

        $this->assertTrue($ettnIle->fresh()->emor_islendi);
        $this->assertTrue($noVknIle->fresh()->emor_islendi);
        $this->assertFalse($celisen->fresh()->emor_islendi);
        $this->assertFalse($yok->fresh()->emor_islendi);
        $this->assertFalse($gelen->fresh()->emor_islendi);
    }

    public function test_gelen_faturanin_vergi_istisna_kodu_erpden_ettn_ile_yazilir_ve_kalkinca_silinir(): void
    {
        $tanim = $this->tanim();
        $istisnali = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS, 'fatura_tipi' => 'ISTISNA']);
        $digeri = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);
        // Giden faturaya gelen kodu yazılmaz
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);

        $this->erp([], istisnaKodlari: [strtoupper(self::ISLENMIS) => '318']);
        $this->artisan('efatura:emor')->assertSuccessful();

        $this->assertSame('318', $istisnali->fresh()->vergi_istisna_kodu);
        $this->assertNull($digeri->fresh()->vergi_istisna_kodu);
        $this->assertNull($giden->fresh()->vergi_istisna_kodu);

        $this->erp([]);
        $this->artisan('efatura:emor')->assertSuccessful();
        $this->assertNull($istisnali->fresh()->vergi_istisna_kodu);

        $this->actingAs(User::factory()->yonetici()->create())
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31')
            ->assertJsonPath('data.0.vergi_istisna_kodu', null);
    }

    public function test_erpden_silinen_fatura_islenmedi_olur_ve_degismeyen_satir_yazilmaz(): void
    {
        $tanim = $this->tanim();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);

        $this->erp([self::ISLENMIS]);
        $this->artisan('efatura:emor')->expectsOutput('eMOR gelen: işlendi 1, işlenmedi 0, değişen 1');
        $this->artisan('efatura:emor')->expectsOutput('eMOR gelen: işlendi 1, işlenmedi 0, değişen 0');

        $this->erp([]);
        $this->artisan('efatura:emor')->expectsOutput('eMOR gelen: işlendi 0, işlenmedi 1, değişen 1');

        $this->assertFalse($fatura->fresh()->emor_islendi);
    }

    public function test_bir_yon_okunamazsa_bayraklari_degismez_digeri_tazelenir_komut_basarisiz_doner(): void
    {
        $tanim = $this->tanim();
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS, 'emor_islendi' => true]);
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        Log::spy();
        $this->erp(new RuntimeException('bağlantı zaman aşımı'), [['ettn' => self::ISLENMIS, 'belge_no' => 'X', 'vkn' => '1']]);

        $this->artisan('efatura:emor')->assertFailed();

        // "Okunamadı" asla "işlenmedi" sayılmaz
        $this->assertTrue($gelen->fresh()->emor_islendi);
        $this->assertTrue($giden->fresh()->emor_islendi);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_erp_senkronla_dugmesi_secilen_yonun_emorunu_hemen_tazeler(): void
    {
        $tanim = $this->tanim();
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $this->erp([self::ISLENMIS], [['ettn' => self::ISLENMIS, 'belge_no' => 'X', 'vkn' => '1']]);

        $this->actingAs(User::factory()->yonetici()->create())
            ->postJson('/api/v1/efatura/erp-senkron', ['yon' => 'giden'])
            ->assertOk()
            ->assertExactJson(['data' => ['islendi' => 1, 'islenmedi' => 0, 'degisen' => 1]]);

        $this->assertTrue($giden->fresh()->emor_islendi);
        $this->assertNull($gelen->fresh()->emor_islendi);
    }

    public function test_erp_senkronla_gecersiz_yonu_reddeder_yon_yoksa_gelen_sayar(): void
    {
        $tanim = $this->tanim();
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $this->erp([self::ISLENMIS]);
        $yonetici = User::factory()->yonetici()->create();

        $this->actingAs($yonetici)
            ->postJson('/api/v1/efatura/erp-senkron', ['yon' => 'yanlis'])
            ->assertUnprocessable();

        // Deploy öncesi açık kalmış sekmenin düğmesi yön göndermiyordu
        $this->actingAs($yonetici)->postJson('/api/v1/efatura/erp-senkron')->assertOk();
        $this->assertTrue($gelen->fresh()->emor_islendi);
    }

    public function test_erp_senkronla_erp_okunamazsa_502_doner_ve_bayrak_degismez(): void
    {
        $tanim = $this->tanim();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_islendi' => true]);
        $this->erp(new RuntimeException('bağlantı zaman aşımı'));

        $this->actingAs(User::factory()->yonetici()->create())
            ->postJson('/api/v1/efatura/erp-senkron', ['yon' => 'gelen'])
            ->assertStatus(502)
            ->assertJsonPath('kod', 'ERP_OKUNAMADI');

        $this->assertTrue($fatura->fresh()->emor_islendi);
    }

    public function test_erp_senkronla_senkron_izni_ister(): void
    {
        $kullanici = User::factory()->create();
        $rol = Rol::query()->create(['ad' => 'Görüntüleyici']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'efatura.goruntule']);
        $kullanici->roller()->attach($rol);
        $this->erp([]);

        $this->actingAs($kullanici)->postJson('/api/v1/efatura/erp-senkron', ['yon' => 'gelen'])->assertForbidden();
    }

    public function test_liste_emor_bayragini_doner(): void
    {
        $tanim = $this->tanim();
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_islendi' => true]);

        $this->actingAs(User::factory()->yonetici()->create())
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.0.emor_islendi', true);
    }
}
