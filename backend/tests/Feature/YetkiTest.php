<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\RolServisi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/** Rol tabanlı izinler (EFAT-18) ve pasif kullanıcı oturumu. */
final class YetkiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $izinler
     */
    private function rol(string $ad, array $izinler): Rol
    {
        return app(RolServisi::class)->kaydet(null, ['ad' => $ad, 'izinler' => $izinler]);
    }

    public function test_sistem_yoneticisi_tum_izinlere_sahiptir(): void
    {
        $yonetici = User::factory()->yonetici()->create();

        $this->assertTrue(Gate::forUser($yonetici)->allows('efatura.goruntule'));
        $this->assertTrue(Gate::forUser($yonetici)->allows('efatura.senkron'));
    }

    public function test_izin_yalniz_kullanicinin_rollerinden_gelir(): void
    {
        $goruntuleyici = $this->rol('Görüntüleyici', ['efatura.goruntule', 'efatura.pdf']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($goruntuleyici);
        $rolsuz = User::factory()->create();

        $this->assertTrue(Gate::forUser($kullanici)->allows('efatura.goruntule'));
        $this->assertTrue(Gate::forUser($kullanici)->allows('efatura.pdf'));
        $this->assertFalse(Gate::forUser($kullanici)->allows('efatura.disari_aktar'));
        $this->assertFalse(Gate::forUser($rolsuz)->allows('efatura.goruntule'));
        $this->assertFalse(Gate::forUser($kullanici)->allows('sistem-yonetimi'));
    }

    public function test_katalogdan_kaldirilmis_izin_kaydi_yetki_vermez(): void
    {
        $rol = $this->rol('Eski', []);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'eski.kaldirilmis']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        $this->assertSame([], $kullanici->izinler());
    }

    public function test_me_ucu_kullanicinin_izinlerini_doner(): void
    {
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($this->rol('Görüntüleyici', ['efatura.goruntule']));

        $this->actingAs($kullanici)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.izinler', ['efatura.goruntule']);
    }

    public function test_rol_izni_degisince_acik_oturumun_sonraki_isteginde_gecerli_olur(): void
    {
        $rol = $this->rol('Görüntüleyici', ['efatura.goruntule']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);
        $this->actingAs($kullanici)->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.izinler', ['efatura.goruntule']);

        app(RolServisi::class)->kaydet($rol, ['ad' => 'Görüntüleyici', 'izinler' => ['sql_baglantilari.goruntule']]);

        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.izinler', ['sql_baglantilari.goruntule']);
        $this->assertFalse(Gate::forUser($kullanici->fresh())->allows('efatura.goruntule'));
    }

    public function test_pasife_alinan_kullanicinin_acik_oturumu_403_hesap_pasif_ile_duser(): void
    {
        $kullanici = User::factory()->create();
        $this->actingAs($kullanici)->getJson('/api/v1/auth/me')->assertOk();

        $kullanici->update(['aktif_mi' => false]);

        $this->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('kod', 'HESAP_PASIF');
        $this->assertGuest('web');
    }
}
