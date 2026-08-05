<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SqlBaglanti;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SqlBaglantiTest extends TestCase
{
    use RefreshDatabase;

    private function girisli(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_oturumsuz_erisim_engellenir(): void
    {
        $this->getJson('/api/v1/ayarlar/sql-baglantilari')->assertStatus(401);
    }

    public function test_bos_durumda_index_null_tanimlar_doner(): void
    {
        $this->girisli();

        $this->getJson('/api/v1/ayarlar/sql-baglantilari')
            ->assertOk()
            ->assertJson([
                'data' => ['test' => null, 'canli' => null, 'aktif_ortam' => null],
            ]);
    }

    public function test_ilk_kayitta_sifre_zorunludur(): void
    {
        $this->girisli();

        $this->putJson('/api/v1/ayarlar/sql-baglantilari/test', [
            'sunucu' => 'sql.local',
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
        ])->assertStatus(422)->assertJsonStructure(['hatalar' => ['sifre']]);
    }

    public function test_tanim_olusturulur_ve_sifre_gizli_kalir(): void
    {
        $this->girisli();

        $yanit = $this->putJson('/api/v1/ayarlar/sql-baglantilari/test', [
            'sunucu' => 'sql.local',
            'port' => 1433,
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
            'sifre' => 'cok-gizli',
        ]);

        $yanit->assertOk()
            ->assertJsonPath('data.ortam', 'test')
            ->assertJsonPath('data.sifre_dolu', true)
            ->assertJsonMissingPath('data.sifre');

        // Şifre veritabanında düz metin durmaz (encrypted cast)
        $ham = SqlBaglanti::query()->where('ortam', 'test')->first();
        $this->assertNotNull($ham);
        $this->assertSame('cok-gizli', $ham->sifre);
        $this->assertNotSame('cok-gizli', $ham->getRawOriginal('sifre'));
    }

    public function test_guncellemede_bos_sifre_kayitli_sifreyi_korur(): void
    {
        $this->girisli();

        SqlBaglanti::query()->create([
            'ortam' => 'test',
            'sunucu' => 'eski.local',
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
            'sifre' => 'ilk-sifre',
        ]);

        $this->putJson('/api/v1/ayarlar/sql-baglantilari/test', [
            'sunucu' => 'yeni.local',
            'veritabani' => 'ERPTEST',
            'kullanici_adi' => 'sa',
            'sifre' => '',
        ])->assertOk()->assertJsonPath('data.sunucu', 'yeni.local');

        $this->assertSame('ilk-sifre', SqlBaglanti::query()->where('ortam', 'test')->first()?->sifre);
    }

    public function test_gecersiz_ortam_404_doner(): void
    {
        $this->girisli();

        $this->putJson('/api/v1/ayarlar/sql-baglantilari/staging', [
            'sunucu' => 'x',
            'veritabani' => 'y',
            'kullanici_adi' => 'z',
            'sifre' => 's',
        ])->assertStatus(404);
    }

    public function test_aktif_ortam_degistirilir_ve_tek_aktif_kalir(): void
    {
        $this->girisli();

        foreach (['test', 'canli'] as $ortam) {
            SqlBaglanti::query()->create([
                'ortam' => $ortam,
                'sunucu' => 'sql.local',
                'veritabani' => 'ERP',
                'kullanici_adi' => 'sa',
                'sifre' => 's',
            ]);
        }

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/aktif', ['ortam' => 'test'])
            ->assertOk()
            ->assertJsonPath('data.ortam', 'test')
            ->assertJsonPath('data.aktif', true);

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/aktif', ['ortam' => 'canli'])
            ->assertOk()
            ->assertJsonPath('data.aktif', true);

        $this->assertSame(1, SqlBaglanti::query()->where('aktif', true)->count());
        $this->assertSame('canli', SqlBaglanti::query()->where('aktif', true)->first()?->ortam);

        $this->getJson('/api/v1/ayarlar/sql-baglantilari')
            ->assertOk()
            ->assertJsonPath('data.aktif_ortam', 'canli');
    }

    public function test_tanimsiz_ortam_aktif_yapilamaz(): void
    {
        $this->girisli();

        $this->postJson('/api/v1/ayarlar/sql-baglantilari/aktif', ['ortam' => 'test'])
            ->assertStatus(422);
    }
}
