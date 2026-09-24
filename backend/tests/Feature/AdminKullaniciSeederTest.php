<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminKullaniciSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminKullaniciSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_lokal_admin_sistem_yoneticisi_olarak_olusturulur(): void
    {
        config(['erp.admin_kullanici' => 'yedek-admin', 'erp.admin_sifre' => 'gizli-sifre']);

        $this->seed(AdminKullaniciSeeder::class);

        $admin = User::query()
            ->where('kullanici_adi', 'yedek-admin')
            ->where('kaynak', User::KAYNAK_LOKAL)
            ->firstOrFail();

        $this->assertTrue($admin->sistem_yoneticisi);
        $this->assertTrue($admin->aktif_mi);
    }
}
