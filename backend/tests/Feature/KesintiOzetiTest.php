<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\Rol;
use App\Models\User;
use App\Models\YemekKesintisi;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Kesinti Yönetimi → Kesinti Özeti: Tüm Kesintiler'den genel özet (HAB /
 * taşeron payı), proje ve kesinti türü dağılımı, en yüksek kesintili
 * taşeronlar; tarihe kadar, tek para biriminde. Yalnız rapor.
 */
final class KesintiOzetiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/ozet';

    private int $tl;

    private int $usd;

    private Proje $zone6;

    private Proje $zone7;

    private Firma $zetas;

    private Firma $mfk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tl = (int) ParaBirimi::query()->where('kod', 'TL')->value('id');
        $this->usd = (int) ParaBirimi::query()->where('kod', 'USD')->value('id');
    }

    private function kullanici(string $izin = 'kesinti_ozeti.goruntule'): User
    {
        $kullanici = User::factory()->create();
        $rol = Rol::query()->create(['ad' => 'Kesinti Raporcu']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => $izin]);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /**
     * TL, 30.09.2026'ya kadar: ZETAŞ'a yansıtılan yemek (ZONE 10), HAB'ın üstlendiği
     * yemek (ZONE 6), MFK'ya yansıtılan işgücü (ZONE 6), HAB'ın üstlendiği işgücü
     * (ZONE 10, firma ZETAŞ). Dışarıda kalanlar: Ekim'deki ve USD satır.
     */
    private function ornekVeri(): void
    {
        $hab = Firma::factory()->create(['kod' => 'HAB', 'firmamiz' => true]);
        $zetas = $this->zetas = Firma::factory()->create(['kod' => 'ZETAŞ', 'ad' => 'ZETAŞ ZEMİN TEKNOLOJİSİ']);
        $mfk = $this->mfk = Firma::factory()->create(['kod' => 'MFK']);
        $this->zone6 = Proje::factory()->create(['kod' => 'ZONE 6', 'ad' => 'ZONE 6']);
        $this->zone7 = Proje::factory()->create(['kod' => 'ZONE 7', 'ad' => 'ZONE 7']);
        $zone10 = Proje::factory()->create(['kod' => 'ZONE 10', 'ad' => 'ZONE 10']);
        $yemek = KesintiTuru::factory()->create(['kod' => 'YEMEK', 'icmal' => 'yemek']);
        $isgucu = KesintiTuru::factory()->create(['kod' => 'İŞGÜCÜ', 'icmal' => 'isgucu']);

        $tutarlar = fn (string $toplam, string $iptal, string $kesilen, string $kalan): array => [
            'toplam_tutar' => $toplam,
            'iptal_edilen_tutar' => $iptal,
            'yansitilan_tutar' => number_format((float) $toplam - (float) $iptal, 2, '.', ''),
            'kesilen_tutar' => $kesilen,
            'kalan_tutar' => $kalan,
        ];
        $yemekSatiri = fn (Firma $firma, Proje $proje, YansitmaTuru $yansitma, string $tarih, array $tutar, ?int $para = null) => YemekKesintisi::factory()->create([
            'kesinti_turu_id' => $yemek->id, 'firma_id' => $firma->id, 'proje_id' => $proje->id, 'yansitma_turu' => $yansitma,
            'tarih' => $tarih, 'para_birimi_id' => $para ?? $this->tl, ...$tutar,
        ]);
        $isgucuSatiri = fn (Firma $firma, Proje $proje, YansitmaTuru $yansitma, string $tarih, array $tutar) => IsgucuKesintisi::factory()->create([
            'kesinti_turu_id' => $isgucu->id, 'firma_id' => $firma->id, 'proje_id' => $proje->id, 'yansitma_turu' => $yansitma,
            'tarih' => $tarih, ...$tutar,
        ]);

        $yemekSatiri($zetas, $zone10, YansitmaTuru::Taseron, '2026-09-10', $tutarlar('1000.00', '100.00', '800.00', '100.00'));
        $yemekSatiri($hab, $this->zone6, YansitmaTuru::Hab, '2026-09-15', $tutarlar('500.00', '0.00', '500.00', '0.00'));
        $isgucuSatiri($mfk, $this->zone6, YansitmaTuru::Taseron, '2026-08-01', $tutarlar('300.00', '0.00', '200.00', '100.00'));
        $isgucuSatiri($zetas, $zone10, YansitmaTuru::Hab, '2026-09-30', $tutarlar('250.00', '50.00', '0.00', '200.00'));
        // Tarihten sonra ve başka para biriminde: rapora girmez
        $yemekSatiri($mfk, $this->zone6, YansitmaTuru::Taseron, '2026-10-01', $tutarlar('9999.00', '0.00', '9999.00', '0.00'));
        $yemekSatiri($mfk, $this->zone6, YansitmaTuru::Taseron, '2026-09-01', $tutarlar('7777.00', '0.00', '7777.00', '0.00'), $this->usd);
    }

    /**
     * @param  array<string, mixed>  $ek
     */
    private function adres(array $ek = [], string $uc = ''): string
    {
        return self::ADRES.$uc.'?'.http_build_query(['tarih' => '2026-09-30', 'para_birimi_id' => $this->tl, ...$ek]);
    }

    public function test_hab_ustlendigi_ve_taseron_payi_yansitma_turune_gore_ayrilir_dagilimlar_ve_taseronlar(): void
    {
        $this->ornekVeri();

        $this->actingAs($this->kullanici())
            ->getJson($this->adres())
            ->assertOk()
            ->assertJsonPath('data.tarih', '2026-09-30')
            ->assertJsonPath('data.para_birimi', 'TL')
            ->assertJsonPath('data.isveren', 'HAB')
            // Seçim yoksa veride geçen projeler, doğal sırayla
            ->assertJsonPath('data.projeler', ['ZONE 6', 'ZONE 10'])
            ->assertJsonPath('data.firmalar', [])
            ->assertJsonPath('data.genel', [
                'toplam' => '2050.00', 'hab' => '750.00', 'taseron' => '1300.00',
                'iptal' => '100.00', 'kesilen' => '1000.00', 'kalan' => '200.00',
            ])
            ->assertJsonPath('data.proje_dagilimi.*.kod', ['ZONE 6', 'ZONE 10'])
            ->assertJsonPath('data.proje_dagilimi.1', [
                'kod' => 'ZONE 10', 'ad' => 'ZONE 10', 'toplam' => '1250.00', 'hab' => '250.00',
                'taseron' => '1000.00', 'iptal' => '100.00', 'kesilen' => '800.00', 'kalan' => '100.00',
            ])
            // Kesinti türleri toplamı büyükten küçüğe
            ->assertJsonPath('data.tur_dagilimi.*.kod', ['YEMEK', 'İŞGÜCÜ'])
            ->assertJsonPath('data.tur_dagilimi.1.toplam', '550.00')
            ->assertJsonPath('data.tur_dagilimi.1.hab', '250.00')
            // Yalnız taşerona yansıtılanlar: HAB'ın üstlendiği ZETAŞ satırı firmanın toplamına girmez
            ->assertJsonPath('data.taseronlar.*.kod', ['ZETAŞ', 'MFK'])
            ->assertJsonPath('data.taseronlar.0', [
                'kod' => 'ZETAŞ', 'ad' => 'ZETAŞ ZEMİN TEKNOLOJİSİ', 'toplam' => '1000.00', 'hab' => '0.00',
                'taseron' => '1000.00', 'iptal' => '100.00', 'kesilen' => '800.00', 'kalan' => '100.00',
            ]);
    }

    public function test_secilen_projelerle_suzulur_basliga_verisiz_secilen_proje_de_yazilir(): void
    {
        $this->ornekVeri();

        $this->actingAs($this->kullanici())
            ->getJson($this->adres(['proje_id' => [$this->zone7->id, $this->zone6->id]]))
            ->assertOk()
            ->assertJsonPath('data.projeler', ['ZONE 6', 'ZONE 7'])
            ->assertJsonPath('data.genel.toplam', '800.00')
            ->assertJsonPath('data.genel.hab', '500.00')
            ->assertJsonPath('data.proje_dagilimi.*.kod', ['ZONE 6'])
            ->assertJsonPath('data.taseronlar.*.kod', ['MFK']);
    }

    public function test_secilen_firmalarla_hab_ve_taseron_kesintileri_birlikte_suzulur_projeyle_birlesir(): void
    {
        $this->ornekVeri();
        $this->actingAs($this->kullanici());

        // ZETAŞ: kendisine yansıtılan 1000 + HAB'ın üstlendiği 250
        $this->getJson($this->adres(['firma_id' => [$this->zetas->id]]))
            ->assertOk()
            ->assertJsonPath('data.firmalar', ['ZETAŞ'])
            ->assertJsonPath('data.projeler', ['ZONE 10'])
            ->assertJsonPath('data.genel.toplam', '1250.00')
            ->assertJsonPath('data.genel.hab', '250.00')
            ->assertJsonPath('data.genel.taseron', '1000.00')
            ->assertJsonPath('data.taseronlar.*.kod', ['ZETAŞ']);

        // Firma ve proje süzgeci birlikte: ZONE 6'da yalnız MFK'nın işgücü
        $this->getJson($this->adres(['firma_id' => [$this->zetas->id, $this->mfk->id], 'proje_id' => [$this->zone6->id]]))
            ->assertOk()
            ->assertJsonPath('data.firmalar', ['MFK', 'ZETAŞ'])
            ->assertJsonPath('data.genel.toplam', '300.00')
            ->assertJsonPath('data.tur_dagilimi.*.kod', ['İŞGÜCÜ']);
    }

    public function test_tarih_ve_para_birimi_zorunludur_422(): void
    {
        $this->actingAs($this->kullanici())
            ->getJson(self::ADRES)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tarih', 'para_birimi_id'], 'hatalar');
    }

    public function test_secenekler_kesintide_gecen_projeler_ve_en_cok_satirli_para_birimi_once(): void
    {
        $this->ornekVeri();
        Proje::factory()->create(['kod' => 'KULLANILMAYAN']);

        $this->actingAs($this->kullanici())
            ->getJson(self::ADRES.'/secenekler')
            ->assertOk()
            ->assertJsonPath('data.projeler.*.kod', ['ZONE 10', 'ZONE 6'])
            ->assertJsonPath('data.firmalar.*.kod', ['HAB', 'MFK', 'ZETAŞ'])
            ->assertJsonPath('data.para_birimleri.*.kod', ['TL', 'USD']);
    }

    public function test_excel_ekrandaki_raporu_tek_sayfada_indirir(): void
    {
        $this->ornekVeri();

        $yanit = $this->actingAs($this->kullanici())
            ->get($this->adres(uc: '/excel'))
            ->assertOk();
        $this->assertStringContainsString('kesinti-ozeti.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Kesinti Özeti'], $kitap->getSheetNames());
        $sayfa = $kitap->getActiveSheet();
        $deger = fn (string $hucre): string => (string) $sayfa->getCell($hucre)->getFormattedValue();
        $satir = fn (int $no, string $son = 'G'): array => array_map(
            fn (string $kolon): string => $deger($kolon.$no),
            range('A', $son),
        );

        $this->assertSame('ZONE 6 - ZONE 10 – EYLÜL SONU İTİBARIYLA KESİNTİ LİSTESİ', $deger('A2'));
        $this->assertSame(['30.09.2026', 'ZONE 6 · ZONE 10', 'HAB', 'TL'], [$deger('G1'), $deger('B3'), $deger('F3'), $deger('G3')]);
        // Özet kartları: değer + oran (HAB toplamın, iptal taşeron payının)
        $this->assertSame(['2,050', 'TL · %100'], [$deger('B8'), $deger('B9')]);
        $this->assertSame(['750', 'TL · TOPLAMIN %36,6'], [$deger('D8'), $deger('D9')]);
        $this->assertSame(['100', 'TL · TAŞERON PAYININ %7,7'], [$deger('B12'), $deger('B13')]);
        // Zone bazında: başlık, satırlar (sıfır "-"), lacivert TOPLAM
        $this->assertSame(['ZONE', 'TOPLAM KESİNTİ', 'HAB ÜSTLENDİĞİ', 'TAŞERON PAYI', 'İPTAL EDİLEN', 'KESİLEN', 'KALAN'], $satir(19));
        $this->assertSame(['ZONE 6', '800.00', '500.00', '300.00', '-', '200.00', '100.00'], $satir(20));
        $this->assertSame(['TOPLAM', '2,050.00', '750.00', '1,300.00', '100.00', '1,000.00', '200.00'], $satir(22));
        // Kesinti türü bazında: toplama göre
        $this->assertSame(['YEMEK', 'İŞGÜCÜ'], [$deger('A27'), $deger('A28')]);
        // En yüksek kesintili taşeronlar: firmanın toplamı = taşeron payı
        $this->assertSame(['TAŞERON FİRMA', 'TOPLAM KESİNTİ', 'İPTAL EDİLEN', 'KESİLEN', 'KALAN'], $satir(35, 'E'));
        $this->assertSame(['ZETAŞ', '1,000.00', '100.00', '800.00', '100.00'], $satir(36, 'E'));
        $this->assertSame(['MFK', '300.00', '-', '200.00', '100.00'], $satir(37, 'E'));
    }

    public function test_excelde_secilen_firmalar_ust_bilgide_yazilir_rapor_bir_satir_asagidan_baslar(): void
    {
        $this->ornekVeri();

        $yanit = $this->actingAs($this->kullanici())
            ->get($this->adres(['firma_id' => [$this->zetas->id]], '/excel'))
            ->assertOk();

        $sayfa = IOFactory::load($yanit->baseResponse->getFile()->getPathname())->getActiveSheet();
        $deger = fn (string $hucre): string => (string) $sayfa->getCell($hucre)->getFormattedValue();
        $this->assertSame(['TAŞERON', 'ZETAŞ'], [$deger('A4'), $deger('B4')]);
        $this->assertSame(['1. ÖZET', '1,250'], [$deger('A6'), $deger('B9')]);
    }

    public function test_baska_kesinti_ekraninin_izni_raporu_acmaz_403(): void
    {
        $this->actingAs($this->kullanici('tum_kesintiler.goruntule'))
            ->getJson($this->adres())
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');
    }
}
