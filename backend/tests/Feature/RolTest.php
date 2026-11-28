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

    /**
     * Roller ekranı ekranları sol menünün sırasıyla listeler (sıra sözleşmesi
     * tek yerde; yeni ekran eklenince yalnız bu liste değişir — inceleme A-10).
     */
    public function test_izin_katalogu_ekranlari_menu_sirasiyla_doner(): void
    {
        $this->yonetici();

        $ekranlar = array_column($this->getJson('/api/v1/ayarlar/izinler')->assertOk()->json('data'), 'ekran');

        $this->assertSame([
            'satinalma_talebi',
            // e-Fatura grubu: Pano, Tüm Faturalar, İşlem Geçmişi (EK-09, EK-03); Protel
            // e-Fatura'nın hemen altında (PRT-10); statik kaynaklar (EK-07, EK-08)
            'efatura_panosu', 'tum_faturalar', 'efatura_islem_gecmisi', 'efatura', 'protel_faturalari',
            'logo_faturalari', 'earsiv_faturalari',
            'kesinti_ozeti', 'pivot', 'tum_kesintiler', 'isgucu_kesintileri', 'kamp_kesintileri',
            'kamp_malzeme_kesintileri', 'makina_ekipman_kesintileri', 'tutanak_kesintileri', 'yemek_kesintileri',
            'osgb_kesintileri', 'tetkik_kesintileri', 'isg_ceza_kesintileri', 'malzeme_kesintileri',
            'kart_basim_kesintileri',
            'erp_kesinti_kontrolu', 'taseron_hakedisleri', 'gider_yansitma_faturalari', 'gelen_fatura_raporu',
            'projeler', 'firmalar', 'para_birimleri', 'kesinti_turleri',
            'sql_baglantilari', 'entegrator_baglantilari', 'mail_ayarlari', 'alarm_kurallari',
            'kullanicilar', 'roller', 'ekran_tasarimi', 'yedekler', 'yedek_planlari',
        ], $ekranlar);
    }

    /**
     * Ekranın izinleri adıyla bulunur (konum numarasıyla değil): başka ekranın
     * eklenmesi bu beklentileri kaydırmaz.
     *
     * @return array<string, array{array{ekran: string, goruntule: string, guncelle: string|null, ekler: list<string>}}>
     */
    public static function ekranIzinleri(): array
    {
        return [
            'satınalma talebi' => [['ekran' => 'satinalma_talebi', 'goruntule' => 'satinalma_talebi.goruntule', 'guncelle' => 'satinalma_talebi.guncelle', 'ekler' => []]],
            'e-Fatura panosu' => [['ekran' => 'efatura_panosu', 'goruntule' => 'efatura_panosu.goruntule', 'guncelle' => null, 'ekler' => []]],
            // Tüm Faturalar'ın senkronu yok
            'Tüm Faturalar' => [['ekran' => 'tum_faturalar', 'goruntule' => 'tum_faturalar.goruntule', 'guncelle' => null,
                'ekler' => ['tum_faturalar.pdf', 'tum_faturalar.disari_aktar', 'tum_faturalar.isle', 'tum_faturalar.red']]],
            'İzibiz e-Fatura' => [['ekran' => 'efatura', 'goruntule' => 'efatura.goruntule', 'guncelle' => 'efatura.senkron',
                'ekler' => ['efatura.pdf', 'efatura.disari_aktar', 'efatura.gizle', 'efatura.isle', 'efatura.red']]],
            'Protel' => [['ekran' => 'protel_faturalari', 'goruntule' => 'protel_faturalari.goruntule', 'guncelle' => 'protel_faturalari.senkron',
                'ekler' => ['protel_faturalari.pdf', 'protel_faturalari.disari_aktar', 'protel_faturalari.isle', 'protel_faturalari.red']]],
            // Statik kaynaklar: içe aktarım adıyla görünen ek izin; PDF yok, RED var (2026-11-26)
            'Logo' => [['ekran' => 'logo_faturalari', 'goruntule' => 'logo_faturalari.goruntule', 'guncelle' => null,
                'ekler' => ['logo_faturalari.ice_aktar', 'logo_faturalari.disari_aktar', 'logo_faturalari.isle', 'logo_faturalari.red']]],
            'e-Arşiv' => [['ekran' => 'earsiv_faturalari', 'goruntule' => 'earsiv_faturalari.goruntule', 'guncelle' => null,
                'ekler' => ['earsiv_faturalari.ice_aktar', 'earsiv_faturalari.disari_aktar', 'earsiv_faturalari.isle', 'earsiv_faturalari.red']]],
            'yedekler' => [['ekran' => 'yedekler', 'goruntule' => 'yedekler.goruntule', 'guncelle' => 'yedekler.al',
                'ekler' => ['yedekler.sil', 'yedekler.indir', 'geri_yukleme.kopya']]],
            'yedek planları' => [['ekran' => 'yedek_planlari', 'goruntule' => 'yedek_planlari.goruntule', 'guncelle' => 'yedek_planlari.guncelle', 'ekler' => []]],
        ];
    }

    /** @param  array{ekran: string, goruntule: string, guncelle: string|null, ekler: list<string>}  $beklenen */
    #[DataProvider('ekranIzinleri')]
    public function test_izin_katalogu_ekranin_izinlerini_doner(array $beklenen): void
    {
        $this->yonetici();

        $ekran = collect($this->getJson('/api/v1/ayarlar/izinler')->assertOk()->json('data'))->firstWhere('ekran', $beklenen['ekran']);

        $this->assertSame($beklenen, $ekran);
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
            ->assertJsonPath('data.kullanicilar', [])
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

    public function test_liste_rol_basina_kullanici_adlarini_sirali_ve_sayisini_doner(): void
    {
        $this->yonetici();
        $rol = Rol::query()->create(['ad' => 'Muhasebe']);
        Rol::query()->create(['ad' => 'Satinalma']);
        foreach (['Zeynep Kaya', 'Ali Veli'] as $ad) {
            User::factory()->create(['ad' => $ad])->roller()->attach($rol);
        }

        $this->getJson('/api/v1/ayarlar/roller')
            ->assertOk()
            ->assertJsonPath('data.0.ad', 'Muhasebe')
            ->assertJsonPath('data.0.kullanicilar', ['Ali Veli', 'Zeynep Kaya'])
            ->assertJsonPath('data.0.kullanici_sayisi', 2)
            ->assertJsonPath('data.1.kullanicilar', [])
            ->assertJsonPath('data.1.kullanici_sayisi', 0);
    }
}
