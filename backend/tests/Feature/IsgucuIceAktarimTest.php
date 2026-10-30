<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Models\Proje;
use App\Models\Rol;
use App\Models\User;
use App\Services\Kesinti\IsgucuIceAktarimi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * İşgücü Kesintileri içe aktarımı: Excel dosyası ve kopyala-yapıştır,
 * önizleme, hatalıyken hiç yazmama, parti olarak kayıt ve geri alma.
 */
final class IsgucuIceAktarimTest extends TestCase
{
    use RefreshDatabase;

    private const ONIZLE = '/api/v1/kesinti/isgucu/yuklemeler/onizle';

    private const KAYDET = '/api/v1/kesinti/isgucu/yuklemeler';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
        KesintiTuru::factory()->create(['kod' => 'İŞGÜCÜ']);
        Proje::factory()->create(['kod' => 'ZONE 10']);
        Firma::factory()->create(['kod' => 'HAB']);
        Firma::factory()->create(['kod' => 'TMC KABA']);
    }

    /**
     * Standart başlık sırasıyla bir satır (değişiklikler üzerine yazılır).
     *
     * @param  array<string, string>  $degisiklik
     * @return list<string>
     */
    private static function satir(array $degisiklik = []): array
    {
        $satir = [
            'kesinti_turu' => 'İŞGÜCÜ', 'taseron' => 'HAB', 'proje' => 'ZONE 10', 'mahal' => 'ZONE 10',
            'tarih' => '27.03.2026', 'kaynak_firma' => 'MFK', 'kaynak_fis_no' => '10397', 'adi_soyadi' => 'FIRAT AKYILDIZ',
            'vasif' => 'NİTELİKSİZ', 'mesai_turu' => 'NORMAL MESAİ', 'yansitma_turu' => 'HAB', 'tanim' => 'DÜZ İŞÇİ',
            'gorev' => 'KABA', 'departman' => 'KABA YAPI', 'aktivite' => 'GENEL TEMİZLİK',
            'miktar_saat' => '-', 'miktar_yevmiye' => '1', 'birim_fiyat' => '2.562,15206833', 'para_birimi' => 'TL',
            'toplam_tutar' => '2.562,15', 'iptal_edilen_tutar' => '0', 'yansitilan_tutar' => '2.562,15',
            'kesilen_tutar' => '2.000,00', 'kalan_tutar' => '562,15',
            ...$degisiklik,
        ];

        return array_values(array_map(fn (string $alan): string => $satir[$alan], array_keys(IsgucuIceAktarimi::KOLONLAR)));
    }

    /**
     * Excel'den kopyalanmış gibi: başlık + satırlar, sekmeyle ayrılmış.
     *
     * @param  list<list<string>>  $satirlar
     */
    private static function yapistirma(array $satirlar): string
    {
        $baslik = array_map(fn (array $basliklar): string => $basliklar[0], array_values(IsgucuIceAktarimi::KOLONLAR));

        return implode("\n", array_map(fn (array $satir): string => implode("\t", $satir), [$baslik, ...$satirlar]));
    }

    public function test_yapistirilan_metin_onizlenir_ve_hicbir_sey_yazilmaz(): void
    {
        $this->postJson(self::ONIZLE, ['metin' => self::yapistirma([self::satir(), self::satir(['taseron' => 'tmc kaba', 'mesai_turu' => 'Fazla Mesai'])])])
            ->assertOk()
            ->assertJsonPath('data.hatalar', [])
            ->assertJsonCount(2, 'data.satirlar')
            ->assertJsonPath('data.satirlar.0.miktar_saat', '0.00')
            ->assertJsonPath('data.satirlar.0.birim_fiyat', '2562.1521')
            ->assertJsonPath('data.satirlar.0.tarih', '2026-03-27')
            ->assertJsonPath('data.satirlar.1.mesai_turu', 'FAZLA MESAİ')
            ->assertJsonPath('data.toplamlar.0.para_birimi', 'TL')
            ->assertJsonPath('data.toplamlar.0.toplam_tutar', '5124.30')
            ->assertJsonMissingPath('data.kayitlar');

        $this->assertDatabaseCount('isgucu_kesintileri', 0);
        $this->assertDatabaseCount('kesinti_yuklemeleri', 0);
    }

    public function test_hatalar_nedenine_gore_gruplanir_ve_hatali_dosya_hic_kaydedilmez(): void
    {
        // Aynı koda sahip iki firma: Excel'deki ad tek firmaya düşmez
        Firma::factory()->count(2)->create(['kod' => 'MFK']);
        $metin = self::yapistirma([
            self::satir(),
            self::satir(['taseron' => 'YOK FİRMA']),
            self::satir(['taseron' => 'YOK FİRMA']),
            self::satir(['taseron' => 'MFK']),
            self::satir(['yansitma_turu' => '%50 TMC-%50 HAB', 'tarih' => '31.02.2026', 'birim_fiyat' => 'abc']),
        ]);

        $yanit = $this->postJson(self::ONIZLE, ['metin' => $metin])->assertOk();

        $hatalar = array_map(
            fn (array $h): string => "{$h['alan']}:{$h['kod']}:{$h['deger']}:{$h['adet']}:".implode(',', $h['satirlar']),
            $yanit->json('data.hatalar'),
        );
        sort($hatalar);
        $this->assertSame([
            'birim_fiyat:gecersiz_sayi:abc:1:6',
            'tarih:gecersiz_tarih:31.02.2026:1:6',
            'taseron:birden_fazla:MFK:1:5',
            'taseron:bulunamadi:YOK FİRMA:2:3,4',
            'yansitma_turu:gecersiz_secenek:%50 TMC-%50 HAB:1:6',
        ], $hatalar);

        // Önizlemesiz kayıt da reddedilir; yanıt hataları önizlemedeki gibi taşır (ekran gösterir)
        $this->postJson(self::KAYDET, ['metin' => $metin])
            ->assertUnprocessable()
            ->assertJsonPath('kod', 'ICE_AKTARIM_HATALI')
            ->assertJsonCount(5, 'data.hatalar')
            ->assertJsonCount(5, 'data.satirlar')
            ->assertJsonPath('data.satirlar.1.hatali', true);

        $this->assertDatabaseCount('isgucu_kesintileri', 0);
        $this->assertDatabaseCount('kesinti_yuklemeleri', 0);
    }

    public function test_hatasiz_yapistirma_tek_parti_olarak_excel_tutarlariyla_kaydedilir(): void
    {
        $this->postJson(self::KAYDET, ['metin' => self::yapistirma([self::satir(), self::satir(['adi_soyadi' => 'ŞAHİN TUNÇ'])])])
            ->assertCreated()
            ->assertJsonPath('data.kaynak', 'yapistirma')
            ->assertJsonPath('data.satir_sayisi', 2);

        $yukleme = KesintiYuklemesi::query()->sole();
        $this->assertSame(2, IsgucuKesintisi::query()->where('yukleme_id', $yukleme->id)->count());
        // Kalan Excel'deki gibi (562,15), yeniden hesaplanmaz
        $this->assertDatabaseHas('isgucu_kesintileri', [
            'adi_soyadi' => 'ŞAHİN TUNÇ', 'tarih' => '2026-03-27', 'vasif' => 'niteliksiz', 'mesai_turu' => 'normal',
            'yansitma_turu' => 'hab', 'miktar_saat' => '0.00', 'birim_fiyat' => '2562.1521', 'kesilen_tutar' => '2000.00', 'kalan_tutar' => '562.15',
        ]);
    }

    public function test_excel_dosyasi_ust_satirlardaki_ara_toplama_ragmen_dogru_baslikla_okunur(): void
    {
        $kitap = new Spreadsheet;
        $sayfa = $kitap->getActiveSheet();
        // Gerçek dosyadaki gibi: üstte başlık metni ve TOPLAM TUTAR… ara toplam satırı
        $sayfa->fromArray([['TERSANE İSTANBUL PROJESİ İŞGÜCÜ KESİNTİSİ'], [], ['', '', '', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR']], null, 'A1');
        // Bugünkü Excel'in başlık adları (eski adlar + fazladan HAKEDİŞ / AY kolonu)
        $sayfa->fromArray([[
            'TARİH', 'FİRMA', 'HAKEDİŞ', 'AY', 'NO', 'ADI - SOYADI', 'VASFI', 'NORMAL MESAİ M./ FAZLA M.', 'KESİNTİ TÜRÜ',
            'KESİNTİ YAPILACAK FİRMA', 'YANSITMA 2', 'BÜTÇE', 'MAHAL', 'TANIM', 'GÖREV', 'BİRİM', 'AKTİVİTE',
            'MİKTAR (saat)', 'MİKTAR (yevmiye)', 'BİRİM FİYAT', 'PARA BİRİMİ', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR',
            'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR',
        ], [
            46108, 'MFK', '3-ARA', 'MART', 10397, 'FIRAT AKYILDIZ', 'NİTELİKSİZ', 'NORMAL MESAİ', 'İŞGÜCÜ',
            'HAB', 'HAB', 'ZONE 10', 'ZONE 10', 'DÜZ İŞÇİ', 'KABA', 'KABA YAPI', 'GENEL TEMİZLİK',
            '-', 1, 2562.15206833333, 'TL', 2562.15206833333, 0, 2562.15206833333, 2562.15206833333, 0,
        ]], null, 'A5');
        $yol = tempnam(sys_get_temp_dir(), 'isgucu').'.xlsx';
        (new Xlsx($kitap))->save($yol);

        $this->post(self::KAYDET, ['dosya' => new UploadedFile($yol, 'Z10 - İşgücü.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.kaynak', 'excel')
            ->assertJsonPath('data.dosya_adi', 'Z10 - İşgücü.xlsx')
            ->assertJsonPath('data.satir_sayisi', 1);

        $this->assertDatabaseHas('isgucu_kesintileri', [
            'tarih' => '2026-03-27', 'kaynak_fis_no' => '10397', 'departman' => 'KABA YAPI',
            'miktar_saat' => '0.00', 'birim_fiyat' => '2562.1521', 'toplam_tutar' => '2562.15',
        ]);
    }

    public function test_yapistirma_tablosu_kolonlari_standart_sirayla_ve_kabul_edilen_basliklarla_doner(): void
    {
        $this->getJson(self::KAYDET.'/kolonlar')
            ->assertOk()
            ->assertJsonCount(24, 'data')
            ->assertJsonPath('data.0', ['alan' => 'kesinti_turu', 'basliklar' => ['KESİNTİ TÜRÜ']])
            ->assertJsonPath('data.1.basliklar', ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'])
            // Yansıtma türü taşeronun hemen sağında (yapıştırma sırası)
            ->assertJsonPath('data.2.alan', 'yansitma_turu');
    }

    public function test_basliksiz_yapistirma_standart_kolon_sirasiyla_okunur_ve_satir_numaralari_korunur(): void
    {
        // Yapıştırma tablosu başlıksız gönderir; aradaki boş satır numaralamayı kaydırmaz
        $metin = implode("\n", [implode("\t", self::satir()), '', implode("\t", self::satir(['taseron' => 'YOK FİRMA']))]);

        $this->postJson(self::ONIZLE, ['metin' => $metin])
            ->assertOk()
            ->assertJsonCount(2, 'data.satirlar')
            ->assertJsonPath('data.satirlar.0.satir', 1)
            ->assertJsonPath('data.hatalar.0.satirlar', ['3']);
    }

    public function test_zorunlu_kolon_eksikse_hangi_kolonlar_oldugu_doner(): void
    {
        $metin = "KESİNTİ TÜRÜ\tTAŞERON ADI\tPROJE\tTARİH\nİŞGÜCÜ\tHAB\tZONE 10\t27.03.2026";

        $eksik = $this->postJson(self::ONIZLE, ['metin' => $metin])->assertOk()->json('data.eksik_kolonlar');

        $this->assertContains('adi_soyadi', $eksik);
        $this->assertNotContains('taseron', $eksik);
    }

    public function test_parti_geri_alininca_satirlariyla_silinir_elle_girilen_satir_kalir(): void
    {
        $this->postJson(self::KAYDET, ['metin' => self::yapistirma([self::satir(), self::satir()])])->assertCreated();
        $elle = IsgucuKesintisi::factory()->create();
        $yukleme = KesintiYuklemesi::query()->sole();

        $this->getJson(self::KAYDET)->assertOk()->assertJsonPath('data.0.satir_sayisi', 2);
        $this->deleteJson(self::KAYDET."/{$yukleme->id}")->assertNoContent();

        $this->assertModelMissing($yukleme);
        $this->assertSame([$elle->id], IsgucuKesintisi::query()->pluck('id')->all());
    }

    public function test_yalniz_goruntuleme_izniyle_ice_aktarilamaz(): void
    {
        $rol = Rol::query()->create(['ad' => 'İşgücü izleyici']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'isgucu_kesintileri.goruntule']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);
        $metin = self::yapistirma([self::satir()]);

        $this->actingAs($kullanici)->postJson(self::ONIZLE, ['metin' => $metin])->assertForbidden();
        $this->actingAs($kullanici)->postJson(self::KAYDET, ['metin' => $metin])->assertForbidden();

        $this->assertDatabaseCount('isgucu_kesintileri', 0);
    }
}
