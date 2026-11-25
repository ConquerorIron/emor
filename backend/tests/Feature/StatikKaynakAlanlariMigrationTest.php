<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EK-05 şema değişikliği canlıdaki dolu `efatura_faturalari`'na uygulanır:
 * eski şemadaki satırlar korunur (K6); statik satır entegratör kimliği ve
 * ETTN olmadan yazılır, anahtarı tanım başına tekildir; entegratör satırlarının
 * boş anahtarı birbirini engellemez (G7 — burada istenen budur).
 */
final class StatikKaynakAlanlariMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Yeni migration'lar, uygulanma sırasıyla */
    private const MIGRATIONLAR = [
        '2026_11_25_142811_create_efatura_yuklemeleri_table.php',
        '2026_11_25_142812_add_statik_kaynak_ve_red_alanlari_to_efatura_faturalari_table.php',
        '2026_11_25_142813_create_efatura_islem_gecmisi_table.php',
    ];

    private function migration(string $dosya): Migration
    {
        /** @var Migration */
        return require database_path('migrations/'.$dosya);
    }

    public function test_eski_semadaki_faturalar_yukseltmede_korunur_yeni_alanlar_bos_gelir(): void
    {
        $izibiz = EFatura::factory()->create(['belge_no' => 'INS1', 'tutar' => '1250.5000', 'gizlenme_zamani' => '2026-11-01 10:00:00']);
        $protel = EFatura::factory()->protel()->create(['belge_no' => 'OTL1', 'sap_islendi' => true]);
        $onceki = DB::table('efatura_faturalari')->orderBy('id')->get(['id', 'kaynak_id', 'ettn', 'belge_no', 'tutar', 'sap_islendi'])->map(fn (object $s): array => (array) $s)->all();
        // Eski şema: üç migration geri alınır
        foreach (array_reverse(self::MIGRATIONLAR) as $dosya) {
            $this->migration($dosya)->down();
        }
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('efatura_faturalari', 'belge_anahtari'));

        foreach (self::MIGRATIONLAR as $dosya) {
            $this->migration($dosya)->up();
        }

        $this->assertEquals(
            $onceki,
            DB::table('efatura_faturalari')->orderBy('id')->get(['id', 'kaynak_id', 'ettn', 'belge_no', 'tutar', 'sap_islendi'])->map(fn (object $s): array => (array) $s)->all(),
        );
        $this->assertNull($izibiz->fresh()->belge_anahtari);
        $this->assertNull($protel->fresh()->harici_red_zamani);
        $this->assertNotNull($izibiz->fresh()->gizlenme_zamani);
    }

    public function test_statik_satir_kimliksiz_yazilir_anahtari_tanim_basina_tekildir(): void
    {
        $logo = EntegratorBaglanti::factory()->create(['saglayici' => 'logo', 'ortam' => 'canli', 'aktif' => true]);
        $earsiv = EntegratorBaglanti::factory()->create(['saglayici' => 'earsiv', 'ortam' => 'canli', 'aktif' => true]);
        $statik = ['kaynak_id' => null, 'ettn' => null];

        EFatura::factory()->create([...$statik, 'entegrator_baglanti_id' => $earsiv->id, 'belge_anahtari' => 'fatura|10184522250|GIB2026000000001']);
        // Aynı fatura no başka gönderende ayrı belge
        EFatura::factory()->create([...$statik, 'entegrator_baglanti_id' => $earsiv->id, 'belge_anahtari' => 'fatura|2690058947|GIB2026000000001']);
        // Aynı anahtar başka tanımda serbest
        EFatura::factory()->create([...$statik, 'entegrator_baglanti_id' => $logo->id, 'belge_anahtari' => 'fatura|10184522250|GIB2026000000001']);
        // Entegratör satırlarında anahtar boş: birbirini engellemez
        EFatura::factory()->count(2)->create(['entegrator_baglanti_id' => EntegratorBaglanti::factory()->aktif()->create()->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        EFatura::factory()->create([...$statik, 'entegrator_baglanti_id' => $earsiv->id, 'belge_anahtari' => 'fatura|10184522250|GIB2026000000001']);
    }

    public function test_postgresql_kimliksiz_ve_anahtarsiz_satiri_reddeder(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK kısıtı yalnız PostgreSQL migration\'ında kurulur.');
        }

        $this->expectException(QueryException::class);
        EFatura::factory()->create(['kaynak_id' => null, 'ettn' => null, 'belge_anahtari' => null]);
    }
}
