<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_lokal_kullanici_giris_yapabilir(): void
    {
        $user = User::factory()->create([
            'kullanici_adi' => 'admin',
            'password' => 'gizli-sifre',
        ]);

        $yanit = $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'admin',
            'sifre' => 'gizli-sifre',
        ]);

        $yanit->assertOk()
            ->assertJsonPath('data.kullanici_adi', 'admin')
            ->assertJsonPath('data.kaynak', 'lokal');

        $this->assertAuthenticatedAs($user);
    }

    public function test_yanlis_sifre_reddedilir(): void
    {
        User::factory()->create(['kullanici_adi' => 'admin', 'password' => 'dogru']);

        $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'admin',
            'sifre' => 'yanlis',
        ])->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');

        $this->assertGuest();
    }

    public function test_pasif_kullanici_giris_yapamaz(): void
    {
        User::factory()->pasif()->create(['kullanici_adi' => 'admin', 'password' => 'gizli']);

        $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'admin',
            'sifre' => 'gizli',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_erp_kullanicisi_dogrulama_yapilandirilmadan_giremez(): void
    {
        // ERP doğrulaması henüz yapılandırılmadı — bilinmeyen kullanıcı genel hata alır
        $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'erpkullanici',
            'sifre' => 'sifre',
        ])->assertStatus(422);
    }

    public function test_me_ve_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_oturumsuz_me_401_doner(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('kod', 'YETKISIZ');
    }
}
