<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\KampKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Kamp (oda) Kesintileri: bu icmalin kolonları, elle
 * giriş kuralları ve Kamp dosyasından oda sayfasının aktarımı. Ortak akış
 * İşgücü testlerinde, izin matrisi EkranYetkisiTest'tedir.
 */
final class KampKesintisiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/kamp';

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
            'kesinti_turu_id' => KesintiTuru::factory()->create(['kod' => 'KAMP', 'icmal' => 'kamp'])->id,
            'firma_id' => Firma::factory()->create(['kod' => 'MFK'])->id,
            'yansitma_turu' => 'taseron',
            'proje_id' => Proje::factory()->create(['kod' => 'TÜM MAHALLER (ORTAK)'])->id,
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-01-01',
            'oda_tipi' => '6 KİŞİLİK ODA',
            'toplam_oda' => '489',
            'birim_fiyat' => '1200',
            'toplam_tutar' => '586800',
            'iptal_edilen_tutar' => '586800',
            'kesilen_tutar' => '0',
        ];
    }

    public function test_elle_girilen_satir_oda_tipi_ve_toplam_oda_ile_kaydedilir(): void
    {
        $this->postJson(self::ADRES, $this->gecerliGovde())
            ->assertCreated()
            ->assertJsonPath('data.oda_tipi', '6 KİŞİLİK ODA')
            ->assertJsonPath('data.toplam_oda', '489.00')
            ->assertJsonPath('data.yansitilan_tutar', '0.00')
            ->assertJsonPath('data.kalan_tutar', '0.00');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizAlanlar(): array
    {
        return [
            'oda tipi boş' => [['oda_tipi' => ''], 'oda_tipi'],
            'oda tipi 100 karakteri aşıyor' => [['oda_tipi' => str_repeat('A', 101)], 'oda_tipi'],
            'toplam oda 2 ondalığı aşıyor' => [['toplam_oda' => '1.125'], 'toplam_oda'],
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

        $this->assertDatabaseCount('kamp_kesintileri', 0);
    }

    public function test_toplamlar_ozet_sekmeleri_ve_excel_sayfalari_toplam_odayi_icerir(): void
    {
        $proje = Proje::factory()->create(['kod' => 'TÜM MAHALLER (ORTAK)']);
        $mfk = Firma::factory()->create(['kod' => 'MFK']);
        KampKesintisi::factory()->create(['firma_id' => $mfk->id, 'proje_id' => $proje->id, 'yansitma_turu' => 'taseron', 'toplam_oda' => '17.00', 'toplam_tutar' => '20400.00']);
        KampKesintisi::factory()->create(['firma_id' => $mfk->id, 'proje_id' => $proje->id, 'yansitma_turu' => 'hab', 'toplam_oda' => '489.00', 'toplam_tutar' => '586800.00']);

        // Tablonun altı / üstü: para birimine göre toplam oda + tutarlar
        $this->getJson(self::ADRES.'?page=1')
            ->assertOk()
            ->assertJsonPath('ozet.0.toplam_oda', '506.00')
            ->assertJsonPath('ozet.0.toplam_tutar', '607200.00');

        $this->getJson(self::ADRES.'/pivot/taseron')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.taseron', 'MFK')
            ->assertJsonPath('data.0.toplam_oda', '506.00');
        $this->getJson(self::ADRES.'/pivot/yansitma_turu')
            ->assertOk()
            ->assertJsonPath('data.*.yansitma_turu', ['hab', 'taseron']);
        // İşgücü'ne özgü sekme burada yok
        $this->getJson(self::ADRES.'/pivot/vasif')->assertNotFound();

        $yanit = $this->get(self::ADRES.'/excel')->assertOk();
        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje'], $kitap->getSheetNames());
        $this->assertSame(['PROJE', 'PARA BİRİMİ', 'SATIR SAYISI', 'TOPLAM ODA'], array_slice($kitap->getSheetByName('Proje')?->toArray()[0] ?? [], 0, 4));
    }

    public function test_yapistirma_kolonlari_kullanicinin_nihai_sirasinda(): void
    {
        $this->getJson(self::ADRES.'/yuklemeler/kolonlar')
            ->assertOk()
            ->assertJsonPath('data.*.alan', [
                'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'mahal', 'tarih', 'kaynak_firma',
                'oda_tipi', 'toplam_oda', 'birim_fiyat', 'para_birimi',
                'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
            ]);
    }

    public function test_kamp_dosyasindan_yalniz_oda_sayfasi_gun_kolonlari_olmadan_aktarilir(): void
    {
        KesintiTuru::factory()->create(['kod' => 'KAMP', 'icmal' => 'kamp']);
        Proje::factory()->create(['kod' => 'TÜM MAHALLER (ORTAK)']);
        Firma::factory()->create(['kod' => 'MFK']);

        // Gerçek dosyanın düzeni: oda sayfasında 1–31 gün kolonları + TOPLAM, malzeme sayfası ayrı
        $kitap = new Spreadsheet;
        $oda = $kitap->getActiveSheet()->setTitle('Ortak - Kamp');
        $gunler = range(1, 31);
        $oda->fromArray([
            ['TARİH', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 'KESİNTİ TÜRÜ', 'BÜTÇE', 'ODA TİPİ', ...$gunler, 'TOPLAM', 'BİRİM FİYAT', 'PARA BİRİMİ', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR', 'Açıklama / Not'],
            [46023, 'MFK', 'TAŞERON', 'KAMP', 'TÜM MAHALLER (ORTAK)', '6 KİŞİLİK ODA', ...array_fill(0, 31, 1), 17, 1200, 'TL', 20400, 0, 20400, 20400, 0, '-'],
        ], null, 'A5');
        $kitap->createSheet()->setTitle('Ortak - Kamp Malzeme')->fromArray([
            ['S.NO', 'TARİH', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 'KESİNTİ TÜRÜ', 'BÜTÇE', 'ÜRÜN TANIMI', 'MALZEME BİRİM FİYATI', 'MİKTAR', 'BİRİM', 'PARA BİRİMİ', 'KDV', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            [1, 46027, 'MFK', 'TAŞERON', 'KAMP MALZEME', 'TÜM MAHALLER (ORTAK)', 'BATTANİYE', 350, 4, 'ADET', 'TL', 0, 1400, 0, 1400, 0, 1400],
        ], null, 'A5');
        $yol = tempnam(sys_get_temp_dir(), 'kamp').'.xlsx';
        (new Xlsx($kitap))->save($yol);

        $this->post(self::ADRES.'/yuklemeler', ['dosya' => new UploadedFile($yol, 'Ortak - Kamp Kesintileri (2026).xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.satir_sayisi', 1);

        $kayit = KampKesintisi::query()->sole();
        $this->assertSame(['6 KİŞİLİK ODA', '17.00', '1200.0000', '2026-01-01'], [$kayit->oda_tipi, $kayit->toplam_oda, $kayit->birim_fiyat, $kayit->tarih->format('Y-m-d')]);
    }
}
