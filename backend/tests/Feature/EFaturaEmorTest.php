<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Models\User;
use App\Services\ErpFaturaKaynagi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/** eMOR kolonu: gelen fatura ERP'ye (TOHOM_FATURA.E_FATURA_ETTN) işlenmiş mi. */
final class EFaturaEmorTest extends TestCase
{
    use RefreshDatabase;

    private const ISLENMIS = 'eeb4f1a9-9bc7-4576-beb6-00000000000a';

    /**
     * @param  list<string>|RuntimeException  $sonuc
     */
    private function erp(array|RuntimeException $sonuc): void
    {
        $this->app->instance(ErpFaturaKaynagi::class, new class($sonuc) implements ErpFaturaKaynagi
        {
            /** @param list<string>|RuntimeException $sonuc */
            public function __construct(private readonly array|RuntimeException $sonuc) {}

            public function islenmisGelenEttnler(): array
            {
                if ($this->sonuc instanceof RuntimeException) {
                    throw $this->sonuc;
                }

                return $this->sonuc;
            }
        });
    }

    public function test_erpde_ettni_olan_gelen_fatura_islendi_digerleri_islenmedi_olur(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        // İzibiz ETTN'i küçük harfle saklanır; ERP büyük harf dönebilir
        $islenmis = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $islenmemis = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => 'aaaaaaaa-0000-0000-0000-000000000001']);
        $this->erp([strtoupper(self::ISLENMIS), 'aaaaaaaa-0000-0000-0000-000000000001']);

        $this->artisan('efatura:emor')
            ->expectsOutput('eMOR: işlendi 1, işlenmedi 1, değişen 2')
            ->assertSuccessful();

        $this->assertTrue($islenmis->fresh()->emor_islendi);
        $this->assertFalse($islenmemis->fresh()->emor_islendi);
        // Giden faturalar bu kontrolün kapsamı dışında
        $this->assertNull($giden->fresh()->emor_islendi);
    }

    public function test_erpden_silinen_fatura_islenmedi_olur_ve_degismeyen_satir_yazilmaz(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);

        $this->erp([self::ISLENMIS]);
        $this->artisan('efatura:emor')->expectsOutput('eMOR: işlendi 1, işlenmedi 0, değişen 1');
        $this->artisan('efatura:emor')->expectsOutput('eMOR: işlendi 1, işlenmedi 0, değişen 0');

        $this->erp([]);
        $this->artisan('efatura:emor')->expectsOutput('eMOR: işlendi 0, işlenmedi 1, değişen 1');

        $this->assertFalse($fatura->fresh()->emor_islendi);
    }

    public function test_erp_okunamazsa_bayraklar_degismez_ve_komut_basarisiz_doner(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS, 'emor_islendi' => true]);
        $bos = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);
        Log::spy();
        $this->erp(new RuntimeException('bağlantı zaman aşımı'));

        $this->artisan('efatura:emor')->assertFailed();

        // "Okunamadı" asla "işlenmedi" sayılmaz
        $this->assertTrue($fatura->fresh()->emor_islendi);
        $this->assertNull($bos->fresh()->emor_islendi);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_liste_emor_bayragini_doner(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_islendi' => true]);
        $kullanici = User::factory()->create(['sistem_yoneticisi' => true]);

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.0.emor_islendi', true);
    }
}
