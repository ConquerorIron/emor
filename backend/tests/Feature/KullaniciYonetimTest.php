<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\ErpKimlikDogrulayici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Sahteler\SahteErpKimlikDogrulayici;
use Tests\TestCase;

final class KullaniciYonetimTest extends TestCase
{
    use RefreshDatabase;

    private const GUCLU_SIFRE = 'Guclu-Sifre-2026';

    /**
     * @param  list<string>|null  $erpKullanicilari  null = ERP'ye ulaşılamıyor
     */
    private function erp(?array $erpKullanicilari = []): void
    {
        $this->app->instance(ErpKimlikDogrulayici::class, new SahteErpKimlikDogrulayici($erpKullanicilari));
    }

    private function yonetici(): User
    {
        $user = User::factory()->yonetici()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $degisen
     * @return array<string, mixed>
     */
    private function yeniKullanici(array $degisen = []): array
    {
        return [
            'kullanici_adi' => 'dis.denetci',
            'ad' => 'Dış Denetçi',
            'email' => 'denetci@ornek.test',
            'sifre' => self::GUCLU_SIFRE,
            ...$degisen,
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function yoneticiUclari(): array
    {
        return [
            'liste' => ['GET', '/api/v1/ayarlar/kullanicilar'],
            'oluşturma' => ['POST', '/api/v1/ayarlar/kullanicilar'],
            'güncelleme' => ['PUT', '/api/v1/ayarlar/kullanicilar/1'],
        ];
    }

    #[DataProvider('yoneticiUclari')]
    public function test_standart_kullanici_kullanici_yonetim_uclarinda_403_alir(string $yontem, string $url): void
    {
        $this->erp();
        $hedef = User::factory()->create(['id' => 1]);
        $this->actingAs(User::factory()->create());

        $this->json($yontem, $url, [...$this->yeniKullanici(), 'aktif_mi' => false])->assertForbidden();

        $this->assertTrue($hedef->refresh()->aktif_mi);
        $this->assertDatabaseMissing('users', ['kullanici_adi' => 'dis.denetci']);
    }

    public function test_lokal_kullanici_rolleriyle_acilir_ve_sifresiyle_giris_yapar(): void
    {
        $this->erp();
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Denetim']);

        $this->postJson('/api/v1/ayarlar/kullanicilar', $this->yeniKullanici(['rol_idleri' => [$rol->id]]))
            ->assertCreated()
            ->assertJsonPath('data.kaynak', 'lokal')
            ->assertJsonPath('data.sistem_yoneticisi', false)
            ->assertJsonPath('data.rol_idleri', [$rol->id])
            ->assertJsonMissingPath('data.password');

        $kullanici = User::query()->where('kullanici_adi', 'dis.denetci')->firstOrFail();
        $this->assertTrue(Hash::check(self::GUCLU_SIFRE, (string) $kullanici->password));

        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['kullanici_adi' => 'dis.denetci', 'sifre' => self::GUCLU_SIFRE])
            ->assertOk()
            ->assertJsonPath('data.kullanici_adi', 'dis.denetci');
    }

    public function test_erpde_var_olan_ad_ile_lokal_kullanici_acilamaz_buyuk_kucuk_harf_farki_onemsiz(): void
    {
        $this->erp(['DIS.DENETCI']);
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/kullanicilar', $this->yeniKullanici())
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.kullanici_adi.0', "Bu kullanıcı adı ERP'de kayıtlı. ERP kullanıcıları kendi şifreleriyle giriş yapar; lokal kullanıcı açılamaz.");

        $this->assertDatabaseMissing('users', ['kullanici_adi' => 'dis.denetci']);
    }

    public function test_erpye_ulasilamazsa_lokal_kullanici_acilmaz(): void
    {
        $this->erp(null);
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/kullanicilar', $this->yeniKullanici())
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('kullanici_adi', 'hatalar');

        $this->assertDatabaseMissing('users', ['kullanici_adi' => 'dis.denetci']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizKullanicilar(): array
    {
        return [
            'zayıf şifre (rakamsız)' => [['sifre' => 'yalnizharflerden'], 'sifre'],
            'kısa şifre' => [['sifre' => 'Ab1'], 'sifre'],
            'kullanıcı adında boşluk' => [['kullanici_adi' => 'dis denetci'], 'kullanici_adi'],
            'geçersiz e-posta' => [['email' => 'eposta-degil'], 'email'],
        ];
    }

    /**
     * @param  array<string, mixed>  $degisen
     */
    #[DataProvider('gecersizKullanicilar')]
    public function test_gecersiz_lokal_kullanici_422_ile_reddedilir(array $degisen, string $alan): void
    {
        $this->erp();
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/kullanicilar', $this->yeniKullanici($degisen))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor($alan, 'hatalar');
    }

    public function test_var_olan_kullanici_adi_ikinci_kez_acilamaz(): void
    {
        $this->erp();
        $this->yonetici();
        User::factory()->erp()->create(['kullanici_adi' => 'dis.denetci']);

        $this->postJson('/api/v1/ayarlar/kullanicilar', $this->yeniKullanici())
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('kullanici_adi', 'hatalar');
    }

    public function test_erp_kullanicisina_rol_atanir(): void
    {
        $this->erp();
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);
        $erpKullanici = User::factory()->erp()->create();

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$erpKullanici->id}", ['rol_idleri' => [$rol->id]])
            ->assertOk()
            ->assertJsonPath('data.rol_idleri', [$rol->id]);
    }

    public function test_erp_kullanicisinin_adi_ve_sifresi_bu_ekrandan_degismez(): void
    {
        $this->erp();
        $this->yonetici();
        $erpKullanici = User::factory()->erp()->create(['ad' => 'ERP Adı']);

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$erpKullanici->id}", ['ad' => 'Başka Ad', 'sifre' => self::GUCLU_SIFRE])
            ->assertUnprocessable();

        $this->assertSame('ERP Adı', $erpKullanici->refresh()->ad);
        $this->assertNull($erpKullanici->password);
    }

    public function test_lokal_kullanicinin_sifresi_sifirlanir(): void
    {
        $this->erp();
        $this->yonetici();
        $lokal = User::factory()->create();

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$lokal->id}", ['sifre' => 'Yeni-Sifre-2026'])->assertOk();

        $this->assertTrue(Hash::check('Yeni-Sifre-2026', (string) $lokal->refresh()->password));
    }

    /** `boolean` kuralı 0 ve "0" değerini de kabul eder; kilit hepsinde çalışmalı. */
    #[TestWith([false])]
    #[TestWith([0])]
    #[TestWith(['0'])]
    public function test_yonetici_kendini_pasife_alamaz(bool|int|string $pasif): void
    {
        $this->erp();
        $yonetici = $this->yonetici();

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$yonetici->id}", ['aktif_mi' => $pasif])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.aktif_mi.0', 'Kendi hesabınızı pasife alamazsınız.');

        $this->assertTrue($yonetici->refresh()->aktif_mi);
    }

    #[TestWith([false])]
    #[TestWith([0])]
    #[TestWith(['0'])]
    public function test_yedek_lokal_admin_pasife_alinamaz(bool|int|string $pasif): void
    {
        config(['erp.admin_kullanici' => 'yedek-admin']);
        $this->erp();
        $this->yonetici();
        $yedek = User::factory()->yonetici()->create(['kullanici_adi' => 'yedek-admin']);

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$yedek->id}", ['aktif_mi' => $pasif])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('aktif_mi', 'hatalar');

        $this->assertTrue($yedek->refresh()->aktif_mi);
    }

    public function test_pasife_alinan_kullanici_bir_sonraki_isteginde_disari_atilir(): void
    {
        $this->erp();
        $yonetici = $this->yonetici();
        $hedef = User::factory()->create();

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$hedef->id}", ['aktif_mi' => false])
            ->assertOk()
            ->assertJsonPath('data.aktif_mi', false);

        $this->actingAs($hedef->refresh())->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('kod', 'HESAP_PASIF');
        $this->assertTrue($yonetici->refresh()->aktif_mi);
    }

    public function test_liste_rolleri_ve_pasife_alinamaz_bayragini_doner(): void
    {
        $this->erp();
        $yonetici = $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);
        $diger = User::factory()->erp()->create(['ad' => 'Zeynep']);
        $diger->roller()->attach($rol);

        $yanit = $this->getJson('/api/v1/ayarlar/kullanicilar')->assertOk();

        $satirlar = collect($yanit->json('data'))->keyBy('id');
        $this->assertSame([$rol->id], $satirlar[$diger->id]['rol_idleri']);
        $this->assertFalse($satirlar[$diger->id]['pasif_yapilamaz']);
        $this->assertTrue($satirlar[$yonetici->id]['pasif_yapilamaz']);
    }
}
