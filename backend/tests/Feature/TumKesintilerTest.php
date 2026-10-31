<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Models\MalzemeKesintisi;
use App\Models\Proje;
use App\Models\User;
use App\Models\YemekKesintisi;
use App\Services\Kesinti\TumKesintilerSorgusu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Tüm Kesintiler: bütün icmal ekranlarının satırları
 * ortak kolonlarla tek listede; filtreler, özet sekmeleri, Excel. Yalnız rapor.
 */
final class TumKesintilerTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/tumu';

    private KesintiTuru $isgucu;

    private KesintiTuru $yemek;

    private KesintiTuru $malzeme;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->yonetici()->create());
        $tmc = Firma::factory()->create(['kod' => 'TMC KABA']);
        $hab = Firma::factory()->create(['kod' => 'HAB']);
        $zone = Proje::factory()->create(['kod' => 'ZONE 10']);
        $this->isgucu = KesintiTuru::factory()->create(['kod' => 'İŞGÜCÜ', 'icmal' => 'isgucu']);
        $this->yemek = KesintiTuru::factory()->create(['kod' => 'YEMEK', 'icmal' => 'yemek']);
        $this->malzeme = KesintiTuru::factory()->create(['kod' => 'MALZEME KESİNTİSİ', 'icmal' => 'malzeme']);

        // Üç ayrı tablo: id yalnız kendi tablosunda benzersiz
        IsgucuKesintisi::factory()->create(['kesinti_turu_id' => $this->isgucu->id, 'firma_id' => $tmc->id, 'proje_id' => $zone->id, 'tarih' => '2026-03-01', 'toplam_tutar' => '1000.00', 'kaynak_firma' => 'MFK']);
        YemekKesintisi::factory()->create(['kesinti_turu_id' => $this->yemek->id, 'firma_id' => $hab->id, 'proje_id' => $zone->id, 'tarih' => '2026-02-01', 'toplam_tutar' => '500.00']);
        MalzemeKesintisi::factory()->create(['kesinti_turu_id' => $this->malzeme->id, 'firma_id' => $tmc->id, 'proje_id' => $zone->id, 'tarih' => '2026-01-01', 'toplam_tutar' => '250.00']);
    }

    public function test_her_icmal_ekrani_tum_kesintilere_girer(): void
    {
        $this->assertEqualsCanonicalizing(KesintiYuklemesi::ICMALLER, array_keys(TumKesintilerSorgusu::KAYNAKLAR));
    }

    public function test_butun_icmallerin_satirlari_ortak_kolonlarla_tek_listede(): void
    {
        $this->getJson(self::ADRES)
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            // Varsayılan sıra tarih, yeniden eskiye
            ->assertJsonPath('data.*.icmal', ['isgucu', 'yemek', 'malzeme'])
            ->assertJsonPath('data.0.kesinti_turu.kod', 'İŞGÜCÜ')
            ->assertJsonPath('data.0.kaynak_firma', 'MFK')
            ->assertJsonMissingPath('data.0.adi_soyadi')
            ->assertJsonPath('ozet.0.satir_sayisi', 3)
            ->assertJsonPath('ozet.0.toplam_tutar', '1750.00');
    }

    public function test_kesinti_turleri_coklu_taseron_ve_tarih_tekli_filtrelenir(): void
    {
        $this->getJson(self::ADRES.'?'.http_build_query(['kesinti_turu_id' => [$this->isgucu->id, $this->malzeme->id]]))
            ->assertOk()
            ->assertJsonPath('data.*.icmal', ['isgucu', 'malzeme']);

        $this->getJson(self::ADRES.'?'.http_build_query(['firma_id' => Firma::query()->where('kod', 'TMC KABA')->value('id'), 'baslangic' => '2026-02-01']))
            ->assertOk()
            ->assertJsonPath('data.*.icmal', ['isgucu']);
    }

    public function test_filtre_secimleri_butun_icmallerin_kayitlarindan(): void
    {
        Firma::factory()->create(['kod' => 'KULLANILMAYAN']);

        $yanit = $this->getJson(self::ADRES.'/filtre-secenekleri')
            ->assertOk()
            ->assertJsonPath('data.firmalar.*.kod', ['HAB', 'TMC KABA'])
            ->assertJsonPath('data.projeler.*.kod', ['ZONE 10']);
        // "İ" ile başlayan kodun sırası veritabanının harf sıralamasına bağlı
        $this->assertEqualsCanonicalizing(['İŞGÜCÜ', 'MALZEME KESİNTİSİ', 'YEMEK'], $yanit->json('data.kesinti_turleri.*.kod'));
    }

    public function test_ozet_sekmeleri_ve_excel_butun_icmalleri_toplar(): void
    {
        $this->getJson(self::ADRES.'/pivot/taseron')
            ->assertOk()
            ->assertJsonPath('data.*.taseron', ['HAB', 'TMC KABA'])
            ->assertJsonPath('data.1.toplam_tutar', '1250.00');

        $yanit = $this->get(self::ADRES.'/excel')->assertOk();
        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Taşeron', 'Yansıtma Türü', 'Proje', 'Tarih'], $kitap->getSheetNames());

        $data = $kitap->getSheet(0)->toArray(null, false, false);
        $this->assertSame([
            'SIRA NO', 'KESİNTİ TÜRÜ', 'TAŞERON ADI', 'YANSITMA TÜRÜ', 'PROJE', 'MAHAL', 'TARİH', 'YIL', 'AY', 'KAYNAK FİRMA',
            'PARA BİRİMİ', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR',
        ], $data[0]);
        $this->assertSame([1, 'İŞGÜCÜ'], [(int) $data[1][0], $data[1][1]]);
        $this->assertSame('Toplam Kayıt: 3', $data[4][0]);
    }

    public function test_izinsiz_kullanici_goremez_ve_rapora_veri_girilmez(): void
    {
        $this->postJson(self::ADRES, [])->assertMethodNotAllowed();

        $this->actingAs(User::factory()->create())
            ->getJson(self::ADRES)
            ->assertForbidden();
    }
}
