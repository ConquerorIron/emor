<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → İşgücü Kesintileri: liste (filtre, sıralama, toplamlar),
 * elle giriş ve seçim listeleri. İçe aktarım IsgucuIceAktarimTest'te, izin
 * matrisi EkranYetkisiTest'tedir.
 */
final class IsgucuKesintisiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/isgucu';

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
            'kesinti_turu_id' => KesintiTuru::factory()->create(['kod' => 'İŞGÜCÜ'])->id,
            'firma_id' => Firma::factory()->create(['kod' => 'TMC KABA'])->id,
            'proje_id' => Proje::factory()->create(['kod' => 'ZONE 10'])->id,
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-03-27',
            'mahal' => 'ZONE 10',
            'kaynak_firma' => 'MFK',
            'kaynak_fis_no' => '10397',
            'adi_soyadi' => 'Fırat Akyıldız',
            'vasif' => 'niteliksiz',
            'mesai_turu' => 'fazla',
            'yansitma_turu' => 'taseron',
            'miktar_saat' => '3',
            'miktar_yevmiye' => '0',
            'birim_fiyat' => '320.2690',
            'toplam_tutar' => '960.81',
            'iptal_edilen_tutar' => '100.00',
            'kesilen_tutar' => '500.00',
            // İstemci gönderse de kullanılmaz: hesaplanır
            'yansitilan_tutar' => '1.00',
            'kalan_tutar' => '1.00',
        ];
    }

    public function test_elle_girilen_satirda_yansitilan_ve_kalan_tutar_hesaplanir(): void
    {
        $this->postJson(self::ADRES, $this->gecerliGovde())
            ->assertCreated()
            ->assertJsonPath('data.yansitilan_tutar', '860.81')
            ->assertJsonPath('data.kalan_tutar', '360.81')
            ->assertJsonPath('data.firma.kod', 'TMC KABA')
            ->assertJsonPath('data.mesai_turu', 'fazla')
            ->assertJsonPath('data.yukleme_id', null);

        $this->assertDatabaseHas('isgucu_kesintileri', ['adi_soyadi' => 'Fırat Akyıldız', 'yansitilan_tutar' => '860.81', 'kalan_tutar' => '360.81']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizAlanlar(): array
    {
        return [
            'tanımsız vasıf' => [['vasif' => 'usta'], 'vasif'],
            'tanımsız yansıtma türü' => [['yansitma_turu' => '%50 TMC-%50 HAB'], 'yansitma_turu'],
            'olmayan taşeron' => [['firma_id' => 999999], 'firma_id'],
            'tarih biçimi' => [['tarih' => '27.03.2026'], 'tarih'],
            'birim fiyat 4 ondalığı aşıyor' => [['birim_fiyat' => '1.12345'], 'birim_fiyat'],
            'eksi tutar' => [['toplam_tutar' => '-5'], 'toplam_tutar'],
            'adı soyadı boş' => [['adi_soyadi' => ''], 'adi_soyadi'],
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

        $this->assertDatabaseCount('isgucu_kesintileri', 0);
    }

    public function test_liste_filtreler_kolona_gore_siralar_ve_para_birimine_gore_toplar(): void
    {
        $tmc = Firma::factory()->create(['kod' => 'TMC KABA']);
        $hab = Firma::factory()->create(['kod' => 'HAB']);
        $usd = ParaBirimi::query()->where('kod', 'USD')->value('id');
        IsgucuKesintisi::factory()->create(['firma_id' => $tmc->id, 'adi_soyadi' => 'Zeki', 'tarih' => '2026-03-01', 'toplam_tutar' => '100.00', 'kalan_tutar' => '10.00']);
        IsgucuKesintisi::factory()->create(['firma_id' => $tmc->id, 'adi_soyadi' => 'Ali', 'tarih' => '2026-03-02', 'toplam_tutar' => '50.50', 'kalan_tutar' => '0.00']);
        IsgucuKesintisi::factory()->create(['firma_id' => $tmc->id, 'adi_soyadi' => 'Cem', 'tarih' => '2026-03-03', 'toplam_tutar' => '7.00', 'para_birimi_id' => $usd]);
        IsgucuKesintisi::factory()->create(['firma_id' => $hab->id, 'adi_soyadi' => 'Hab Personeli']);
        IsgucuKesintisi::factory()->create(['firma_id' => $tmc->id, 'adi_soyadi' => 'Nisan', 'tarih' => '2026-04-15']);

        $this->getJson(self::ADRES.'?'.http_build_query([
            'firma_id' => $tmc->id, 'baslangic' => '2026-03-01', 'bitis' => '2026-03-31',
            'sirala' => 'adi_soyadi', 'yon' => 'asc', 'page' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('data.*.adi_soyadi', ['Ali', 'Cem', 'Zeki'])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('ozet', [
                ['para_birimi' => 'TL', 'satir_sayisi' => 2, 'toplam_tutar' => '150.50', 'iptal_edilen_tutar' => '0.00', 'yansitilan_tutar' => '5124.30', 'kesilen_tutar' => '5124.30', 'kalan_tutar' => '10.00'],
                ['para_birimi' => 'USD', 'satir_sayisi' => 1, 'toplam_tutar' => '7.00', 'iptal_edilen_tutar' => '0.00', 'yansitilan_tutar' => '2562.15', 'kesilen_tutar' => '2562.15', 'kalan_tutar' => '0.00'],
            ]);

        // İlişkili tanım koduna göre sıralama
        $this->getJson(self::ADRES.'?'.http_build_query(['sirala' => 'taseron', 'yon' => 'asc', 'page' => 1]))
            ->assertOk()
            ->assertJsonPath('data.0.firma.kod', 'HAB');
    }

    public function test_arama_metin_kolonlarinda_buyuk_kucuk_harf_duyarsiz_arar(): void
    {
        IsgucuKesintisi::factory()->create(['adi_soyadi' => 'FIRAT AKYILDIZ', 'kaynak_fis_no' => '10397']);
        IsgucuKesintisi::factory()->create(['adi_soyadi' => 'ŞAHİN TUNÇ', 'kaynak_fis_no' => '10398']);

        $this->getJson(self::ADRES.'?ara=akyildiz&page=1')->assertOk()->assertJsonPath('data.*.adi_soyadi', ['FIRAT AKYILDIZ']);
        $this->getJson(self::ADRES.'?ara=10398&page=1')->assertOk()->assertJsonPath('data.*.adi_soyadi', ['ŞAHİN TUNÇ']);
    }

    public function test_satir_guncellenir_ve_silinir(): void
    {
        $kesinti = IsgucuKesintisi::factory()->create();

        $this->putJson(self::ADRES."/{$kesinti->id}", [...$this->gecerliGovde(), 'adi_soyadi' => 'Yeni Ad'])
            ->assertOk()
            ->assertJsonPath('data.adi_soyadi', 'Yeni Ad');

        $this->deleteJson(self::ADRES."/{$kesinti->id}")->assertNoContent();
        $this->assertModelMissing($kesinti);
    }

    public function test_kesintide_kullanilan_tasaron_silinemez(): void
    {
        $kesinti = IsgucuKesintisi::factory()->create();

        $this->deleteJson("/api/v1/kesinti/firmalar/{$kesinti->firma_id}")
            ->assertStatus(409)
            ->assertJsonPath('kod', 'KAYIT_KULLANILIYOR');

        $this->assertDatabaseHas('firmalar', ['id' => $kesinti->firma_id]);
    }

    public function test_secim_listeleri_tanim_ekranlarina_izni_olmayan_icmal_kullanicisina_da_acilir(): void
    {
        Firma::factory()->create(['kod' => 'HAB', 'ad' => 'HAB']);
        $rol = Rol::query()->create(['ad' => 'İşgücü izleyici']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'isgucu_kesintileri.goruntule']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        $this->actingAs($kullanici)->getJson('/api/v1/kesinti/secenekler')
            ->assertOk()
            ->assertJsonPath('data.firmalar.0.kod', 'HAB')
            ->assertJsonPath('data.para_birimleri.*.kod', ['EUR', 'TL', 'USD']);

        // Tanım ekranının kendisi açılmaz
        $this->actingAs($kullanici)->getJson('/api/v1/kesinti/firmalar')->assertForbidden();
        $this->actingAs(User::factory()->create())->getJson('/api/v1/kesinti/secenekler')->assertForbidden();
    }
}
