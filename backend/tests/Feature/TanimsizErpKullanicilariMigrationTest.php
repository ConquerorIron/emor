<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Giriş izne bağlandığında (2026-09-24) kendiliğinden tanımlanmış ama rol
 * verilmemiş ERP kullanıcılarının izni kalkar; rolü olan, sistem yöneticisi
 * olan ve lokal kullanıcılar etkilenmez.
 */
final class TanimsizErpKullanicilariMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_yalniz_rolsuz_ve_yonetici_olmayan_erp_kullanicilarinin_izni_kalkar(): void
    {
        $rolsuz = User::factory()->erp()->create();
        $rollu = User::factory()->erp()->create();
        $rollu->roller()->attach(Rol::query()->create(['ad' => 'Muhasebe']));
        $erpYonetici = User::factory()->erp()->yonetici()->create();
        $lokal = User::factory()->create();

        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_24_082542_tanimsiz_erp_kullanicilarinin_giris_iznini_kaldir.php');
        $migration->up();

        $this->assertFalse($rolsuz->refresh()->aktif_mi);
        $this->assertTrue($rollu->refresh()->aktif_mi);
        $this->assertTrue($erpYonetici->refresh()->aktif_mi);
        $this->assertTrue($lokal->refresh()->aktif_mi);
    }
}
