<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\KampMalzemeKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Kamp Malzeme Kesintileri: liste, elle giriş ve seçim
 * listeleri. İçe aktarım KampMalzemeIceAktarimTest'te, izin matrisi
 * EkranYetkisiTest'tedir.
 */
final class KampMalzemeKesintisiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/kamp-malzeme';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
    }

    /**
     * @return array<string, mixed>
     */
    private function gecerliGovde(): array
    {
        return [
            'kesinti_turu_id' => KesintiTuru::factory()->create(['kod' => 'KAMP MALZEME', 'icmal' => 'kamp_malzeme'])->id,
            'firma_id' => Firma::factory()->create(['kod' => 'METROTEKNİK'])->id,
            'yansitma_turu' => 'taseron',
            'proje_id' => Proje::factory()->create(['kod' => 'TÜM MAHALLER (ORTAK)'])->id,
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-01-05',
            'mahal' => null,
            'kaynak_firma' => null,
            'urun_tanimi' => 'BATTANİYE',
            'birim_fiyat' => '350.1234',
            'miktar' => '4.50',
            'birim' => 'ADET',
            'toplam_tutar' => '1575.56',
            'iptal_edilen_tutar' => '75.56',
            'kesilen_tutar' => '1000.00',
            // İstemci gönderse de kullanılmaz: hesaplanır
            'yansitilan_tutar' => '1.00',
            'kalan_tutar' => '1.00',
        ];
    }

    public function test_elle_girilen_satirda_yansitilan_ve_kalan_tutar_hesaplanir(): void
    {
        $this->postJson(self::ADRES, $this->gecerliGovde())
            ->assertCreated()
            ->assertJsonPath('data.yansitilan_tutar', '1500.00')
            ->assertJsonPath('data.kalan_tutar', '500.00')
            ->assertJsonPath('data.firma.kod', 'METROTEKNİK')
            ->assertJsonPath('data.yansitma_turu', 'taseron')
            ->assertJsonPath('data.miktar', '4.50')
            ->assertJsonPath('data.birim_fiyat', '350.1234')
            ->assertJsonPath('data.yukleme_id', null);

        $this->assertDatabaseHas('kamp_malzeme_kesintileri', ['urun_tanimi' => 'BATTANİYE', 'yansitilan_tutar' => '1500.00', 'kalan_tutar' => '500.00']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizAlanlar(): array
    {
        return [
            'ürün tanımı boş' => [['urun_tanimi' => ''], 'urun_tanimi'],
            'birim 20 karakteri aşıyor' => [['birim' => str_repeat('A', 21)], 'birim'],
            'miktar 2 ondalığı aşıyor' => [['miktar' => '1.125'], 'miktar'],
            'birim fiyat 4 ondalığı aşıyor' => [['birim_fiyat' => '1.12345'], 'birim_fiyat'],
            'tanımsız yansıtma türü' => [['yansitma_turu' => 'tmc'], 'yansitma_turu'],
            'tarih biçimi' => [['tarih' => '05.01.2026'], 'tarih'],
        ];
    }

    /**
     * @param  array<string, mixed>  $degisiklik
     */
    #[DataProvider('gecersizAlanlar')]
    public function test_gecersiz_satir_422_ile_reddedilir(array $degisiklik, string $alan): void
    {
        $this->postJson(self::ADRES, [...$this->gecerliGovde(), ...$degisiklik])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor($alan, 'hatalar');

        $this->assertDatabaseCount('kamp_malzeme_kesintileri', 0);
    }

    public function test_liste_urune_gore_arar_siralar_ve_para_birimine_gore_toplar(): void
    {
        KampMalzemeKesintisi::factory()->create(['urun_tanimi' => 'YASTIK', 'miktar' => '2.00', 'toplam_tutar' => '300.00']);
        KampMalzemeKesintisi::factory()->create(['urun_tanimi' => 'BATTANİYE', 'miktar' => '1.00', 'toplam_tutar' => '350.00']);
        KampMalzemeKesintisi::factory()->create(['urun_tanimi' => 'BATTANİYE', 'miktar' => '4.00', 'toplam_tutar' => '1400.00']);

        $this->getJson(self::ADRES.'?'.http_build_query(['ara' => 'battan', 'sirala' => 'miktar', 'yon' => 'desc', 'page' => 1]))
            ->assertOk()
            ->assertJsonPath('data.*.miktar', ['4.00', '1.00'])
            ->assertJsonPath('ozet.0.toplam_tutar', '1750.00')
            ->assertJsonPath('ozet.0.satir_sayisi', 2);
    }

    public function test_ozet_sekmeleri_ve_excel_sayfalari(): void
    {
        $proje = Proje::factory()->create(['kod' => 'TÜM MAHALLER (ORTAK)']);
        $hab = Firma::factory()->create(['kod' => 'HAB']);
        KampMalzemeKesintisi::factory()->create(['firma_id' => $hab->id, 'proje_id' => $proje->id, 'tarih' => '2026-01-05', 'toplam_tutar' => '350.00']);
        KampMalzemeKesintisi::factory()->create(['firma_id' => $hab->id, 'proje_id' => $proje->id, 'tarih' => '2026-02-10', 'toplam_tutar' => '1400.00']);

        $this->getJson(self::ADRES.'/pivot/tarih')
            ->assertOk()
            ->assertJsonPath('data.*.ay', [1, 2])
            ->assertJsonPath('data.1.toplam_tutar', '1400.00')
            // Yalnız 5 tutar toplanır (kullanıcının sekme tarifi)
            ->assertJsonMissingPath('data.0.miktar');
        $this->getJson(self::ADRES.'/pivot/taseron')
            ->assertOk()
            ->assertJsonPath('data.0.toplam_tutar', '1750.00');
        $this->getJson(self::ADRES.'/pivot/vasif')->assertNotFound();

        $yanit = $this->get(self::ADRES.'/excel')->assertOk();
        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'], $kitap->getSheetNames());
    }

    public function test_satir_guncellenir_ve_silinir(): void
    {
        $kesinti = KampMalzemeKesintisi::factory()->create();

        $this->putJson(self::ADRES."/{$kesinti->id}", [...$this->gecerliGovde(), 'urun_tanimi' => 'NEVRESİM'])
            ->assertOk()
            ->assertJsonPath('data.urun_tanimi', 'NEVRESİM');

        $this->deleteJson(self::ADRES."/{$kesinti->id}")->assertNoContent();
        $this->assertModelMissing($kesinti);
    }

    public function test_kesintide_kullanilan_proje_silinemez(): void
    {
        $kesinti = KampMalzemeKesintisi::factory()->create();

        $this->deleteJson("/api/v1/kesinti/projeler/{$kesinti->proje_id}")
            ->assertStatus(409)
            ->assertJsonPath('kod', 'KAYIT_KULLANILIYOR');
    }

    public function test_secim_listeleri_kamp_malzeme_kullanicisina_da_acilir(): void
    {
        $rol = Rol::query()->create(['ad' => 'Kamp izleyici']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'kamp_malzeme_kesintileri.goruntule']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        $this->actingAs($kullanici)->getJson('/api/v1/kesinti/secenekler')->assertOk();
        // Başka icmalin ekranı açılmaz
        $this->actingAs($kullanici)->getJson('/api/v1/kesinti/isgucu')->assertForbidden();
    }
}
