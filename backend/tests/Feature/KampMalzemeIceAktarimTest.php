<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KampMalzemeKesintisi;
use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Models\Proje;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Kamp Malzeme Kesintileri içe aktarımı: ortak akış (IcmalIceAktarimi)
 * IsgucuIceAktarimTest'te; burada bu icmalin kolonları, çok sayfalı Kamp
 * dosyasından doğru sayfanın seçilmesi ve partilerin icmale göre ayrılması.
 */
final class KampMalzemeIceAktarimTest extends TestCase
{
    use RefreshDatabase;

    private const KAYDET = '/api/v1/kesinti/kamp-malzeme/yuklemeler';

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
        KesintiTuru::factory()->create(['kod' => 'KAMP MALZEME']);
        Proje::factory()->create(['kod' => 'TÜM MAHALLER (ORTAK)']);
        Firma::factory()->create(['kod' => 'HAB']);
        Firma::factory()->create(['kod' => 'MFK']);
    }

    /**
     * Gerçek Kamp dosyasının düzeni: oda sayfası (ürün/miktar kolonu yok) ve
     * malzeme sayfası; başlıklar 5. satırda, üstte ara toplam satırı.
     */
    private static function kampDosyasi(): string
    {
        $kitap = new Spreadsheet;
        $oda = $kitap->getActiveSheet()->setTitle('Ortak - Kamp');
        $oda->fromArray([
            ['TARİH', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 'KESİNTİ TÜRÜ', 'BÜTÇE', 'ODA TİPİ', 'TOPLAM', 'BİRİM FİYAT', 'PARA BİRİMİ', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            [46023, 'MFK', 'TAŞERON', 'KAMP', 'TÜM MAHALLER (ORTAK)', '6 KİŞİLİK ODA', 17, 1200, 'TL', 20400, 0, 20400, 20400, 0],
        ], null, 'A5');

        $malzeme = $kitap->createSheet()->setTitle('Ortak - Kamp Malzeme');
        $malzeme->fromArray([['', '', '', '', '', '', '', '', '', '', '', '', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR']], null, 'A2');
        $malzeme->fromArray([
            ['S.NO', 'TARİH', 'KESİNTİ YAPILACAK FİRMA', 'YANSITMA', 'KESİNTİ TÜRÜ', 'BÜTÇE', 'ÜRÜN TANIMI', 'MALZEME BİRİM FİYATI', 'MİKTAR', 'BİRİM', 'PARA BİRİMİ', 'KDV', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR', 'Açıklama / Not'],
            [1, 46027, 'HAB', 'HAB', 'KAMP MALZEME', 'TÜM MAHALLER (ORTAK)', 'BATTANİYE', 350, 4, 'ADET', 'TL', 0, 1400, 1400, 0, 0, 0, '*Sözleşmelerine göre kamp kesintisi yapılmayacaktır.'],
            [2, 46030, 'MFK', 'TAŞERON', 'KAMP MALZEME', 'TÜM MAHALLER (ORTAK)', 'YASTIK', 125.5, 2, 'ADET', 'TL', 0, 251, 0, 251, 200, 51, '-'],
        ], null, 'A5');

        $yol = tempnam(sys_get_temp_dir(), 'kamp').'.xlsx';
        (new Xlsx($kitap))->save($yol);

        return $yol;
    }

    public function test_kamp_dosyasindan_yalniz_malzeme_sayfasi_bugunku_basliklarla_aktarilir(): void
    {
        $this->post(self::KAYDET, ['dosya' => new UploadedFile(self::kampDosyasi(), 'Ortak - Kamp Kesintileri (2026).xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.satir_sayisi', 2);

        $this->assertSame(2, KampMalzemeKesintisi::query()->count());
        $this->assertDatabaseHas('kamp_malzeme_kesintileri', [
            'urun_tanimi' => 'YASTIK', 'tarih' => '2026-01-08', 'yansitma_turu' => 'taseron', 'birim_fiyat' => '125.5000',
            'miktar' => '2.00', 'birim' => 'ADET', 'kesilen_tutar' => '200.00', 'kalan_tutar' => '51.00',
        ]);
        $this->assertSame(KesintiYuklemesi::ICMAL_KAMP_MALZEME, KesintiYuklemesi::query()->sole()->icmal);
    }

    public function test_yapistirma_kolonlari_yansitma_turu_tasaronun_hemen_saginda_ve_kaynak_fis_no_yok(): void
    {
        $alanlar = $this->getJson(self::KAYDET.'/kolonlar')->assertOk()->json('data.*.alan');

        $this->assertSame(['kesinti_turu', 'taseron', 'yansitma_turu', 'proje'], array_slice($alanlar, 0, 4));
        $this->assertNotContains('kaynak_fis_no', $alanlar);
    }

    public function test_basliksiz_yapistirmada_birim_siniri_ve_zorunlu_urun_denetlenir(): void
    {
        $satir = fn (string $urun, string $birim): string => implode("\t", [
            'KAMP MALZEME', 'HAB', 'HAB', 'TÜM MAHALLER (ORTAK)', '', '07.01.2026', '', $urun,
            '350', '4', $birim, 'TL', '1.400,00', '1.400,00', '0', '0', '0',
        ]);

        $yanit = $this->postJson(self::KAYDET.'/onizle', ['metin' => implode("\n", [$satir('', 'ADET'), $satir('BATTANİYE', str_repeat('A', 21))])])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            ['urun_tanimi:zorunlu:1', 'birim:cok_uzun:2'],
            array_map(fn (array $h): string => "{$h['alan']}:{$h['kod']}:".implode(',', $h['satirlar']), $yanit->json('data.hatalar')),
        );
    }

    public function test_baska_icmalin_partisi_bu_ekrandan_listelenmez_ve_geri_alinamaz(): void
    {
        $isgucu = KesintiYuklemesi::query()->create(['icmal' => KesintiYuklemesi::ICMAL_ISGUCU, 'kaynak' => 'excel', 'satir_sayisi' => 1]);
        IsgucuKesintisi::factory()->create(['yukleme_id' => $isgucu->id]);

        $this->getJson(self::KAYDET)->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson(self::KAYDET."/{$isgucu->id}")->assertNotFound();

        $this->assertModelExists($isgucu);
    }
}
