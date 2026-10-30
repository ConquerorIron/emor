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
use App\Services\Kesinti\IsgucuIceAktarimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
            'kesinti_turu_id' => KesintiTuru::factory()->create(['kod' => 'İŞGÜCÜ', 'icmal' => 'isgucu'])->id,
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

    public function test_excele_aktarim_filtredeki_tum_satirlari_tum_kolonlarla_yapistirma_sirasinda_verir(): void
    {
        $tmc = Firma::factory()->create(['kod' => 'TMC KABA']);
        IsgucuKesintisi::factory()->create(['firma_id' => $tmc->id, 'adi_soyadi' => '=KOMUT()', 'tarih' => '2026-03-27', 'birim_fiyat' => '2562.1521']);
        IsgucuKesintisi::factory()->create(['adi_soyadi' => 'Başka Taşeron']);

        $yanit = $this->get(self::ADRES.'/excel?'.http_build_query(['firma_id' => $tmc->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sayfa = IOFactory::load($yanit->baseResponse->getFile()->getPathname())->getActiveSheet();
        $satirlar = $sayfa->toArray(null, false, false);
        $basliklar = $satirlar[0];

        // Yapıştırma sırası + tarihin hemen sağında Yıl / Ay; başlıklar içe aktarımın standart adları
        $beklenen = array_map(fn (array $b): string => $b[0], array_values(IsgucuIceAktarimi::KOLONLAR));
        array_splice($beklenen, array_search('TARİH', $beklenen, true) + 1, 0, ['YIL', 'AY']);
        $this->assertSame($beklenen, $basliklar);

        $this->assertCount(2, $satirlar, 'Filtre dışındaki satır aktarılmaz');
        $satir = array_combine($basliklar, $satirlar[1]);
        $this->assertSame('TMC KABA', $satir['TAŞERON ADI']);
        $this->assertSame('HAB', $satir['YANSITMA TÜRÜ']);
        $this->assertSame(2026, (int) $satir['YIL']);
        $this->assertSame('Mart', $satir['AY']);
        $this->assertSame(2562.1521, (float) $satir['BİRİM FİYAT']);
        // Formül gibi görünen metin formül olarak yazılmaz
        $this->assertSame('=KOMUT()', $satir['ADI SOYADI']);
        $this->assertSame('s', $sayfa->getCell('I2')->getDataType());
    }

    public function test_ozet_sekmeleri_filtredeki_satirlari_gruplayip_toplar(): void
    {
        $tmc = Firma::factory()->create(['kod' => 'TMC KABA', 'ad' => 'TMC Yapı']);
        $hab = Firma::factory()->create(['kod' => 'HAB']);
        $proje = Proje::factory()->create(['kod' => 'ZONE 10']);
        $ortak = ['proje_id' => $proje->id, 'iptal_edilen_tutar' => '0.00', 'yansitilan_tutar' => '0.00', 'kesilen_tutar' => '0.00', 'kalan_tutar' => '0.00'];
        IsgucuKesintisi::factory()->create([...$ortak, 'firma_id' => $tmc->id, 'tarih' => '2026-03-02', 'mesai_turu' => 'fazla', 'miktar_saat' => '3.00', 'toplam_tutar' => '100.00']);
        IsgucuKesintisi::factory()->create([...$ortak, 'firma_id' => $tmc->id, 'tarih' => '2026-03-28', 'mesai_turu' => 'normal', 'miktar_saat' => '0.50', 'toplam_tutar' => '50.25']);
        IsgucuKesintisi::factory()->create([...$ortak, 'firma_id' => $hab->id, 'tarih' => '2026-04-01', 'mesai_turu' => 'normal', 'miktar_saat' => '2.00', 'toplam_tutar' => '10.00']);

        $this->getJson(self::ADRES.'/pivot/taseron')
            ->assertOk()
            ->assertJsonPath('data.*.taseron', ['HAB', 'TMC KABA'])
            ->assertJsonPath('data.1', [
                'taseron' => 'TMC KABA', 'proje' => 'ZONE 10', 'para_birimi' => 'TL', 'taseron_ad' => 'TMC Yapı', 'satir_sayisi' => 2,
                'miktar_saat' => '3.50', 'toplam_tutar' => '150.25', 'iptal_edilen_tutar' => '0.00', 'yansitilan_tutar' => '0.00',
                'kesilen_tutar' => '0.00', 'kalan_tutar' => '0.00',
            ]);

        // Tarih sekmesi tarihten türetilen yıl / aya göre; liste filtresi uygulanır
        $this->getJson(self::ADRES.'/pivot/tarih?'.http_build_query(['firma_id' => $tmc->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.yil', 2026)
            ->assertJsonPath('data.0.ay', 3)
            ->assertJsonPath('data.0.toplam_tutar', '150.25');

        $this->getJson(self::ADRES.'/pivot/mesai_turu')
            ->assertOk()
            ->assertJsonPath('data.*.mesai_turu', ['fazla', 'normal'])
            ->assertJsonPath('data.1.miktar_saat', '2.50');

        $this->getJson(self::ADRES.'/pivot/adi_soyadi')->assertNotFound();
    }

    public function test_baska_ekrana_bagli_kesinti_turuyle_satir_girilemez(): void
    {
        $kamp = KesintiTuru::factory()->create(['kod' => 'KAMP', 'icmal' => 'kamp']);

        $this->postJson(self::ADRES, [...$this->gecerliGovde(), 'kesinti_turu_id' => $kamp->id])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.kesinti_turu_id.0', __('hata.kesinti_turu_baska_ekranin'));

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
