<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgCezaKesintisi;
use App\Models\KesintiTuru;
use App\Models\OsgbKesintisi;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\TetkikKesintisi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → OSGB, Tetkik ve İSG Ceza Kesintisi: kolon sıraları, elle
 * giriş, aynı OSGB dosyasından her ekrana yalnız kendi sayfasının aktarılması,
 * özet sekmeleri. Ortak akış İşgücü testlerinde, izinler EkranYetkisiTest'tedir.
 */
final class OsgbTetkikIsgCezaKesintisiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
        KesintiTuru::factory()->create(['kod' => 'OSGB', 'icmal' => 'osgb']);
        KesintiTuru::factory()->create(['kod' => 'TETKİK', 'icmal' => 'tetkik']);
        KesintiTuru::factory()->create(['kod' => 'İSG CEZA', 'icmal' => 'isg_ceza']);
        Proje::factory()->create(['kod' => 'ZONE 10']);
        Firma::factory()->create(['kod' => 'TMC KABA']);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function kolonSiralari(): array
    {
        $bas = ['kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'mahal', 'tarih', 'kaynak_firma'];
        $son = ['para_birimi', 'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar'];

        return [
            'OSGB' => ['osgb', [...$bas, 'toplam_kisi', 'birim_fiyat', ...$son]],
            'Tetkik' => ['tetkik', [...$bas, 'adi_soyadi', ...$son]],
            'İSG Ceza' => ['isg-ceza', [...$bas, ...$son]],
        ];
    }

    /**
     * @param  list<string>  $beklenen
     */
    #[DataProvider('kolonSiralari')]
    public function test_yapistirma_kolonlari_kullanicinin_sirasinda(string $yol, array $beklenen): void
    {
        $this->getJson("/api/v1/kesinti/{$yol}/yuklemeler/kolonlar")
            ->assertOk()
            ->assertJsonPath('data.*.alan', $beklenen);
    }

    public function test_elle_giris_ekrana_ozgu_alanlarla_kaydedilir(): void
    {
        $ortak = [
            'firma_id' => Firma::query()->value('id'),
            'yansitma_turu' => 'taseron',
            'proje_id' => Proje::query()->value('id'),
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-01-31',
            'toplam_tutar' => '1000',
            'iptal_edilen_tutar' => '0',
            'kesilen_tutar' => '600',
        ];
        $tur = fn (string $kod): int => (int) KesintiTuru::query()->where('kod', $kod)->value('id');

        $this->postJson('/api/v1/kesinti/osgb', [...$ortak, 'kesinti_turu_id' => $tur('OSGB'), 'toplam_kisi' => '132', 'birim_fiyat' => '120'])
            ->assertCreated()
            ->assertJsonPath('data.toplam_kisi', '132.00')
            ->assertJsonPath('data.birim_fiyat', '120.0000')
            ->assertJsonPath('data.kalan_tutar', '400.00');

        $this->postJson('/api/v1/kesinti/tetkik', [...$ortak, 'kesinti_turu_id' => $tur('TETKİK'), 'adi_soyadi' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('adi_soyadi', 'hatalar');
        $this->postJson('/api/v1/kesinti/tetkik', [...$ortak, 'kesinti_turu_id' => $tur('TETKİK'), 'adi_soyadi' => 'TUNCAY YEL'])
            ->assertCreated()
            ->assertJsonPath('data.adi_soyadi', 'TUNCAY YEL');

        $this->postJson('/api/v1/kesinti/isg-ceza', [...$ortak, 'kesinti_turu_id' => $tur('İSG CEZA')])
            ->assertCreated()
            ->assertJsonMissingPath('data.birim_fiyat');
    }

    /**
     * Gerçek OSGB dosyasının düzeni (yansıtma kolonu eklenmiş): OSGB, Sağlık
     * Tetkikleri ve İSG Ceza sayfaları aynı dosyada.
     */
    private static function osgbDosyasi(): string
    {
        $kitap = new Spreadsheet;
        $kitap->getActiveSheet()->setTitle('Z10-OSGB')->fromArray([
            ['TARİH', 'BÜTÇE', 'KESİNTİ TÜRÜ', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 1, 2, 'İLAVE', 'TOPLAM', 'BİRİM FİYAT', 'PARA BİRİMİ', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            [46023, 'ZONE 10', 'OSGB', 'TMC KABA', 'TAŞERON', 43, 43, 0, 411, 120, 'TL', 49320, 12330, 36990, 36990, 0],
        ], null, 'A5');
        $kitap->createSheet()->setTitle('Z10-Sağlık Tetkikleri')->fromArray([
            ['NO', 'HK. NO', 'TARİH', 'ADI SOYADI', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 'KESİNTİ TÜRÜ', 'BÜTÇE', 'PARA BİRİMİ', 'Hemog.', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            [1, 1, 46053, 'TUNCAY YEL', 'TMC KABA', 'TAŞERON', 'TETKİK', 'ZONE 10', 'TL', 1, 830, 0, 830, 830, 0],
            [1, 1, 46053, 'MÖHSİN UD', 'TMC KABA', 'TAŞERON', 'TETKİK', 'ZONE 10', 'TL', 1, 830, 0, 830, 830, 0],
        ], null, 'A7');
        $kitap->createSheet()->setTitle('Z10-İSG Ceza')->fromArray([
            ['BÜTÇE', 'KESİNTİ TÜRÜ', 'PARA BİRİMİ', 'UYGUNSUZLUK TİPİ', 'UYGUNSUZLUK TESPİT YERİ', 'TARİH', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 'TUTAR', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            ['ZONE 10', 'İSG CEZA', 'TL', 'Tehlikeli Davranış', 'ZONE 10', 46037, 'TMC KABA', 'TAŞERON', 'MADDE 112', 5000, 0, 5000, 5000, 0],
        ], null, 'A5');
        $yol = tempnam(sys_get_temp_dir(), 'osgb').'.xlsx';
        (new Xlsx($kitap))->save($yol);

        return $yol;
    }

    public function test_ayni_osgb_dosyasi_her_ekrana_yalniz_kendi_sayfasiyla_aktarilir(): void
    {
        $yol = self::osgbDosyasi();
        $yukle = fn (string $ekran) => $this->post("/api/v1/kesinti/{$ekran}/yuklemeler", ['dosya' => new UploadedFile($yol, 'Z10 - OSGB Kesintileri.xlsx', null, null, true)], ['Accept' => 'application/json']);

        $yukle('osgb')->assertCreated()->assertJsonPath('data.satir_sayisi', 1);
        $yukle('tetkik')->assertCreated()->assertJsonPath('data.satir_sayisi', 2);
        // İSG Ceza ortak kolonları Tetkik sayfasında da buluyor; kesinti türü bu ekrana bağlı olmayan sayfa okunmaz
        $yukle('isg-ceza')->assertCreated()->assertJsonPath('data.satir_sayisi', 1);

        $this->assertDatabaseHas('osgb_kesintileri', ['toplam_kisi' => '411.00', 'birim_fiyat' => '120.0000', 'kesilen_tutar' => '36990.00']);
        $this->assertSame(['MÖHSİN UD', 'TUNCAY YEL'], TetkikKesintisi::query()->orderBy('adi_soyadi')->pluck('adi_soyadi')->all());
        $this->assertDatabaseHas('isg_ceza_kesintileri', ['mahal' => 'ZONE 10', 'toplam_tutar' => '5000.00']);
    }

    public function test_ozet_sekmeleri_ve_excel_sayfalari_osgb_toplam_kisiyi_toplar(): void
    {
        OsgbKesintisi::factory()->create(['toplam_kisi' => '132.00']);
        OsgbKesintisi::factory()->create(['toplam_kisi' => '411.00']);
        IsgCezaKesintisi::factory()->create();

        $this->getJson('/api/v1/kesinti/osgb?page=1')
            ->assertOk()
            ->assertJsonPath('ozet.0.toplam_kisi', '543.00');

        foreach (['osgb', 'tetkik', 'isg-ceza'] as $ekran) {
            $this->getJson("/api/v1/kesinti/{$ekran}/pivot/tarih")->assertOk();

            $yanit = $this->get("/api/v1/kesinti/{$ekran}/excel")->assertOk();
            $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
            $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'], $kitap->getSheetNames(), $ekran);
        }
    }
}
