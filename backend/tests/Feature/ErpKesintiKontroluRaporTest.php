<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Models\Rol;
use App\Models\User;
use App\Models\YemekKesintisi;
use App\Services\Rapor\ErpRaporKaynagi;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Raporlar → ERP Kesinti Kontrolü: kesinti icmalleri Taşeron + Proje + Para
 * Birimi bazında, yanında ERP'nin hakediş yansıtılacak gideri ve gider
 * yansıtma faturası toplamı. ERP proc'ları sahte kaynakla taklit edilir.
 */
final class ErpKesintiKontroluRaporTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/raporlar/erp-kesinti-kontrolu';

    private const HAKEDIS_PROC = 'dbo.DASHBOARD_TASERON_SOZLESME_HAKEDISLERI';

    private const FATURA_PROC = 'dbo.ERP_GIDER_YANSITMA_FATURALARI';

    private const TMC = 'TMC YAPI İNŞAAT SANAYİ VE TİCARET LİMİTED ŞİRKETİ';

    private const KORTEK = 'KORTEK YAPI TAAHHÜT SANAYİ VE TİCARET ANONİM ŞİRKETİ';

    private const PARAMETRELER = ['ilk_tarih' => '2000-01-01', 'son_tarih' => '2026-11-04', 'proje_kodu' => '', 'firma_adi' => ''];

    /**
     * Proc'lara giden çağrılar (proc + kullanıcı kimliği + parametreler)
     *
     * @var list<array{0: string, 1: int, 2: array<string, string|null>}>
     */
    private array $cagrilar = [];

    private Proje $zone10;

    protected function setUp(): void
    {
        parent::setUp();

        // Kodu ile adı farklı proje: fatura proc'u kodu, hakediş proc'u adı döndürür
        $this->zone10 = Proje::factory()->create(['kod' => 'ZONE 10', 'ad' => 'ZONE 10 ANA BÜTÇE']);
    }

    /**
     * @param  list<array<string, mixed>>  $hakedisler
     * @param  list<array<string, mixed>>  $faturalar
     */
    private function erp(array $hakedisler = [], array $faturalar = []): void
    {
        $satirlar = [self::HAKEDIS_PROC => $hakedisler, self::FATURA_PROC => $faturalar];

        $this->app->instance(ErpRaporKaynagi::class, new class($satirlar, $this->cagrilar) implements ErpRaporKaynagi
        {
            /**
             * @param  array<string, list<array<string, mixed>>>  $satirlar
             * @param  list<array{0: string, 1: int, 2: array<string, string|null>}>  $cagrilar
             */
            public function __construct(private readonly array $satirlar, private array &$cagrilar) {}

            public function satirlar(string $proc, int $erpKullaniciId, array $parametreler): array
            {
                $this->cagrilar[] = [$proc, $erpKullaniciId, $parametreler];

                return $this->satirlar[$proc];
            }
        });
    }

    /**
     * Hakediş proc satırı: verilmeyen kolonlar boş (rapor yalnız firma, proje, para ve yansıtılacak gideri kullanır)
     *
     * @return array<string, string>
     */
    private function hakedis(int $id, string $firma, string $proje, string $para, string $yansitilacakGider): array
    {
        return [
            'HAKEDIS_ID' => (string) $id,
            'FIRMA_ADI' => $firma,
            'PROJE_ADI' => $proje,
            'PARA_KODU' => str_pad($para, 5),
            'HAKEDIS_TARIHI' => '2026-03-01 00:00:00.000',
            'YANSITILACAK_GIDER' => $yansitilacakGider,
        ];
    }

    /**
     * Gider yansıtma faturası proc satırı: tutar faturanın kendi para birimindeki genel toplam
     *
     * @return array<string, string>
     */
    private function fatura(string $no, string $unvan, string $proje, string $para, string $genelToplamDoviz): array
    {
        return [
            'TARIH' => '2026-03-01 00:00:00.000',
            'FATURA_NO' => str_pad($no, 50),
            'UNVAN' => $unvan,
            'PROJE_KODU' => str_pad($proje, 50),
            'PARA_KODU' => str_pad($para, 5),
            'GENEL_TOPLAM_TL' => '99999.00',
            'GENEL_TOPLAM_DOVIZ' => $genelToplamDoviz,
        ];
    }

    /**
     * @param  class-string<Model>  $model  icmal satırı
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string}  $tutarlar  toplam, iptal edilen, yansıtılan, kesilen, kalan
     */
    private function kesinti(string $model, Firma $firma, string $para, string $tarih, array $tutarlar, ?Proje $proje = null): void
    {
        $model::factory()->create([
            'firma_id' => $firma->id,
            'proje_id' => ($proje ?? $this->zone10)->id,
            'para_birimi_id' => ParaBirimi::query()->where('kod', $para)->value('id'),
            'tarih' => $tarih,
            ...array_combine(['toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar'], $tutarlar),
        ]);
    }

    private function raporcu(string $izin = 'erp_kesinti_kontrolu.goruntule'): User
    {
        $kullanici = User::factory()->create(['erp_kullanici_id' => 2]);
        $rol = Rol::query()->create(['ad' => 'Raporcu']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => $izin]);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /** Ortak veri: TMC iki icmalde + ERP'de, HAB yalnız kesintide, KORTEK kesintide TL / ERP'de USD */
    private function ornekVeri(): void
    {
        $tmc = Firma::factory()->create(['ad' => self::TMC]);
        $hab = Firma::factory()->create(['ad' => 'HAB']);
        $kortek = Firma::factory()->create(['ad' => self::KORTEK]);

        $this->kesinti(IsgucuKesintisi::class, $tmc, 'TL', '2026-03-01', ['1000.00', '0.00', '800.00', '700.00', '100.00']);
        $this->kesinti(YemekKesintisi::class, $tmc, 'TL', '2026-04-01', ['500.00', '100.00', '400.00', '400.00', '0.00']);
        $this->kesinti(YemekKesintisi::class, $hab, 'TL', '2026-03-01', ['50.00', '0.00', '50.00', '50.00', '0.00']);
        $this->kesinti(IsgucuKesintisi::class, $kortek, 'TL', '2026-03-01', ['339.50', '0.00', '339.50', '300.00', '39.50']);

        $this->erp(
            hakedisler: [
                // Ad harf / boşluk farkıyla, proje ADIYLA: yine TMC / ZONE 10 / TL'ye eşleşir
                $this->hakedis(1, ' tmc yapı  inşaat sanayi ve ticaret limited şirketi', 'ZONE 10 ANA BÜTÇE', 'TL', '1200.00'),
                $this->hakedis(2, self::TMC, 'ZONE 10', 'TL', '300.00'),
                // Yansıtılacak gideri olmayan hakediş yeni satır açmaz
                $this->hakedis(3, 'MET MÜHENDİSLİK', 'ZONE 10', 'USD', '0.00'),
            ],
            faturalar: [
                $this->fatura('INS1', self::TMC, 'ZONE 10', 'TL', '1500.00'),
                // Kesinti TL girilmiş, ERP'de USD: kesinti projesinde olduğu için ayrı satırda görünür
                $this->fatura('INS2', self::KORTEK, 'ZONE 10', 'USD', '84.50'),
                // Kesinti girilmeyen proje: raporun konusu değil
                $this->fatura('INS3', 'ROYAL KENT', 'ZONE 4-5 - ANA BÜTÇE', 'TL', '999.00'),
            ],
        );
    }

    public function test_kesinti_gruplari_yaninda_erp_hakedis_ve_fatura_toplamlari(): void
    {
        $this->ornekVeri();

        $yanit = $this->actingAs($this->raporcu())
            ->getJson(self::ADRES.'?'.http_build_query(self::PARAMETRELER))
            ->assertOk();

        $satirlar = array_map(
            fn (array $satir): array => array_diff_key($satir, ['anahtar' => true]),
            $yanit->json('data'),
        );
        // Taşeron, proje, para birimi sırasıyla
        $this->assertSame([
            [
                'taseron' => 'HAB', 'proje' => 'ZONE 10', 'para_kodu' => 'TL',
                'toplam_tutar' => 50, 'iptal_edilen_tutar' => 0, 'yansitilan_tutar' => 50, 'kesilen_tutar' => 50, 'kalan_tutar' => 0,
                'hakedis_yansitilacak_gider' => 0, 'yansitma_faturasi' => 0,
            ],
            [
                'taseron' => self::KORTEK, 'proje' => 'ZONE 10', 'para_kodu' => 'TL',
                'toplam_tutar' => 339.5, 'iptal_edilen_tutar' => 0, 'yansitilan_tutar' => 339.5, 'kesilen_tutar' => 300, 'kalan_tutar' => 39.5,
                'hakedis_yansitilacak_gider' => 0, 'yansitma_faturasi' => 0,
            ],
            [
                'taseron' => self::KORTEK, 'proje' => 'ZONE 10', 'para_kodu' => 'USD',
                'toplam_tutar' => 0, 'iptal_edilen_tutar' => 0, 'yansitilan_tutar' => 0, 'kesilen_tutar' => 0, 'kalan_tutar' => 0,
                'hakedis_yansitilacak_gider' => 0, 'yansitma_faturasi' => 84.5,
            ],
            [
                'taseron' => self::TMC, 'proje' => 'ZONE 10', 'para_kodu' => 'TL',
                'toplam_tutar' => 1500, 'iptal_edilen_tutar' => 100, 'yansitilan_tutar' => 1200, 'kesilen_tutar' => 1100, 'kalan_tutar' => 100,
                'hakedis_yansitilacak_gider' => 1500, 'yansitma_faturasi' => 1500,
            ],
        ], $satirlar);
        // Satır anahtarları farklı ve kısa (Excel isteği anahtar başına 100 karakter)
        $anahtarlar = array_column($yanit->json('data'), 'anahtar');
        $this->assertCount(4, array_unique($anahtarlar));
        $this->assertLessThanOrEqual(100, max(array_map(strlen(...), $anahtarlar)));

        // İki ERP raporu kullanıcının ERP kimliği ve aynı parametrelerle
        $parametreler = ['ilk_tarih' => '2000-01-01', 'son_tarih' => '2026-11-04', 'proje_kodu' => null, 'firma_adi' => null];
        $this->assertSame([
            [self::HAKEDIS_PROC, 2, $parametreler],
            [self::FATURA_PROC, 2, $parametreler],
        ], $this->cagrilar);
    }

    public function test_kesintiler_tarih_proje_ve_firma_parametreleriyle_suzulur(): void
    {
        $tmc = Firma::factory()->create(['ad' => self::TMC]);
        $hab = Firma::factory()->create(['ad' => 'HAB']);
        $diger = Proje::factory()->create(['kod' => 'ZONE 6', 'ad' => 'ZONE 6']);
        $this->kesinti(IsgucuKesintisi::class, $tmc, 'TL', '2026-03-01', ['1000.00', '0.00', '800.00', '700.00', '100.00']);
        // Tarih aralığı dışında
        $this->kesinti(YemekKesintisi::class, $tmc, 'TL', '2026-08-01', ['500.00', '0.00', '500.00', '500.00', '0.00']);
        // Başka proje
        $this->kesinti(YemekKesintisi::class, $tmc, 'TL', '2026-03-01', ['70.00', '0.00', '70.00', '70.00', '0.00'], $diger);
        // Başka firma
        $this->kesinti(YemekKesintisi::class, $hab, 'TL', '2026-03-01', ['50.00', '0.00', '50.00', '50.00', '0.00']);
        $this->erp();
        $this->actingAs($this->raporcu());
        $parametreler = ['ilk_tarih' => '2026-01-01', 'son_tarih' => '2026-06-30', 'proje_kodu' => 'ZONE 10', 'firma_adi' => self::TMC];

        $this->getJson(self::ADRES.'?'.http_build_query($parametreler))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.taseron', self::TMC)
            ->assertJsonPath('data.0.toplam_tutar', 1000);
        // ERP proc'ları da aynı parametrelerle süzer
        $this->assertSame([self::HAKEDIS_PROC, 2, $parametreler], $this->cagrilar[0]);

        // Firmalarda olmayan firma adı: kesinti satırı yok
        $this->getJson(self::ADRES.'?'.http_build_query([...$parametreler, 'firma_adi' => 'BİLİNMEYEN FİRMA']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_excel_ekrandaki_satirlari_para_birimine_gore_toplamla_indirir(): void
    {
        $this->ornekVeri();
        $this->actingAs($this->raporcu());
        $anahtarlar = array_column($this->getJson(self::ADRES.'?'.http_build_query(self::PARAMETRELER))->json('data'), 'anahtar');

        // Ekranda süzülmüş: KORTEK USD + TMC, ekrandaki sırayla
        $yanit = $this->post(self::ADRES.'/excel', [...self::PARAMETRELER, 'anahtarlar' => [$anahtarlar[3], $anahtarlar[2]]])
            ->assertOk();
        $this->assertStringContainsString('erp-kesinti-kontrolu.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        // Özet sekmesi yok: tek sayfa, raporun adıyla
        $this->assertSame(['ERP Kesinti Kontrolü'], $kitap->getSheetNames());
        $sayfa = $kitap->getActiveSheet();
        $this->assertSame([
            'Sıra No', 'Taşeron', 'Proje', 'Para Birimi', 'Toplam Tutar', 'İptal Edilen Tutar', 'Yansıtılan Tutar',
            'Kesilen Tutar', 'Kalan Tutar', 'Hakediş Yansıtılacak Gider', 'Yansıtma Faturası',
        ], $sayfa->rangeToArray('A1:K1')[0]);

        // Sıra No, Taşeron, Para Birimi, Toplam Tutar, Hakediş Yansıtılacak Gider, Yansıtma Faturası
        $satir = fn (int $no): array => array_map(
            fn (string $kolon): mixed => $sayfa->getCell($kolon.$no)->getFormattedValue(),
            ['A', 'B', 'D', 'E', 'J', 'K'],
        );
        $this->assertSame(['1', self::TMC, 'TL', '1,500.00', '1,500.00', '1,500.00'], $satir(2));
        $this->assertSame(['2', self::KORTEK, 'USD', '0.00', '0.00', '84.50'], $satir(3));
        $this->assertSame(['TOPLAM', '', 'TL', '1,500.00', '1,500.00', '1,500.00'], $satir(4));
        $this->assertSame(['TOPLAM', '', 'USD', '0.00', '0.00', '84.50'], $satir(5));
        $this->assertSame('', $sayfa->getCell('A6')->getFormattedValue());
    }

    public function test_baska_raporun_izni_bu_raporu_acmaz(): void
    {
        $this->erp();
        $this->actingAs($this->raporcu('taseron_hakedisleri.goruntule'));

        $this->getJson(self::ADRES.'?'.http_build_query(self::PARAMETRELER))
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');
        $this->assertSame([], $this->cagrilar);
    }
}
