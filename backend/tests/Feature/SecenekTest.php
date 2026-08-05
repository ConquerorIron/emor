<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecenekTest extends TestCase
{
    use RefreshDatabase;

    public function test_oturumsuz_erisim_engellenir(): void
    {
        $this->getJson('/api/v1/secenekler/personeller')->assertStatus(401);
    }

    public function test_aktif_ortam_yoksa_anlasilir_hata_doner(): void
    {
        $this->actingAs(User::factory()->create());

        // sql_baglantilari boş → MssqlBaglantiServisi::baglan 422 üretir
        $this->getJson('/api/v1/secenekler/personeller')
            ->assertStatus(422)
            ->assertJsonPath('kod', 'DOGRULAMA');
    }

    public function test_tablo_maddesi_sayisal_olmayan_tur_reddedilir(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/secenekler/tablo-maddesi/abc')->assertStatus(404);
    }

    public function test_tablo_maddesi_aktif_ortam_yoksa_422(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/v1/secenekler/tablo-maddesi/36')
            ->assertStatus(422)
            ->assertJsonPath('kod', 'DOGRULAMA');
    }
}
