<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Canlıdaki Protel faturalarının `erp_okundu`'da duran otel ERP bayrağı
 * `otel_erp_okundu`'ya taşınır; İzibiz faturalarının eMOR okuması değişmez.
 */
final class OtelErpBayraginiTasiMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        /** @var Migration */
        return require database_path('migrations/2026_11_20_141923_move_protel_erp_flag_to_otel_erp_okundu_on_efatura_faturalari_table.php');
    }

    public function test_protel_bayragi_otel_kolonuna_tasinir_izibiz_emor_okumasi_korunur_geri_alma_eski_hale_dondurur(): void
    {
        $protel = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $izibizTanimi = EntegratorBaglanti::factory()->aktif()->create();
        $izibiz = EFatura::factory()->create(['entegrator_baglanti_id' => $izibizTanimi->id, 'erp_okundu' => true]);
        $izibizOkunmamis = EFatura::factory()->create(['entegrator_baglanti_id' => $izibizTanimi->id, 'erp_okundu' => false]);
        $alinan = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'otel_erp_okundu' => true]);
        $alinmayan = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'otel_erp_okundu' => false]);
        $migration = $this->migration();
        // Eski şema: Protel bayrağı erp_okundu'da
        $migration->down();
        $this->assertSame(
            [$izibiz->id => 1, $izibizOkunmamis->id => 0, $alinan->id => 1, $alinmayan->id => 0],
            DB::table('efatura_faturalari')->orderBy('id')->pluck('erp_okundu', 'id')->map(fn (mixed $d): int => (int) $d)->all(),
        );

        $migration->up();

        $this->assertTrue($izibiz->fresh()->erp_okundu);
        $this->assertNull($izibiz->fresh()->otel_erp_okundu);
        $this->assertFalse($izibizOkunmamis->fresh()->erp_okundu);
        $this->assertNull($alinan->fresh()->erp_okundu);
        $this->assertTrue($alinan->fresh()->otel_erp_okundu);
        $this->assertNull($alinmayan->fresh()->erp_okundu);
        $this->assertFalse($alinmayan->fresh()->otel_erp_okundu);
    }
}
