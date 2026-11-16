<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Firma Özeti → Pivot: canlıdaki rollerde duran eski izin anahtarı yeni
 * anahtara taşınır; o role bağlı kullanıcı ekrana erişmeye devam eder.
 */
final class FirmaOzetiIzniniPivotaTasiMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        /** @var Migration */
        return require database_path('migrations/2026_11_16_143150_rename_firma_ozeti_izni_to_pivot.php');
    }

    /**
     * @return list<string>
     */
    private function izinler(Rol $rol): array
    {
        return DB::table('rol_izinleri')->where('rol_id', $rol->id)->orderBy('izin')->pluck('izin')->all();
    }

    public function test_eski_izin_yeni_anahtara_tasinir_diger_izinler_degismez_kullanici_pivotu_acar(): void
    {
        $rol = Rol::query()->create(['ad' => 'Kesinti Raporcu']);
        DB::table('rol_izinleri')->insert([
            ['rol_id' => $rol->id, 'izin' => 'firma_ozeti.goruntule'],
            ['rol_id' => $rol->id, 'izin' => 'kesinti_ozeti.goruntule'],
        ]);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        $this->migration()->up();

        $this->assertSame(['kesinti_ozeti.goruntule', 'pivot.goruntule'], $this->izinler($rol));
        $this->actingAs($kullanici)
            ->getJson('/api/v1/kesinti/pivot/secenekler')
            ->assertOk();
    }

    public function test_geri_alma_eski_anahtari_geri_getirir(): void
    {
        $rol = Rol::query()->create(['ad' => 'Kesinti Raporcu']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'firma_ozeti.goruntule']);
        $migration = $this->migration();
        $migration->up();

        $migration->down();

        $this->assertSame(['firma_ozeti.goruntule'], $this->izinler($rol));
    }
}
