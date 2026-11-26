<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RolTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        $user = User::factory()->yonetici()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function yoneticiUclari(): array
    {
        return [
            'izin kataloğu' => ['GET', '/api/v1/ayarlar/izinler'],
            'liste' => ['GET', '/api/v1/ayarlar/roller'],
            'oluşturma' => ['POST', '/api/v1/ayarlar/roller'],
            'güncelleme' => ['PUT', '/api/v1/ayarlar/roller/{rol}'],
            'silme' => ['DELETE', '/api/v1/ayarlar/roller/{rol}'],
        ];
    }

    #[DataProvider('yoneticiUclari')]
    public function test_standart_kullanici_rol_uclarinda_403_alir(string $yontem, string $url): void
    {
        $rol = Rol::query()->create(['ad' => 'Mevcut']);
        $this->actingAs(User::factory()->create());

        // PostgreSQL'de dizi testler arasında sıfırlanmaz: gerçek kimlik kullanılır
        $url = str_replace('{rol}', (string) $rol->id, $url);

        $this->json($yontem, $url, ['ad' => 'Yeni', 'izinler' => []])->assertForbidden();

        $this->assertSame(['Mevcut'], Rol::query()->pluck('ad')->all());
    }

    public function test_izin_katalogu_doner(): void
    {
        $this->yonetici();

        $this->getJson('/api/v1/ayarlar/izinler')
            ->assertOk()
            ->assertJsonCount(38, 'data')
            ->assertJsonPath('data.0', [
                'ekran' => 'satinalma_talebi',
                'goruntule' => 'satinalma_talebi.goruntule',
                'guncelle' => 'satinalma_talebi.guncelle',
                'ekler' => [],
            ])
            // e-Fatura grubu: Pano, Tüm Faturalar, İşlem Geçmişi (EK-09, EK-03); Tüm Faturalar'ın senkronu yok
            ->assertJsonPath('data.1', [
                'ekran' => 'efatura_panosu',
                'goruntule' => 'efatura_panosu.goruntule',
                'guncelle' => null,
                'ekler' => [],
            ])
            ->assertJsonPath('data.2', [
                'ekran' => 'tum_faturalar',
                'goruntule' => 'tum_faturalar.goruntule',
                'guncelle' => null,
                'ekler' => ['tum_faturalar.pdf', 'tum_faturalar.disari_aktar', 'tum_faturalar.isle', 'tum_faturalar.red'],
            ])
            ->assertJsonPath('data.3.ekran', 'efatura_islem_gecmisi')
            ->assertJsonPath('data.4', [
                'ekran' => 'efatura',
                'goruntule' => 'efatura.goruntule',
                'guncelle' => 'efatura.senkron',
                'ekler' => ['efatura.pdf', 'efatura.disari_aktar', 'efatura.gizle', 'efatura.isle', 'efatura.red'],
            ])
            // Protel Gelen Faturalar menüde e-Fatura'nın hemen altında (PRT-10)
            ->assertJsonPath('data.5', [
                'ekran' => 'protel_faturalari',
                'goruntule' => 'protel_faturalari.goruntule',
                'guncelle' => 'protel_faturalari.senkron',
                'ekler' => ['protel_faturalari.pdf', 'protel_faturalari.disari_aktar', 'protel_faturalari.isle', 'protel_faturalari.red'],
            ])
            // Statik kaynaklar (EK-07, EK-08): içe aktarım adıyla görünen ek izin; PDF yok, RED var (2026-11-26)
            ->assertJsonPath('data.6', [
                'ekran' => 'logo_faturalari',
                'goruntule' => 'logo_faturalari.goruntule',
                'guncelle' => null,
                'ekler' => ['logo_faturalari.ice_aktar', 'logo_faturalari.disari_aktar', 'logo_faturalari.isle', 'logo_faturalari.red'],
            ])
            ->assertJsonPath('data.7', [
                'ekran' => 'earsiv_faturalari',
                'goruntule' => 'earsiv_faturalari.goruntule',
                'guncelle' => null,
                'ekler' => ['earsiv_faturalari.ice_aktar', 'earsiv_faturalari.disari_aktar', 'earsiv_faturalari.isle', 'earsiv_faturalari.red'],
            ])
            ->assertJsonPath('data.36', [
                'ekran' => 'yedekler',
                'goruntule' => 'yedekler.goruntule',
                'guncelle' => 'yedekler.al',
                'ekler' => ['yedekler.sil', 'yedekler.indir', 'geri_yukleme.kopya'],
            ])
            ->assertJsonPath('data.37', [
                'ekran' => 'yedek_planlari',
                'goruntule' => 'yedek_planlari.goruntule',
                'guncelle' => 'yedek_planlari.guncelle',
                'ekler' => [],
            ]);
    }

    public function test_rol_izinleriyle_olusturulur(): void
    {
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/roller', [
            'ad' => 'Muhasebe',
            'aciklama' => 'Fatura görüntüleme',
            'izinler' => ['efatura.pdf', 'efatura.goruntule'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.ad', 'Muhasebe')
            ->assertJsonPath('data.izinler', ['efatura.goruntule', 'efatura.pdf'])
            ->assertJsonPath('data.kullanici_sayisi', 0);

        $this->assertSame(2, DB::table('rol_izinleri')->count());
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizRoller(): array
    {
        return [
            'katalogda olmayan izin' => [['ad' => 'X', 'izinler' => ['herseyi.yap']], 'izinler.0'],
            'ad boş' => [['ad' => '', 'izinler' => []], 'ad'],
            'izinler eksik' => [['ad' => 'X'], 'izinler'],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('gecersizRoller')]
    public function test_gecersiz_rol_422_ile_reddedilir(array $govde, string $alan): void
    {
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/roller', $govde)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor($alan, 'hatalar');

        $this->assertDatabaseCount('roller', 0);
    }

    public function test_ayni_adla_ikinci_rol_acilamaz_ama_rol_kendi_adiyla_guncellenir(): void
    {
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);

        $this->postJson('/api/v1/ayarlar/roller', ['ad' => 'Muhasebe', 'izinler' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('ad', 'hatalar');

        $this->putJson("/api/v1/ayarlar/roller/{$rol->id}", ['ad' => 'Muhasebe', 'izinler' => ['efatura.goruntule']])
            ->assertOk()
            ->assertJsonPath('data.izinler', ['efatura.goruntule']);
    }

    public function test_guncelleme_izinleri_tamamen_degistirir(): void
    {
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);
        DB::table('rol_izinleri')->insert([['rol_id' => $rol->id, 'izin' => 'efatura.goruntule'], ['rol_id' => $rol->id, 'izin' => 'efatura.pdf']]);

        $this->putJson("/api/v1/ayarlar/roller/{$rol->id}", ['ad' => 'Muhasebe', 'izinler' => ['sql_baglantilari.goruntule']])->assertOk();

        $this->assertSame(['sql_baglantilari.goruntule'], $rol->izinler());
    }

    public function test_guncelleme_ve_ek_izinler_ekranin_goruntuleme_iznini_de_getirir(): void
    {
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/roller', [
            'ad' => 'Ayar sorumlusu',
            'izinler' => ['sql_baglantilari.guncelle', 'efatura.pdf'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.izinler', ['efatura.goruntule', 'efatura.pdf', 'sql_baglantilari.goruntule', 'sql_baglantilari.guncelle']);
    }

    public function test_rol_silinince_kullanicilardan_da_kalkar(): void
    {
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        $this->deleteJson("/api/v1/ayarlar/roller/{$rol->id}")->assertNoContent();

        $this->assertModelMissing($rol);
        $this->assertSame([], $kullanici->roller()->pluck('roller.id')->all());
    }

    public function test_liste_rol_basina_kullanici_sayisini_doner(): void
    {
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);
        User::factory()->count(2)->create()->each(fn (User $u) => $u->roller()->attach($rol));

        $this->getJson('/api/v1/ayarlar/roller')
            ->assertOk()
            ->assertJsonPath('data.0.ad', 'Muhasebe')
            ->assertJsonPath('data.0.kullanici_sayisi', 2);
    }
}
