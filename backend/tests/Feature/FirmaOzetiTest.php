<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\Proje;
use App\Models\Rol;
use App\Models\User;
use App\Models\YemekKesintisi;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Firma Özeti: taşeron + proje başına kesinti türlerinin
 * kalanı yan yana (pivot) ve toplam kalan; yalnız taşerona yansıtılanlardan.
 */
final class FirmaOzetiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/firma-ozeti';

    private Firma $zetas;

    private function kullanici(string $izin = 'firma_ozeti.goruntule'): User
    {
        $kullanici = User::factory()->create();
        $rol = Rol::query()->create(['ad' => 'Firma Raporcu']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => $izin]);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /**
     * Kolonlar: İŞGÜCÜ, KAMP (verisiz), YEMEK ekran sırasıyla, ekrana bağsız ESKİ sonda.
     * Kalanlar (TL, 30.09.2026'ya kadar, taşerona yansıtılan): ZETAŞ / ZONE 10 →
     * işgücü 40 + yemek 100 + 60; MFK / ZONE 6 → işgücü 100; MFK / ZONE 10 →
     * yemek 0 (tamamı kesilmiş). Dışarıda: HAB'ın üstlendikleri, Ekim'deki satır.
     */
    private function ornekVeri(): void
    {
        $this->zetas = Firma::factory()->create(['kod' => 'ZETAŞ', 'ad' => 'ZETAŞ ZEMİN TEKNOLOJİSİ']);
        $mfk = Firma::factory()->create(['kod' => 'MFK', 'ad' => 'MFK']);
        $hab = Firma::factory()->create(['kod' => 'HAB', 'firmamiz' => true]);
        $zone6 = Proje::factory()->create(['kod' => 'ZONE 6']);
        $zone10 = Proje::factory()->create(['kod' => 'ZONE 10']);
        KesintiTuru::factory()->create(['kod' => 'ESKİ', 'icmal' => null]);
        $yemek = KesintiTuru::factory()->create(['kod' => 'YEMEK', 'icmal' => 'yemek']);
        KesintiTuru::factory()->create(['kod' => 'KAMP', 'icmal' => 'kamp']);
        $isgucu = KesintiTuru::factory()->create(['kod' => 'İŞGÜCÜ', 'icmal' => 'isgucu']);

        $satir = function (string $model, KesintiTuru $tur, Firma $firma, Proje $proje, YansitmaTuru $yansitma, string $kalan, string $tarih = '2026-09-10'): Model {
            /** @var class-string<YemekKesintisi|IsgucuKesintisi> $model */
            return $model::factory()->create([
                'kesinti_turu_id' => $tur->id, 'firma_id' => $firma->id, 'proje_id' => $proje->id,
                'yansitma_turu' => $yansitma, 'tarih' => $tarih,
                'toplam_tutar' => '500.00', 'iptal_edilen_tutar' => '0.00', 'yansitilan_tutar' => '500.00',
                'kesilen_tutar' => number_format(500 - (float) $kalan, 2, '.', ''), 'kalan_tutar' => $kalan,
            ]);
        };

        $satir(IsgucuKesintisi::class, $isgucu, $this->zetas, $zone10, YansitmaTuru::Taseron, '40.00');
        $satir(YemekKesintisi::class, $yemek, $this->zetas, $zone10, YansitmaTuru::Taseron, '100.00');
        $satir(YemekKesintisi::class, $yemek, $this->zetas, $zone10, YansitmaTuru::Taseron, '60.00');
        $satir(IsgucuKesintisi::class, $isgucu, $mfk, $zone6, YansitmaTuru::Taseron, '100.00');
        // Dışarıda kalanlar
        $satir(YemekKesintisi::class, $yemek, $mfk, $zone6, YansitmaTuru::Hab, '500.00');
        $satir(YemekKesintisi::class, $yemek, $hab, $zone6, YansitmaTuru::Hab, '200.00');
        $satir(YemekKesintisi::class, $yemek, $mfk, $zone10, YansitmaTuru::Taseron, '0.00');
        $satir(YemekKesintisi::class, $yemek, $this->zetas, $zone10, YansitmaTuru::Taseron, '999.00', '2026-10-01');
    }

    /**
     * @param  array<string, mixed>  $ek
     */
    private function adres(array $ek = [], string $uc = ''): string
    {
        $tl = (int) DB::table('para_birimleri')->where('kod', 'TL')->value('id');

        return self::ADRES.$uc.'?'.http_build_query(['tarih' => '2026-09-30', 'para_birimi_id' => $tl, ...$ek]);
    }

    public function test_taseron_ve_proje_basina_kesinti_turlerinin_kalani_yan_yana_toplamla(): void
    {
        $this->ornekVeri();

        $this->actingAs($this->kullanici())
            ->getJson($this->adres())
            ->assertOk()
            ->assertJsonPath('data.para_birimi', 'TL')
            ->assertJsonPath('data.kesinti_turleri.*.kod', ['İŞGÜCÜ', 'KAMP', 'YEMEK', 'ESKİ'])
            // Taşeron, sonra proje sırasıyla; kalanı sıfır olan MFK / ZONE 10 da listelenir
            ->assertJsonPath('data.satirlar.*.taseron', ['MFK', 'MFK', 'ZETAŞ'])
            ->assertJsonPath('data.satirlar.*.proje', ['ZONE 6', 'ZONE 10', 'ZONE 10'])
            ->assertJsonPath('data.satirlar.0.kalanlar', ['100.00', '0.00', '0.00', '0.00'])
            ->assertJsonPath('data.satirlar.0.toplam_kalan', '100.00')
            // Kayıt sayısı: yemek kesintisi var ama tamamı kesilmiş (ekranda 0,00), diğer türler yok ("-")
            ->assertJsonPath('data.satirlar.1.kalanlar', ['0.00', '0.00', '0.00', '0.00'])
            ->assertJsonPath('data.satirlar.1.kayit_sayilari', [0, 0, 1, 0])
            ->assertJsonPath('data.satirlar.2.taseron_ad', 'ZETAŞ ZEMİN TEKNOLOJİSİ')
            ->assertJsonPath('data.satirlar.2.kalanlar', ['40.00', '0.00', '160.00', '0.00'])
            ->assertJsonPath('data.satirlar.2.kayit_sayilari', [1, 0, 2, 0])
            ->assertJsonPath('data.satirlar.2.toplam_kalan', '200.00')
            ->assertJsonPath('data.toplam', [
                'kalanlar' => ['140.00', '0.00', '160.00', '0.00'], 'kayit_sayilari' => [2, 0, 3, 0], 'toplam_kalan' => '300.00',
            ]);
    }

    public function test_secilen_firmayla_suzulur(): void
    {
        $this->ornekVeri();

        $this->actingAs($this->kullanici())
            ->getJson($this->adres(['firma_id' => [$this->zetas->id]]))
            ->assertOk()
            ->assertJsonPath('data.satirlar.*.taseron', ['ZETAŞ'])
            ->assertJsonPath('data.toplam.toplam_kalan', '200.00');
    }

    public function test_excel_tabloyu_toplam_satiriyla_indirir(): void
    {
        $this->ornekVeri();

        $yanit = $this->actingAs($this->kullanici())
            ->get($this->adres(uc: '/excel'))
            ->assertOk();
        $this->assertStringContainsString('firma-ozeti.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Firma Özeti'], $kitap->getSheetNames());
        $sayfa = $kitap->getActiveSheet();
        $satir = fn (int $no): array => array_map(
            fn (string $kolon): string => (string) $sayfa->getCell($kolon.$no)->getFormattedValue(),
            range('A', 'H'),
        );
        $this->assertSame('Firma Özeti — 30.09.2026 itibarıyla kalan kesintiler (TL)', $sayfa->getCell('A1')->getValue());
        $this->assertSame(['Taşeron', 'Taşeron Adı', 'Proje', 'İŞGÜCÜ', 'KAMP', 'YEMEK', 'ESKİ', 'Toplam Kalan'], $satir(3));
        // Kesintisi olmayan tür boş, kesintisi olup kalanı sıfır olan 0.00
        $this->assertSame(['MFK', 'MFK', 'ZONE 6', '100.00', '', '', '', '100.00'], $satir(4));
        $this->assertSame(['MFK', 'MFK', 'ZONE 10', '', '', '0.00', '', '0.00'], $satir(5));
        $this->assertSame(['ZETAŞ', 'ZETAŞ ZEMİN TEKNOLOJİSİ', 'ZONE 10', '40.00', '', '160.00', '', '200.00'], $satir(6));
        $this->assertSame(['TOPLAM', '', '', '140.00', '', '160.00', '', '300.00'], $satir(7));
    }

    public function test_parametre_secenekleri_kesintide_gecenlerden(): void
    {
        $this->ornekVeri();

        $this->actingAs($this->kullanici())
            ->getJson(self::ADRES.'/secenekler')
            ->assertOk()
            ->assertJsonPath('data.firmalar.*.kod', ['HAB', 'MFK', 'ZETAŞ'])
            ->assertJsonPath('data.para_birimleri.*.kod', ['TL']);
    }

    public function test_kesinti_ozeti_izni_firma_ozetini_acmaz_403(): void
    {
        $this->actingAs($this->kullanici('kesinti_ozeti.goruntule'))
            ->getJson($this->adres())
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');
    }
}
