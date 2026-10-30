<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\MakinaEkipmanKesintisi;
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
 * Kesinti Yönetimi → Makina Ekipman Kesintileri: bu icmalin kolonları, elle
 * giriş kuralları ve bugünkü Excel başlıklarıyla aktarım. Ortak akış İşgücü
 * testlerinde, izin matrisi EkranYetkisiTest'tedir.
 */
final class MakinaEkipmanKesintisiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/makina-ekipman';

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
            'kesinti_turu_id' => KesintiTuru::factory()->create(['kod' => 'MAKİNA-EKİPMAN', 'icmal' => 'makina_ekipman'])->id,
            'firma_id' => Firma::factory()->create(['kod' => 'AKTAŞ HAFRİYAT'])->id,
            'yansitma_turu' => 'taseron',
            'proje_id' => Proje::factory()->create(['kod' => 'ZONE 10'])->id,
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-01-01',
            'kaynak_firma' => 'MFK',
            'kaynak_fis_no' => '11900',
            'makina_tanimi' => '30 TON EKSKAVATÖR KOVALI',
            'aktivite' => 'ESKİ KAMP ALANI KADEME İNDİRME',
            'disiplin' => 'HAFRİYAT AKTARMA',
            'toplam_tutar' => '15625.27',
            'iptal_edilen_tutar' => '0',
            'kesilen_tutar' => '15389.32',
        ];
    }

    public function test_elle_girilen_satir_makinaya_ozgu_alanlarla_kaydedilir_birim_fiyat_yok(): void
    {
        $this->postJson(self::ADRES, $this->gecerliGovde())
            ->assertCreated()
            ->assertJsonPath('data.makina_tanimi', '30 TON EKSKAVATÖR KOVALI')
            ->assertJsonPath('data.disiplin', 'HAFRİYAT AKTARMA')
            ->assertJsonPath('data.kaynak_fis_no', '11900')
            ->assertJsonPath('data.kalan_tutar', '235.95')
            ->assertJsonMissingPath('data.birim_fiyat');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function gecersizAlanlar(): array
    {
        return [
            'makina tanımı boş' => [['makina_tanimi' => ''], 'makina_tanimi'],
            'makina tanımı 200 karakteri aşıyor' => [['makina_tanimi' => str_repeat('A', 201)], 'makina_tanimi'],
            'disiplin 100 karakteri aşıyor' => [['disiplin' => str_repeat('A', 101)], 'disiplin'],
            'kaynak fiş no 100 karakteri aşıyor' => [['kaynak_fis_no' => str_repeat('1', 101)], 'kaynak_fis_no'],
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

        $this->assertDatabaseCount('makina_ekipman_kesintileri', 0);
    }

    public function test_ozet_sekmeleri_makina_ve_disipline_gore_gruplar_excel_ayni_sayfalari_verir(): void
    {
        $proje = Proje::factory()->create(['kod' => 'ZONE 10']);
        $ortak = ['proje_id' => $proje->id, 'iptal_edilen_tutar' => '0.00', 'yansitilan_tutar' => '0.00', 'kesilen_tutar' => '0.00', 'kalan_tutar' => '0.00'];
        MakinaEkipmanKesintisi::factory()->create([...$ortak, 'makina_tanimi' => 'JCB', 'disiplin' => 'HAFRİYAT', 'toplam_tutar' => '100.00']);
        MakinaEkipmanKesintisi::factory()->create([...$ortak, 'makina_tanimi' => 'JCB', 'disiplin' => null, 'toplam_tutar' => '50.00']);
        MakinaEkipmanKesintisi::factory()->create([...$ortak, 'makina_tanimi' => 'VİNÇ', 'disiplin' => 'HAFRİYAT', 'toplam_tutar' => '10.00']);

        $this->getJson(self::ADRES.'/pivot/makina')
            ->assertOk()
            ->assertJsonPath('data.*.makina_tanimi', ['JCB', 'VİNÇ'])
            ->assertJsonPath('data.0.toplam_tutar', '150.00')
            // Yalnız 5 tutar toplanır
            ->assertJsonMissingPath('data.0.miktar_saat');
        // Disiplini boş satırlar kendi grubunda
        $this->getJson(self::ADRES.'/pivot/disiplin')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $yanit = $this->get(self::ADRES.'/excel')->assertOk();
        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Makina', 'Disiplin', 'Tarih'], $kitap->getSheetNames());
        $this->assertSame('MAKİNA TANIMI', $kitap->getSheetByName('Makina')?->getCell('A1')->getValue());
    }

    public function test_yapistirma_kolonlari_kullanicinin_sirasinda(): void
    {
        $this->getJson(self::ADRES.'/yuklemeler/kolonlar')
            ->assertOk()
            ->assertJsonPath('data.*.alan', [
                'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'mahal', 'tarih', 'kaynak_firma',
                'kaynak_fis_no', 'makina_tanimi', 'aktivite', 'disiplin', 'para_birimi',
                'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
            ]);
    }

    public function test_bugunku_excel_basliklariyla_aktarilir(): void
    {
        KesintiTuru::factory()->create(['kod' => 'MAKİNA-EKİPMAN', 'icmal' => 'makina_ekipman']);
        Proje::factory()->create(['kod' => 'ZONE 10']);
        Firma::factory()->create(['kod' => 'AKTAŞ HAFRİYAT']);

        // Gerçek dosyanın adları (KİRALANAN FİRMA, FİŞ NUMARASI, BÜTÇE…); yalnız YANSITMA eklenmiş
        $kitap = new Spreadsheet;
        $kitap->getActiveSheet()->fromArray([
            ['KİRALANAN FİRMA', 'TARİH', 'HAKEDİŞ NO', 'FİŞ NUMARASI', 'MAKİNA TANIMI', 'BÜTÇE', 'MAHAL', 'AKTİVİTE', 'DİSİPLİN', 'KESİNTİ TÜRÜ', 'ÇALIŞILAN FİRMA', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 'AYLIK BİRİM FİYAT', 'PARA BİRİMİ', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            ['MFK', 46023, '1-ARA', 11900, '30 TON EKSKAVATÖR KOVALI', 'ZONE 10', 'ZONE 10', 'HAFRİYAT', 'HAFRİYAT AKTARMA', 'MAKİNA-EKİPMAN', 'HAB', 'AKTAŞ HAFRİYAT', 'TAŞERON', 468758.246, 'TL', 15625.274866667, 0, 15625.274866667, 15389.324333333, 235.950533334],
        ], null, 'A5');
        $yol = tempnam(sys_get_temp_dir(), 'makina').'.xlsx';
        (new Xlsx($kitap))->save($yol);

        $this->post(self::ADRES.'/yuklemeler', ['dosya' => new UploadedFile($yol, 'Z10 - Makine-Ekipman.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.satir_sayisi', 1);

        $this->assertDatabaseHas('makina_ekipman_kesintileri', [
            'kaynak_firma' => 'MFK', 'kaynak_fis_no' => '11900', 'makina_tanimi' => '30 TON EKSKAVATÖR KOVALI',
            'disiplin' => 'HAFRİYAT AKTARMA', 'yansitma_turu' => 'taseron', 'toplam_tutar' => '15625.27', 'kalan_tutar' => '235.95',
        ]);
        $this->assertSame(1, MakinaEkipmanKesintisi::query()->count());
    }
}
