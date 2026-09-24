<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\ErpKimlikDogrulayici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Sahteler\SahteErpKimlikDogrulayici;
use Tests\TestCase;

/**
 * Kullanıcılar ekranı (kullanıcı kararı 2026-09-24): ERP kullanıcıları
 * listelenir; giriş izni ve roller uygulamada tanımlanır. Lokal kullanıcı yok.
 */
final class KullaniciYonetimTest extends TestCase
{
    use RefreshDatabase;

    private const OZALP = ['erp_kullanici_id' => 501, 'kullanici_adi' => 'ozalp.doganalp', 'ad' => 'Özalp DOĞANALP', 'sistem_yoneticisi' => false];

    private const ERP_YONETICI = ['erp_kullanici_id' => 502, 'kullanici_adi' => 'erp.admin', 'ad' => 'ERP Yönetici', 'sistem_yoneticisi' => true];

    /**
     * @param  list<array{erp_kullanici_id: int, kullanici_adi: string, ad: string, sistem_yoneticisi: bool}>|null  $erpKullanicilari  null = ERP'ye ulaşılamıyor
     * @param  array{ad: string, kullanici_adi: string, erp_kullanici_id: int, sistem_yoneticisi: bool}|null  $dogrulanan
     */
    private function erp(?array $erpKullanicilari = [self::OZALP, self::ERP_YONETICI], ?array $dogrulanan = null): void
    {
        $this->app->instance(ErpKimlikDogrulayici::class, new SahteErpKimlikDogrulayici($erpKullanicilari, $dogrulanan));
    }

    private function yonetici(): User
    {
        $user = User::factory()->yonetici()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function yonetimUclari(): array
    {
        return [
            'liste' => ['GET', '/api/v1/ayarlar/kullanicilar'],
            'tanımlama' => ['POST', '/api/v1/ayarlar/kullanicilar'],
            'güncelleme' => ['PUT', '/api/v1/ayarlar/kullanicilar/1'],
        ];
    }

    #[DataProvider('yonetimUclari')]
    public function test_standart_kullanici_kullanici_yonetim_uclarinda_403_alir(string $yontem, string $url): void
    {
        $this->erp();
        $hedef = User::factory()->create(['id' => 1]);
        $this->actingAs(User::factory()->create());

        $this->json($yontem, $url, ['erp_kullanici_id' => 501, 'aktif_mi' => false])->assertForbidden();

        $this->assertTrue($hedef->refresh()->aktif_mi);
        $this->assertDatabaseMissing('users', ['erp_kullanici_id' => 501]);
    }

    public function test_liste_erp_kullanicilarini_uygulamadaki_tanimlariyla_birlestirir(): void
    {
        $this->erp();
        $yonetici = $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);
        $tanimli = User::factory()->erp()->create(['kullanici_adi' => 'erp.admin', 'erp_kullanici_id' => 502]);
        $tanimli->roller()->attach($rol);

        $yanit = $this->getJson('/api/v1/ayarlar/kullanicilar')
            ->assertOk()
            ->assertJsonPath('meta.erp_okunamadi', false);

        $satirlar = collect($yanit->json('data'))->keyBy('kullanici_adi');
        // Henüz tanımlanmamış ERP kullanıcısı: kaydı yok, giremez
        $this->assertNull($satirlar['ozalp.doganalp']['id']);
        $this->assertSame('Özalp DOĞANALP', $satirlar['ozalp.doganalp']['ad']);
        $this->assertFalse($satirlar['ozalp.doganalp']['aktif_mi']);
        // Tanımlı ERP kullanıcısı: rolleriyle
        $this->assertSame($tanimli->id, $satirlar['erp.admin']['id']);
        $this->assertSame([$rol->id], $satirlar['erp.admin']['rol_idleri']);
        $this->assertTrue($satirlar['erp.admin']['sistem_yoneticisi']);
        // ERP listesinde olmayan uygulama kullanıcısı (oturumdaki yönetici) da görünür
        $this->assertTrue($satirlar[$yonetici->kullanici_adi]['pasif_yapilamaz']);
        $this->assertArrayNotHasKey('sifre', $satirlar['ozalp.doganalp']);
    }

    public function test_erp_okunamazsa_liste_uygulamadaki_kullanicilarla_doner(): void
    {
        $this->erp(null);
        $yonetici = $this->yonetici();

        $this->getJson('/api/v1/ayarlar/kullanicilar')
            ->assertOk()
            ->assertJsonPath('meta.erp_okunamadi', true)
            ->assertJsonPath('data.0.id', $yonetici->id);
    }

    public function test_erp_kullanicisi_izin_ve_rolle_tanimlanir_ve_erp_sifresiyle_girer(): void
    {
        $this->erp(dogrulanan: [...self::OZALP]);
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);

        $this->postJson('/api/v1/ayarlar/kullanicilar', ['erp_kullanici_id' => 501, 'aktif_mi' => true, 'rol_idleri' => [$rol->id]])
            ->assertCreated()
            ->assertJsonPath('data.kullanici_adi', 'ozalp.doganalp')
            ->assertJsonPath('data.ad', 'Özalp DOĞANALP')
            ->assertJsonPath('data.kaynak', 'erp')
            ->assertJsonPath('data.aktif_mi', true)
            ->assertJsonPath('data.rol_idleri', [$rol->id]);

        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['kullanici_adi' => 'ozalp.doganalp', 'sifre' => 'erp-sifresi'])
            ->assertOk()
            ->assertJsonPath('data.kullanici_adi', 'ozalp.doganalp');
    }

    public function test_izni_kaldirilan_erp_kullanicisi_giremez(): void
    {
        $this->erp(dogrulanan: [...self::OZALP]);
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/kullanicilar', ['erp_kullanici_id' => 501, 'aktif_mi' => false])->assertCreated();

        $this->app['auth']->guard('web')->logout();
        $this->postJson('/api/v1/auth/login', ['kullanici_adi' => 'ozalp.doganalp', 'sifre' => 'erp-sifresi'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.kullanici_adi.0', 'Hesabınız pasif durumda. Yöneticinizle iletişime geçin.');
    }

    public function test_erpde_olmayan_kullanici_tanimlanamaz(): void
    {
        $this->erp();
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/kullanicilar', ['erp_kullanici_id' => 999])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.erp_kullanici_id.0', "Bu kullanıcı ERP'de bulunamadı.");
    }

    public function test_erpye_ulasilamazsa_tanimlama_yapilmaz(): void
    {
        $this->erp(null);
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/kullanicilar', ['erp_kullanici_id' => 501])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('erp_kullanici_id', 'hatalar');

        $this->assertDatabaseMissing('users', ['erp_kullanici_id' => 501]);
    }

    public function test_ayni_erp_kullanicisi_ikinci_kez_tanimlanamaz(): void
    {
        $this->erp();
        $this->yonetici();
        User::factory()->erp()->create(['kullanici_adi' => 'ozalp.doganalp', 'erp_kullanici_id' => 501]);

        $this->postJson('/api/v1/ayarlar/kullanicilar', ['erp_kullanici_id' => 501])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.erp_kullanici_id.0', 'Bu ERP kullanıcısı uygulamada zaten tanımlı.');
    }

    public function test_yonetici_olmayan_erp_sistem_yoneticisini_tanimlayamaz(): void
    {
        $this->erp();
        $kullanici = User::factory()->create();
        $rol = Rol::query()->create(['ad' => 'Kullanıcı sorumlusu']);
        DB::table('rol_izinleri')->insert([
            ['rol_id' => $rol->id, 'izin' => 'kullanicilar.goruntule'],
            ['rol_id' => $rol->id, 'izin' => 'kullanicilar.guncelle'],
        ]);
        $kullanici->roller()->attach($rol);

        $this->actingAs($kullanici)
            ->postJson('/api/v1/ayarlar/kullanicilar', ['erp_kullanici_id' => 502])
            ->assertForbidden();
        $this->actingAs($kullanici)
            ->postJson('/api/v1/ayarlar/kullanicilar', ['erp_kullanici_id' => 501])
            ->assertCreated();
    }

    public function test_tanimli_kullanicinin_rolu_degisir_ad_ve_sifre_alanlari_yok_sayilir(): void
    {
        $this->erp();
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);
        $erpKullanici = User::factory()->erp()->create(['ad' => 'ERP Adı']);

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$erpKullanici->id}", ['rol_idleri' => [$rol->id], 'ad' => 'Başka Ad', 'sifre' => 'x'])
            ->assertOk()
            ->assertJsonPath('data.rol_idleri', [$rol->id]);

        $this->assertSame('ERP Adı', $erpKullanici->refresh()->ad);
        $this->assertNull($erpKullanici->password);
    }

    /** `boolean` kuralı 0 ve "0" değerini de kabul eder; kilit hepsinde çalışmalı. */
    #[TestWith([false])]
    #[TestWith([0])]
    #[TestWith(['0'])]
    public function test_yonetici_kendi_giris_iznini_kaldiramaz(bool|int|string $pasif): void
    {
        $this->erp();
        $yonetici = $this->yonetici();

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$yonetici->id}", ['aktif_mi' => $pasif])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.aktif_mi.0', 'Kendi giriş izninizi kaldıramazsınız.');

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

    public function test_izni_kaldirilan_kullanici_bir_sonraki_isteginde_disari_atilir(): void
    {
        $this->erp();
        $yonetici = $this->yonetici();
        $hedef = User::factory()->erp()->create();

        $this->putJson("/api/v1/ayarlar/kullanicilar/{$hedef->id}", ['aktif_mi' => false])
            ->assertOk()
            ->assertJsonPath('data.aktif_mi', false);

        $this->actingAs($hedef->refresh())->getJson('/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('kod', 'HESAP_PASIF');
        $this->assertTrue($yonetici->refresh()->aktif_mi);
    }
}
