<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\TutanakKesintisi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Tutanak Kesintisi: bu icmalin kolonları, elle giriş
 * kuralları, bugünkü Excel başlıklarıyla aktarım, özet sekmeleri ve Excel
 * sayfaları. Ortak akış İşgücü testlerinde, izin matrisi EkranYetkisiTest'tedir.
 */
final class TutanakKesintisiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/tutanak';

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
            'kesinti_turu_id' => KesintiTuru::factory()->create(['kod' => 'TUTANAK', 'icmal' => 'tutanak'])->id,
            'firma_id' => Firma::factory()->create(['kod' => 'TMC KABA'])->id,
            'yansitma_turu' => 'taseron',
            'proje_id' => Proje::factory()->create(['kod' => 'ZONE 10'])->id,
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-02-15',
            'kaynak_firma' => 'MFK',
            'kaynak_tutanak_no' => 'T-125',
            'kaynak_sozlesme' => 'KABA İNŞAAT',
            'kaynak_hakedis_no' => '2-ARA',
            'aktivite' => 'İLAVE BETON DÖKÜMÜ',
            'toplam_tutar' => '1000',
            'iptal_edilen_tutar' => '100',
            'kesilen_tutar' => '500',
        ];
    }

    public function test_elle_girilen_satir_tutanaga_ozgu_alanlarla_kaydedilir(): void
    {
        $this->postJson(self::ADRES, $this->gecerliGovde())
            ->assertCreated()
            ->assertJsonPath('data.kaynak_tutanak_no', 'T-125')
            ->assertJsonPath('data.kaynak_sozlesme', 'KABA İNŞAAT')
            ->assertJsonPath('data.kaynak_hakedis_no', '2-ARA')
            ->assertJsonPath('data.aktivite', 'İLAVE BETON DÖKÜMÜ')
            ->assertJsonPath('data.yansitilan_tutar', '900.00')
            ->assertJsonPath('data.kalan_tutar', '400.00')
            ->assertJsonMissingPath('data.birim_fiyat');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizAlanlar(): array
    {
        return [
            'tutanak no 100 karakteri aşıyor' => [['kaynak_tutanak_no' => str_repeat('1', 101)], 'kaynak_tutanak_no'],
            'sözleşme 100 karakteri aşıyor' => [['kaynak_sozlesme' => str_repeat('A', 101)], 'kaynak_sozlesme'],
            'hakediş no 100 karakteri aşıyor' => [['kaynak_hakedis_no' => str_repeat('1', 101)], 'kaynak_hakedis_no'],
            'aktivite 200 karakteri aşıyor' => [['aktivite' => str_repeat('A', 201)], 'aktivite'],
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

        $this->assertDatabaseCount('tutanak_kesintileri', 0);
    }

    public function test_yapistirma_kolonlari_kullanicinin_sirasinda(): void
    {
        $this->getJson(self::ADRES.'/yuklemeler/kolonlar')
            ->assertOk()
            ->assertJsonPath('data.*.alan', [
                'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'mahal', 'tarih', 'kaynak_firma',
                'kaynak_tutanak_no', 'kaynak_sozlesme', 'kaynak_hakedis_no', 'aktivite', 'para_birimi',
                'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
            ]);
    }

    public function test_bugunku_excel_basliklariyla_aktarilir(): void
    {
        KesintiTuru::factory()->create(['kod' => 'TUTANAK', 'icmal' => 'tutanak']);
        Proje::factory()->create(['kod' => 'ZONE 10']);
        Firma::factory()->create(['kod' => 'TMC KABA']);

        // Gerçek dosyanın adları (başlık 7. satırda, üstte para birimine göre ara toplamlar); yalnız YANSITMA eklenmiş
        $kitap = new Spreadsheet;
        $sayfa = $kitap->getActiveSheet();
        $sayfa->fromArray([['', '', '', '', '', '', '', '', '', 'TL', 'MALZEME TUTAR', 'İŞÇİLİK TUTAR', 'TOPLAM TUTAR', 'İPTAL TUTAR', 'YANSITILACAK TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR']], null, 'A2');
        $sayfa->fromArray([
            ['SIRA', 'FİRMA', 'SÖZLEŞME KAPSAMI', 'HAKEDİŞ NO', 'TARİH', 'İŞ KAPSAMI', 'KESİNTİ TÜRÜ', 'BÜTÇE', 'TUTANAK NO', 'PARA BİRİMİ', 'MALZEME TUTAR', 'İŞÇİLİK TUTAR', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR', 'DURUMU', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA'],
            [1, 'MFK', 'KABA İNŞAAT', '3-ARA', 46068, 'İLAVE BETON', 'TUTANAK', 'ZONE 10', 125, 'TL', 600, 400, 1000, 0, 1000, 1000, 0, 'KESİLDİ', 'TMC KABA', 'TAŞERON'],
        ], null, 'A7');
        $yol = tempnam(sys_get_temp_dir(), 'tutanak').'.xlsx';
        (new Xlsx($kitap))->save($yol);

        $this->post(self::ADRES.'/yuklemeler', ['dosya' => new UploadedFile($yol, 'Z10 - İlave Tutanak.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.satir_sayisi', 1);

        $this->assertDatabaseHas('tutanak_kesintileri', [
            'kaynak_firma' => 'MFK', 'kaynak_sozlesme' => 'KABA İNŞAAT', 'kaynak_hakedis_no' => '3-ARA',
            'kaynak_tutanak_no' => '125', 'aktivite' => 'İLAVE BETON', 'tarih' => '2026-02-15',
            'yansitma_turu' => 'taseron', 'toplam_tutar' => '1000.00',
        ]);
    }

    public function test_ozet_sekmeleri_ve_excel_sayfalari(): void
    {
        $proje = Proje::factory()->create(['kod' => 'ZONE 10']);
        TutanakKesintisi::factory()->create(['proje_id' => $proje->id, 'tarih' => '2026-02-15', 'toplam_tutar' => '1000.00']);
        TutanakKesintisi::factory()->create(['proje_id' => $proje->id, 'tarih' => '2026-03-01', 'toplam_tutar' => '250.00']);

        $this->getJson(self::ADRES.'/pivot/proje')
            ->assertOk()
            ->assertJsonPath('data.0.toplam_tutar', '1250.00')
            ->assertJsonPath('data.0.satir_sayisi', 2);
        $this->getJson(self::ADRES.'/pivot/tarih')
            ->assertOk()
            ->assertJsonPath('data.*.ay', [2, 3]);

        $yanit = $this->get(self::ADRES.'/excel')->assertOk();
        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'], $kitap->getSheetNames());
        $this->assertSame('KAYNAK TUTANAK NO', $kitap->getSheet(0)->getCell('J1')->getValue());
    }
}
