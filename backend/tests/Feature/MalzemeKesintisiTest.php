<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\MalzemeKesintisi;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Malzeme Kesintisi: kolon sırası, elle giriş, "Ambar
 * Malzeme" sayfasının aktarımı, özet sekmeleri. Ortak akış İşgücü
 * testlerinde, izinler EkranYetkisiTest'tedir.
 */
final class MalzemeKesintisiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
        KesintiTuru::factory()->create(['kod' => 'MALZEME KESİNTİSİ', 'icmal' => 'malzeme']);
        Proje::factory()->create(['kod' => 'ZONE 10']);
        Firma::factory()->create(['kod' => 'TMC KABA']);
    }

    public function test_yapistirma_kolonlari_kullanicinin_sirasinda(): void
    {
        $this->getJson('/api/v1/kesinti/malzeme/yuklemeler/kolonlar')
            ->assertOk()
            ->assertJsonPath('data.*.alan', [
                'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'mahal', 'tarih', 'kaynak_firma',
                'kaynak_irsaliye_no', 'urun_tanimi', 'malzeme_birim_fiyati', 'miktar', 'birim', 'para_birimi',
                'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
            ]);
    }

    public function test_elle_giris_malzeme_alanlariyla_kaydedilir(): void
    {
        $govde = [
            'kesinti_turu_id' => KesintiTuru::query()->value('id'),
            'firma_id' => Firma::query()->value('id'),
            'yansitma_turu' => 'taseron',
            'proje_id' => Proje::query()->value('id'),
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-04-15',
            'kaynak_irsaliye_no' => 'ZDF2023000000299',
            'urun_tanimi' => 'HYTERA BD555U UHF DİJİTAL EKRANLI EL TELSİZİ',
            'malzeme_birim_fiyati' => '2489.64',
            'miktar' => '12',
            'birim' => 'ADET',
            'toplam_tutar' => '29875.68',
            'iptal_edilen_tutar' => '0',
            'kesilen_tutar' => '20000',
        ];

        $this->postJson('/api/v1/kesinti/malzeme', [...$govde, 'urun_tanimi' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('urun_tanimi', 'hatalar');

        $this->postJson('/api/v1/kesinti/malzeme', $govde)
            ->assertCreated()
            ->assertJsonPath('data.kaynak_irsaliye_no', 'ZDF2023000000299')
            ->assertJsonPath('data.malzeme_birim_fiyati', '2489.6400')
            ->assertJsonPath('data.miktar', '12.00')
            ->assertJsonPath('data.kalan_tutar', '9875.68');
    }

    public function test_ambar_malzeme_sayfasi_aktarilir_kdv_kolonlari_alinmaz(): void
    {
        // Gerçek dosyanın düzeni (yansıtma kolonu eklenmiş); FİRMA kaynak firmadır
        $kitap = new Spreadsheet;
        $kitap->getActiveSheet()->setTitle('Ambar Malzeme')->fromArray([
            ['S.NO', 'FİRMA', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 'KESİNTİ TÜRÜ', 'BÜTÇE', 'ÜRÜN TANIMI', 'TARİH', 'İRSALİYE NO', 'MALZEME BİRİM FİYATI', 'MİKTAR', 'BİRİM', 'PARA BİRİMİ', 'KDV, %', 'TEVKİFAT ORANI, %', 'KDV TEVKİFATI', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            [5, 'İSG BİRİMİ', 'TMC KABA', 'TAŞERON', 'MALZEME KESİNTİSİ', 'ZONE 10', 'GERİ SARIMLI DÜŞÜŞ DURDURUCU TEO 10MT', 46157, '006.01.99.DV00050019', 6200, 10, 'ADET', 'TL', 0, 0, null, 62000, 0, 62000, 62000, 0],
            [6, 'İSG BİRİMİ', 'TMC KABA', 'TAŞERON', 'MALZEME KESİNTİSİ', 'ZONE 10', 'HALAT TUTUCU', 46157, '006.06.04.0000060501', 350, 15, 'ADET', 'TL', 0, 0, null, 5250, 0, 5250, 5250, 0],
        ], null, 'A5');
        $yol = tempnam(sys_get_temp_dir(), 'malzeme').'.xlsx';
        (new Xlsx($kitap))->save($yol);

        $this->post('/api/v1/kesinti/malzeme/yuklemeler', ['dosya' => new UploadedFile($yol, 'Ambar Malzeme.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.satir_sayisi', 2);

        $this->assertDatabaseHas('malzeme_kesintileri', [
            'kaynak_firma' => 'İSG BİRİMİ',
            'kaynak_irsaliye_no' => '006.06.04.0000060501',
            'urun_tanimi' => 'HALAT TUTUCU',
            'malzeme_birim_fiyati' => '350.0000',
            'miktar' => '15.00',
            'birim' => 'ADET',
            'toplam_tutar' => '5250.00',
            'tarih' => '2026-05-15',
        ]);
    }

    public function test_ozet_sekmeleri_ve_excel_sayfalari(): void
    {
        MalzemeKesintisi::factory()->count(2)->create(['proje_id' => Proje::query()->value('id')]);

        $this->getJson('/api/v1/kesinti/malzeme/pivot/tarih')
            ->assertOk()
            ->assertJsonPath('data.0.toplam_tutar', '10500.00');

        $yanit = $this->get('/api/v1/kesinti/malzeme/excel')->assertOk();
        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'], $kitap->getSheetNames());
        $this->assertSame('MALZEME BİRİM FİYATI', $kitap->getSheet(0)->getCell('L1')->getValue());
    }
}
