<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\ErpKimlikDogrulayici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ERP doğrulama akışı — MSSQL yerine sahte doğrulayıcı bağlanır. */
final class ErpLoginTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array{ad: string, kullanici_adi: string, erp_kullanici_id: int, sistem_yoneticisi: bool}|null  $sonuc
     */
    private function sahteDogrulayici(?array $sonuc, bool $yapilandirildi = true): void
    {
        $this->app->instance(ErpKimlikDogrulayici::class, new class($sonuc, $yapilandirildi) implements ErpKimlikDogrulayici
        {
            /**
             * @param  array{ad: string, kullanici_adi: string, erp_kullanici_id: int, sistem_yoneticisi: bool}|null  $sonuc
             */
            public function __construct(
                private readonly ?array $sonuc,
                private readonly bool $yapilandirildiMi,
            ) {}

            public function yapilandirildi(): bool
            {
                return $this->yapilandirildiMi;
            }

            public function dogrula(string $kullaniciAdi, string $sifre): ?array
            {
                return $this->sonuc;
            }
        });
    }

    public function test_erp_kullanicisi_giris_yapar_ve_yerel_tabloya_yansitilir(): void
    {
        $this->sahteDogrulayici([
            'ad' => 'Fatih DEMİR',
            'kullanici_adi' => 'fatih.demir',
            'erp_kullanici_id' => 33819,
            'sistem_yoneticisi' => false,
        ]);

        $yanit = $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'fatih.demir',
            'sifre' => 'dogru-sifre',
        ]);

        $yanit->assertOk()
            ->assertJsonPath('data.ad', 'Fatih DEMİR')
            ->assertJsonPath('data.kaynak', 'erp');

        $this->assertDatabaseHas('users', [
            'kullanici_adi' => 'fatih.demir',
            'kaynak' => 'erp',
            'erp_kullanici_id' => 33819,
            'sistem_yoneticisi' => false,
        ]);
    }

    public function test_tekrar_giriste_ayni_kullanici_guncellenir_cogaltilmaz(): void
    {
        $this->sahteDogrulayici([
            'ad' => 'Fatih DEMİR (Yeni Unvan)',
            'kullanici_adi' => 'fatih.demir',
            'erp_kullanici_id' => 33819,
            'sistem_yoneticisi' => false,
        ]);

        User::factory()->erp()->create([
            'kullanici_adi' => 'fatih.demir',
            'ad' => 'Fatih DEMİR',
            'erp_kullanici_id' => 33819,
            'sistem_yoneticisi' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'fatih.demir',
            'sifre' => 'dogru-sifre',
        ])->assertOk();

        $this->assertSame(1, User::query()->where('kullanici_adi', 'fatih.demir')->count());
        $this->assertSame(
            'Fatih DEMİR (Yeni Unvan)',
            User::query()->where('kullanici_adi', 'fatih.demir')->first()?->ad,
        );
    }

    public function test_erp_sistem_yoneticisi_bayragi_her_giriste_tazelenir(): void
    {
        // ERP'de yönetici yapılmış kullanıcı; yerel kayıt eski (yönetici değil)
        $this->sahteDogrulayici([
            'ad' => 'Fatih DEMİR',
            'kullanici_adi' => 'fatih.demir',
            'erp_kullanici_id' => 33819,
            'sistem_yoneticisi' => true,
        ]);

        User::factory()->erp()->create([
            'kullanici_adi' => 'fatih.demir',
            'erp_kullanici_id' => 33819,
            'sistem_yoneticisi' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'fatih.demir',
            'sifre' => 'dogru-sifre',
        ])->assertOk()->assertJsonPath('data.sistem_yoneticisi', true);

        $this->assertTrue(
            User::query()->where('kullanici_adi', 'fatih.demir')->first()?->sistem_yoneticisi,
        );
    }

    public function test_erp_eslesmeyen_sifre_reddedilir(): void
    {
        $this->sahteDogrulayici(null);

        $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'fatih.demir',
            'sifre' => 'yanlis',
        ])->assertStatus(422);

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['kullanici_adi' => 'fatih.demir']);
    }

    public function test_pasife_alinmis_erp_kullanicisi_dogru_sifreyle_de_giremez(): void
    {
        $this->sahteDogrulayici([
            'ad' => 'Fatih DEMİR',
            'kullanici_adi' => 'fatih.demir',
            'erp_kullanici_id' => 33819,
            'sistem_yoneticisi' => false,
        ]);

        User::factory()->erp()->pasif()->create([
            'kullanici_adi' => 'fatih.demir',
            'erp_kullanici_id' => 33819,
            'sistem_yoneticisi' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'fatih.demir',
            'sifre' => 'dogru-sifre',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_lokal_admin_erp_yapilandirilmis_olsa_da_lokal_dogrulanir(): void
    {
        // ERP doğrulayıcı null dönse bile lokal admin kendi şifresiyle girer
        $this->sahteDogrulayici(null);

        User::factory()->create(['kullanici_adi' => 'admin', 'password' => 'lokal-sifre']);

        $this->postJson('/api/v1/auth/login', [
            'kullanici_adi' => 'admin',
            'sifre' => 'lokal-sifre',
        ])->assertOk()->assertJsonPath('data.kaynak', 'lokal');
    }
}
