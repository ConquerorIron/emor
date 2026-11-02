<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\KartBasimKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Personel Kart Basım Kesintisi: kolon sırası, elle giriş,
 * yapıştırma aktarımı, özet sekmeleri. Ortak akış İşgücü testlerinde,
 * izinler EkranYetkisiTest'tedir.
 */
final class KartBasimKesintisiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/kart-basim';

    private const KOLONLAR = [
        'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'mahal', 'tarih', 'kaynak_firma',
        'personel_adi_soyadi', 'urun_tanimi', 'kart_basim_nedeni', 'malzeme_birim_fiyati', 'miktar', 'birim',
        'para_birimi', 'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
        KesintiTuru::factory()->create(['kod' => 'KART BASIM', 'icmal' => 'kart_basim']);
        Proje::factory()->create(['kod' => 'ZONE 10']);
        Firma::factory()->create(['kod' => 'TMC KABA']);
    }

    public function test_yapistirma_kolonlari_kullanicinin_sirasinda(): void
    {
        $this->getJson(self::ADRES.'/yuklemeler/kolonlar')
            ->assertOk()
            ->assertJsonPath('data.*.alan', self::KOLONLAR);
    }

    public function test_elle_giris_kart_basim_alanlariyla_kaydedilir(): void
    {
        $govde = [
            'kesinti_turu_id' => KesintiTuru::query()->value('id'),
            'firma_id' => Firma::query()->value('id'),
            'yansitma_turu' => 'taseron',
            'proje_id' => Proje::query()->value('id'),
            'para_birimi_id' => ParaBirimi::query()->where('kod', 'TL')->value('id'),
            'tarih' => '2026-04-15',
            'personel_adi_soyadi' => 'TUNCAY YEL',
            'urun_tanimi' => 'PERSONEL GİRİŞ KARTI',
            'kart_basim_nedeni' => 'KAYIP',
            'malzeme_birim_fiyati' => '150',
            'miktar' => '2',
            'birim' => 'ADET',
            'toplam_tutar' => '300',
            'iptal_edilen_tutar' => '0',
            'kesilen_tutar' => '200',
        ];

        $this->postJson(self::ADRES, [...$govde, 'personel_adi_soyadi' => '', 'kart_basim_nedeni' => str_repeat('A', 101)])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('personel_adi_soyadi', 'hatalar')
            ->assertJsonValidationErrorFor('kart_basim_nedeni', 'hatalar');

        $this->postJson(self::ADRES, $govde)
            ->assertCreated()
            ->assertJsonPath('data.personel_adi_soyadi', 'TUNCAY YEL')
            ->assertJsonPath('data.kart_basim_nedeni', 'KAYIP')
            ->assertJsonPath('data.malzeme_birim_fiyati', '150.0000')
            ->assertJsonPath('data.miktar', '2.00')
            ->assertJsonPath('data.kalan_tutar', '100.00');
    }

    public function test_yapistirilan_satirlar_aktarilir_kart_basim_nedeni_bos_olabilir(): void
    {
        $basliklar = ['KESİNTİ TÜRÜ', 'TAŞERON ADI', 'YANSITMA TÜRÜ', 'PROJE', 'MAHAL', 'TARİH', 'KAYNAK FİRMA', 'PERSONEL ADI SOYADI', 'ÜRÜN TANIMI', 'KART BASIM NEDENİ', 'MALZEME BİRİM FİYATI', 'MİKTAR', 'BİRİM', 'PARA BİRİMİ', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'];
        $satirlar = [
            ['KART BASIM', 'TMC KABA', 'TAŞERON', 'ZONE 10', 'ZONE 10', '15.04.2026', 'GÜVENLİK', 'TUNCAY YEL', 'PERSONEL GİRİŞ KARTI', 'KAYIP', '150', '1', 'ADET', 'TL', '150', '0', '150', '150', '0'],
            ['KART BASIM', 'TMC KABA', 'TAŞERON', 'ZONE 10', 'ZONE 10', '16.04.2026', 'GÜVENLİK', 'MÖHSİN UD', 'PERSONEL GİRİŞ KARTI', '', '150', '1', 'ADET', 'TL', '150', '0', '150', '100', '50'],
        ];
        $metin = implode("\n", array_map(fn (array $satir): string => implode("\t", $satir), [$basliklar, ...$satirlar]));

        $this->postJson(self::ADRES.'/yuklemeler', ['metin' => $metin])
            ->assertCreated()
            ->assertJsonPath('data.satir_sayisi', 2);

        $this->assertDatabaseHas('kart_basim_kesintileri', [
            'personel_adi_soyadi' => 'MÖHSİN UD',
            'urun_tanimi' => 'PERSONEL GİRİŞ KARTI',
            'kart_basim_nedeni' => null,
            'malzeme_birim_fiyati' => '150.0000',
            'kalan_tutar' => '50.00',
            'tarih' => '2026-04-16',
        ]);
    }

    public function test_ozet_sekmeleri_ve_excel_sayfalari(): void
    {
        KartBasimKesintisi::factory()->count(2)->create(['proje_id' => Proje::query()->value('id')]);

        $this->getJson(self::ADRES.'/pivot/tarih')
            ->assertOk()
            ->assertJsonPath('data.0.toplam_tutar', '300.00');

        $yanit = $this->get(self::ADRES.'/excel')->assertOk();
        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'], $kitap->getSheetNames());
        // SIRA NO'dan sonra kullanıcının sırası (Tarih'in sağında Yıl ve Ay)
        $this->assertSame('PERSONEL ADI SOYADI', $kitap->getSheet(0)->getCell('K1')->getValue());
        $this->assertSame('KART BASIM NEDENİ', $kitap->getSheet(0)->getCell('M1')->getValue());
    }
}
