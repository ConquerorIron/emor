<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * BY-028-R5: kopya kaydına konum izi eklenir. İzsiz eski `tamam` kopyanın
 * hangi yerde yapıldığı doğrulanamaz: `konum_belirsiz` olur; diğer durumlar
 * değişmez (K6: yükseltme yolu).
 */
final class KopyaKonumIziMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        /** @var Migration */
        return require database_path('migrations/2026_11_21_112737_add_konum_izi_to_yedek_kopyalari_table.php');
    }

    public function test_izsiz_eski_tamam_kopya_konum_belirsiz_olur_diger_durumlar_korunur(): void
    {
        $migration = $this->migration();
        // Eski şema: konum izi yok
        $migration->down();
        $this->assertFalse(Schema::hasColumn('yedek_kopyalari', 'konum_izi'));
        $hedef = YedekHedefi::factory()->create();
        $simdi = now();
        foreach (['tamam', 'basarisiz', 'silindi'] as $durum) {
            DB::table('yedek_kopyalari')->insert(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => $durum, 'uzak_kimlik' => "k-{$durum}", 'created_at' => $simdi, 'updated_at' => $simdi]);
        }

        $migration->up();

        $satirlar = DB::table('yedek_kopyalari')->orderBy('id')->get(['uzak_kimlik', 'durum', 'hata_kodu', 'konum_izi'])->map(fn (object $s): array => (array) $s)->all();
        $this->assertSame([
            ['uzak_kimlik' => 'k-tamam', 'durum' => 'konum_belirsiz', 'hata_kodu' => 'KOPYA_KONUMU_DOGRULANAMADI', 'konum_izi' => null],
            ['uzak_kimlik' => 'k-basarisiz', 'durum' => 'basarisiz', 'hata_kodu' => null, 'konum_izi' => null],
            ['uzak_kimlik' => 'k-silindi', 'durum' => 'silindi', 'hata_kodu' => null, 'konum_izi' => null],
        ], $satirlar);
    }
}
