<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Canlıdaki mevcut lokal admin kaydı seeder yeniden çalışmadan düzeltilir
 * (deploy.sh migrate'i her seferinde, seed'i yalnız --with-seed ile çalıştırır).
 */
final class LokalAdminiSistemYoneticisiYapMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_yalniz_yapilandirilan_lokal_admin_yonetici_yapilir(): void
    {
        config(['erp.admin_kullanici' => 'yedek-admin']);

        $admin = User::factory()->create(['kullanici_adi' => 'yedek-admin']);
        $baskaLokal = User::factory()->create(['kullanici_adi' => 'baska-lokal']);

        $this->migration()->up();

        $this->assertTrue($admin->refresh()->sistem_yoneticisi);
        $this->assertFalse($baskaLokal->refresh()->sistem_yoneticisi);
    }

    private function migration(): Migration
    {
        /** @var Migration */
        return require database_path('migrations/2026_09_23_103738_lokal_admini_sistem_yoneticisi_yap.php');
    }

    public function test_geri_alma_onceden_verilmis_yonetici_yetkisini_silmez(): void
    {
        config(['erp.admin_kullanici' => 'yedek-admin']);
        $admin = User::factory()->yonetici()->create(['kullanici_adi' => 'yedek-admin']);
        $migration = $this->migration();
        $migration->up();

        $migration->down();

        $this->assertTrue($admin->refresh()->sistem_yoneticisi);
    }
}
