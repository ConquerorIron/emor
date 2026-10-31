<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\User;
use App\Models\YemekKesintisi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Yemek Kesintisi: bu icmalin kolonları, elle giriş,
 * bugünkü Excel başlıklarıyla aktarım, özet sekmeleri ve Excel sayfaları.
 * Ortak akış İşgücü testlerinde, izin matrisi EkranYetkisiTest'tedir.
 */
final class YemekKesintisiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/yemek';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
    }

    public function test_elle_girilen_satir_kaydedilir_hakedis_no_siniri_denetlenir(): void
    {
        $govde = [
            'kesinti_turu_id' => KesintiTuru::factory()->create(['kod' => 'YEMEK', 'icmal' => 'yemek'])->id,
            'firma_id' => Firma::factory()->create(['kod' => 'HAB'])->id,
            'yansitma_turu' => 'hab',
            'proje_id' => Proje::factory()->create(['kod' => 'ZONE 10'])->id,
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-01-01',
            'kaynak_firma' => 'ATİLLA GIDA',
            'kaynak_hakedis_no' => '1',
            'toplam_tutar' => '5097.83',
            'iptal_edilen_tutar' => '0',
            'kesilen_tutar' => '5000',
        ];

        $this->postJson(self::ADRES, $govde)
            ->assertCreated()
            ->assertJsonPath('data.kaynak_hakedis_no', '1')
            ->assertJsonPath('data.kalan_tutar', '97.83')
            ->assertJsonMissingPath('data.birim_fiyat');

        $this->postJson(self::ADRES, [...$govde, 'kaynak_hakedis_no' => str_repeat('1', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('kaynak_hakedis_no', 'hatalar');
    }

    public function test_yapistirma_kolonlari_kullanicinin_sirasinda(): void
    {
        $this->getJson(self::ADRES.'/yuklemeler/kolonlar')
            ->assertOk()
            ->assertJsonPath('data.*.alan', [
                'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'mahal', 'tarih', 'kaynak_firma',
                'kaynak_hakedis_no', 'para_birimi',
                'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
            ]);
    }

    public function test_bugunku_excel_ogun_kolonlari_atlanarak_aktarilir(): void
    {
        KesintiTuru::factory()->create(['kod' => 'YEMEK', 'icmal' => 'yemek']);
        Proje::factory()->create(['kod' => 'ZONE 10']);
        Firma::factory()->create(['kod' => 'HAB']);

        // Gerçek dosyanın düzeni: başlık 5. satırda, öğün adet / birim fiyat / tutar kolonları arada
        $kitap = new Spreadsheet;
        $kitap->getActiveSheet()->fromArray([
            ['KESİNTİ TÜRÜ', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA TÜRÜ', 'PROJE', 'MAHAL', 'TARİH', 'KAYNAK FİRMA', 'KAYNAK HAKEDİŞ NO', 'PARA BİRİMİ', 'SABAH', 'SABAH BİRİM FİYAT', 'SABAH TUTAR', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            ['YEMEK', 'HAB', 'HAB', 'ZONE 10', 'ZONE 10', 46023, 'ATİLLA GIDA', 1, 'TL', 11.88, 71, 843.66, 5097.8292686935, 0, 5097.8292686935, 5097.8292686935, 0],
        ], null, 'A5');
        $yol = tempnam(sys_get_temp_dir(), 'yemek').'.xlsx';
        (new Xlsx($kitap))->save($yol);

        $this->post(self::ADRES.'/yuklemeler', ['dosya' => new UploadedFile($yol, 'Z10 - Yemek.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.satir_sayisi', 1);

        $this->assertDatabaseHas('yemek_kesintileri', [
            'kaynak_firma' => 'ATİLLA GIDA', 'kaynak_hakedis_no' => '1', 'tarih' => '2026-01-01',
            'yansitma_turu' => 'hab', 'toplam_tutar' => '5097.83',
        ]);
    }

    public function test_ozet_sekmeleri_ve_excel_sayfalari(): void
    {
        $proje = Proje::factory()->create(['kod' => 'ZONE 10']);
        YemekKesintisi::factory()->create(['proje_id' => $proje->id, 'tarih' => '2026-01-01', 'toplam_tutar' => '100.00']);
        YemekKesintisi::factory()->create(['proje_id' => $proje->id, 'tarih' => '2026-01-02', 'toplam_tutar' => '50.00']);

        $this->getJson(self::ADRES.'/pivot/tarih')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.toplam_tutar', '150.00');

        $yanit = $this->get(self::ADRES.'/excel')->assertOk();
        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'], $kitap->getSheetNames());
    }
}
