<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Models\Proje;
use App\Models\Rol;
use App\Models\User;
use App\Models\YemekKesintisi;
use App\Services\Kesinti\KesintiEkstresi;
use App\Services\Kesinti\YansitmaTuru;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Firma Özeti → hücreye tıklanınca kesinti ekstresi (PDF): hücrenin arkasındaki
 * satırlar kendi ekranının kolonlarıyla; kalan toplamı hücreye eşit.
 */
final class KesintiEkstresiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/kesinti/firma-ozeti/ekstre';

    private Firma $zetas;

    private Proje $zone10;

    private KesintiTuru $yemek;

    private int $tl;

    private function kullanici(string $izin = 'firma_ozeti.goruntule'): User
    {
        $kullanici = User::factory()->create();
        $rol = Rol::query()->create(['ad' => 'Firma Raporcu']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => $izin]);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /**
     * ZETAŞ / ZONE 10 (TL, 30.09.2026'ya kadar, taşerona yansıtılan): yemek kalan
     * 100 + 60, işgücü kalan 40 → Firma Özeti hücreleri YEMEK 160, Toplam Kalan 200.
     * Dışarıda: HAB'ın üstlendiği yemek, Ekim'deki yemek, başka projedeki yemek.
     */
    private function ornekVeri(): void
    {
        $this->tl = (int) DB::table('para_birimleri')->where('kod', 'TL')->value('id');
        $this->zetas = Firma::factory()->create(['kod' => 'ZETAŞ', 'ad' => 'ZETAŞ ZEMİN TEKNOLOJİSİ', 'vergi_kimlik_no' => '9980001234']);
        Firma::factory()->create(['kod' => 'HAB', 'firmamiz' => true]);
        $this->zone10 = Proje::factory()->create(['kod' => 'ZONE 10', 'ad' => 'ZONE 10 TERSANE']);
        $zone6 = Proje::factory()->create(['kod' => 'ZONE 6']);
        $this->yemek = KesintiTuru::factory()->create(['kod' => 'YEMEK', 'icmal' => 'yemek']);
        $isgucu = KesintiTuru::factory()->create(['kod' => 'İŞGÜCÜ', 'icmal' => 'isgucu']);

        $satir = function (string $model, KesintiTuru $tur, Proje $proje, YansitmaTuru $yansitma, string $kalan, string $tarih): Model {
            /** @var class-string<YemekKesintisi|IsgucuKesintisi> $model */
            return $model::factory()->create([
                'kesinti_turu_id' => $tur->id, 'firma_id' => $this->zetas->id, 'proje_id' => $proje->id,
                'yansitma_turu' => $yansitma, 'tarih' => $tarih, 'kaynak_firma' => 'ATİLLA GIDA',
                'toplam_tutar' => '500.00', 'iptal_edilen_tutar' => '0.00', 'yansitilan_tutar' => '500.00',
                'kesilen_tutar' => number_format(500 - (float) $kalan, 2, '.', ''), 'kalan_tutar' => $kalan,
                ...($model === YemekKesintisi::class ? ['kaynak_hakedis_no' => '3'] : []),
            ]);
        };

        $satir(YemekKesintisi::class, $this->yemek, $this->zone10, YansitmaTuru::Taseron, '60.00', '2026-09-12');
        $satir(YemekKesintisi::class, $this->yemek, $this->zone10, YansitmaTuru::Taseron, '100.00', '2026-09-10');
        $satir(IsgucuKesintisi::class, $isgucu, $this->zone10, YansitmaTuru::Taseron, '40.00', '2026-08-01');
        $satir(YemekKesintisi::class, $this->yemek, $this->zone10, YansitmaTuru::Hab, '500.00', '2026-09-10');
        $satir(YemekKesintisi::class, $this->yemek, $this->zone10, YansitmaTuru::Taseron, '999.00', '2026-10-01');
        $satir(YemekKesintisi::class, $this->yemek, $zone6, YansitmaTuru::Taseron, '77.00', '2026-09-10');
    }

    /**
     * @return array{tarih: string, para_birimi_id: int, firma_id: int, proje_id: int, kesinti_turu_id: int|null}
     */
    private function parametreler(?int $kesintiTuruId): array
    {
        return [
            'tarih' => '2026-09-30', 'para_birimi_id' => $this->tl, 'firma_id' => $this->zetas->id,
            'proje_id' => $this->zone10->id, 'kesinti_turu_id' => $kesintiTuruId,
        ];
    }

    public function test_her_kesinti_ekrani_ekstrede(): void
    {
        $this->assertEqualsCanonicalizing(KesintiYuklemesi::ICMALLER, array_keys(KesintiEkstresi::AKTARIMLAR));
    }

    public function test_tek_tur_ekstresi_hucrenin_satirlari_ekran_kolonlariyla_kalan_toplami_hucreye_esit(): void
    {
        $this->ornekVeri();

        $veri = app(KesintiEkstresi::class)->veri($this->parametreler($this->yemek->id));

        // Başlık türü söyler
        $this->assertSame('YEMEK KESİNTİ EKSTRESİ', $veri['baslik']);
        $this->assertSame('YEMEK', $veri['kesinti_turu']);
        $this->assertSame(['ZETAŞ', '9980001234', 'ZONE 10', 'ZONE 10 TERSANE', 'TL', 'HAB', '30.09.2026'], [
            $veri['firma']['kod'], $veri['firma']['vergi_kimlik_no'], $veri['proje'], $veri['proje_ad'], $veri['para_birimi'], $veri['isveren'], $veri['tarih'],
        ]);
        $this->assertCount(1, $veri['bolumler']);
        $bolum = $veri['bolumler'][0];
        // Mahal yerine proje adı; her satırda aynı olanlar (tür, taşeron, yansıtma, proje kodu, para birimi) başlıkta
        $this->assertSame(
            ['PROJE', 'TARİH', 'KAYNAK FİRMA', 'KAYNAK HAKEDİŞ NO', 'TOPLAM TUTAR', 'İPTAL EDİLEN TUTAR', 'YANSITILAN TUTAR', 'KESİLEN TUTAR', 'KALAN TUTAR'],
            array_column($bolum['kolonlar'], 'baslik'),
        );
        // Tarih sırasıyla; HAB'ın üstlendiği, Ekim'deki ve başka projedeki satır yok
        $this->assertSame([
            ['ZONE 10 TERSANE', '10.09.2026', 'ATİLLA GIDA', '3', '500,00', '0,00', '500,00', '400,00', '100,00'],
            ['ZONE 10 TERSANE', '12.09.2026', 'ATİLLA GIDA', '3', '500,00', '0,00', '500,00', '440,00', '60,00'],
        ], $bolum['satirlar']);
        $this->assertSame('160,00', $bolum['toplam']['kalan_tutar']);
        $this->assertSame('1.000,00', $bolum['toplam']['toplam_tutar']);
    }

    public function test_toplam_kalan_ekstresi_butun_turler_ekran_sirasiyla_genel_toplamla(): void
    {
        $this->ornekVeri();

        $veri = app(KesintiEkstresi::class)->veri($this->parametreler(null));

        $this->assertSame('TÜM KESİNTİLER EKSTRESİ', $veri['baslik']);
        $this->assertNull($veri['kesinti_turu']);
        $this->assertSame(['İŞGÜCÜ', 'YEMEK'], array_column($veri['bolumler'], 'kod'));
        $this->assertSame('200,00', $veri['genel_toplam']['kalan_tutar']);
        $this->assertSame('1.500,00', $veri['genel_toplam']['toplam_tutar']);
    }

    public function test_kodu_kesintisi_ile_biten_turun_basligi_tekrarlamaz(): void
    {
        $this->ornekVeri();
        $malzeme = KesintiTuru::factory()->create(['kod' => 'MALZEME KESİNTİSİ', 'icmal' => 'malzeme']);

        $veri = app(KesintiEkstresi::class)->veri($this->parametreler($malzeme->id));

        $this->assertSame('MALZEME KESİNTİSİ EKSTRESİ', $veri['baslik']);
        $this->assertSame([], $veri['bolumler']);
    }

    public function test_ekstre_pdf_olarak_iner(): void
    {
        $this->ornekVeri();

        $yanit = $this->actingAs($this->kullanici())
            ->get(self::ADRES.'?'.http_build_query($this->parametreler($this->yemek->id)))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', (string) $yanit->getContent());
        $this->assertStringContainsString('kesinti-ekstresi-zetas-zone-10-yemek.pdf', (string) $yanit->headers->get('Content-Disposition'));
    }

    public function test_firma_ve_proje_zorunlu_422(): void
    {
        $this->actingAs($this->kullanici())
            ->getJson(self::ADRES.'?tarih=2026-09-30')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['para_birimi_id', 'firma_id', 'proje_id'], 'hatalar');
    }

    public function test_kesinti_ozeti_izni_ekstreyi_acmaz_403(): void
    {
        $this->ornekVeri();

        $this->actingAs($this->kullanici('kesinti_ozeti.goruntule'))
            ->getJson(self::ADRES.'?'.http_build_query($this->parametreler(null)))
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');
    }
}
