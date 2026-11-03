<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\Kesinti\ErpFirmaKaynagi;
use App\Services\Kesinti\ErpProjeKaynagi;
use App\Services\Rapor\ErpRaporKaynagi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Raporlar → Taşeron Hakedişleri: ERP proc'unun hakedişleri, hakediş başına
 * satır. Proc ve WEB_ERP_* view'ları sahte kaynakla taklit edilir (gerçek
 * proc test ortamında çalıştırılamıyor).
 */
final class TaseronHakedisleriRaporTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/raporlar/taseron-hakedisleri';

    private const PROC = 'dbo.DASHBOARD_TASERON_SOZLESME_HAKEDISLERI';

    private const TARIHLER = 'ilk_tarih=2026-01-01&son_tarih=2026-11-03';

    /**
     * Proc'a giden çağrılar (proc + kullanıcı kimliği + parametreler)
     *
     * @var list<array{0: string, 1: int, 2: array<string, string|null>}>
     */
    private array $cagrilar = [];

    /**
     * @param  list<array<string, mixed>>  $hakedisler
     */
    private function erpHakedisleri(array $hakedisler): void
    {
        $this->app->instance(ErpRaporKaynagi::class, new class($hakedisler, $this->cagrilar) implements ErpRaporKaynagi
        {
            /**
             * @param  list<array<string, mixed>>  $hakedisler
             * @param  list<array{0: string, 1: int, 2: array<string, string|null>}>  $cagrilar
             */
            public function __construct(private readonly array $hakedisler, private array &$cagrilar) {}

            public function satirlar(string $proc, int $erpKullaniciId, array $parametreler): array
            {
                $this->cagrilar[] = [$proc, $erpKullaniciId, $parametreler];

                return $this->hakedisler;
            }
        });
    }

    /**
     * Canlıdaki gibi proc satırı: verilmeyen tutarlar 0 (proc float metni döndürür)
     *
     * @param  array<string, string|null>  $degerler
     * @return array<string, mixed>
     */
    private function hakedis(int $id, string $tarih, string $proje, string $para, array $degerler = []): array
    {
        return [
            'HAKEDIS_ID' => (string) $id,
            'FIRMA_ADI' => 'ATİLLA GIDA',
            'PROJE_ADI' => $proje,
            // nchar: sonu boşlukla dolu gelir
            'SOZLESME_NO' => str_pad('HAB-26-1078.03.Z01', 50),
            'SOZLESME_KONUSU' => 'YEMEK TEMİNİ HİZMETLERİ',
            'HAKEDIS_NO' => str_pad('3', 50),
            'HAKEDIS_TARIHI' => "{$tarih} 00:00:00.000",
            'DONEM_BASLANGICI' => '2026-04-16 00:00:00.000',
            'DONEM_SONU' => '2026-05-16 00:00:00.000',
            'PARA_KODU' => str_pad($para, 5),
            'ONAY_DURUMU' => 'Onaylandı',
            'ONAYLAYAN' => 'ozalp.doganalp',
            'ONAY_ZAMANI' => '2026-07-06 13:53:14.850',
            'FATURA_NO' => 'TRN2026000002287',
            'HAKEDIS_DURUMU' => ' ',
            'IMALAT_TUTARI' => '0.0',
            'TUTANAK_TUTARI' => '0.0',
            'HAKEDIS' => '0.0',
            'IMALAT_KESINTI_TUTARI' => '0.0',
            'KDV' => '0.0',
            'KDVLI_HAKEDIS' => '0.0',
            'KDV_TEVKIFATI' => '0.0',
            'CARI_KESINTI' => '0.0',
            'YANSITILACAK_GIDER' => '0.0',
            'TASERON_ADINA_YAPILAN_IS' => '0.0',
            'NET_ODENECEK' => '0.0',
            'ODEME_TALEBI_YAPILAN_TUTAR' => '0.0',
            'ODENEN_TUTAR' => '0.0',
            ...$degerler,
        ];
    }

    private function raporcu(?int $erpKullaniciId = 2): User
    {
        $kullanici = User::factory()->create(['erp_kullanici_id' => $erpKullaniciId]);
        $rol = Rol::query()->create(['ad' => 'Raporcu']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'taseron_hakedisleri.goruntule']);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    public function test_hakedis_basina_satir_kullanicinin_kolon_sirasiyla_en_yenisi_once(): void
    {
        $this->erpHakedisleri([
            $this->hakedis(400, '2026-05-16', 'ZONE 7', 'TL', ['IMALAT_TUTARI' => '58776.989999999998', 'ODEME_TALEBI_YAPILAN_TUTAR' => '816187.76000000001']),
            $this->hakedis(443, '2026-06-30', 'ZONE 7', 'USD', ['IMALAT_TUTARI' => '100.5']),
            $this->hakedis(472, '2026-06-30', 'ZONE 10', 'TL', [
                'IMALAT_TUTARI' => '181886.53',
                'ONAY_ZAMANI' => null,
                'FATURA_NO' => null,
                'HAKEDIS_DURUMU' => 'Kesin Hakediş',
            ]),
        ]);

        $yanit = $this->actingAs($this->raporcu())
            ->getJson(self::ADRES.'?'.self::TARIHLER.'&proje_kodu=&firma_adi=')
            ->assertOk();

        // Hakediş tarihi yeniden eskiye; aynı günde son açılan hakediş önce
        $this->assertSame([472, 443, 400], array_column($yanit->json('data'), 'hakedis_id'));
        $this->assertSame([
            'hakedis_id', 'firma_adi', 'proje_adi', 'sozlesme_no', 'sozlesme_konusu', 'hakedis_no', 'hakedis_tarihi',
            'donem_baslangici', 'donem_sonu', 'para_kodu', 'imalat', 'tutanak', 'hakedis', 'imalat_kesintisi', 'kdv',
            'kdvli_hakedis', 'kdv_tevkifati', 'cari_kesinti', 'yansitilacak_gider', 'taseron_adina_yapilan_is',
            'net_odenecek', 'onay_durumu', 'onaylayan', 'onay_tarihi', 'onay_saati', 'fatura_no', 'hakedis_durumu',
            'odeme_talebi_tutari', 'odenen_tutar',
        ], array_keys($yanit->json('data.0')));

        // nchar kırpılır, tarihler gün olarak; onaysız / faturasız hakedişte boş
        $yanit->assertJsonPath('data.0.sozlesme_no', 'HAB-26-1078.03.Z01')
            ->assertJsonPath('data.0.hakedis_no', '3')
            ->assertJsonPath('data.0.hakedis_tarihi', '2026-06-30')
            ->assertJsonPath('data.0.donem_baslangici', '2026-04-16')
            ->assertJsonPath('data.0.para_kodu', 'TL')
            ->assertJsonPath('data.0.onay_tarihi', null)
            ->assertJsonPath('data.0.onay_saati', null)
            ->assertJsonPath('data.0.fatura_no', '')
            ->assertJsonPath('data.0.hakedis_durumu', 'Kesin Hakediş')
            // Onay zamanı tarih + saat; tutar kuruşa yuvarlanır; ödeme talebi proc'un ODEME_TALEBI_YAPILAN_TUTAR'ı
            ->assertJsonPath('data.2.onay_tarihi', '2026-07-06')
            ->assertJsonPath('data.2.onay_saati', '13:53:14')
            ->assertJsonPath('data.2.hakedis_durumu', '')
            ->assertJsonPath('data.2.imalat', 58776.99)
            ->assertJsonPath('data.2.odeme_talebi_tutari', 816187.76);

        // Boş proje / firma = hepsi (NULL); proc kullanıcının ERP kimliğiyle süzer
        $this->assertSame(
            [[self::PROC, 2, ['ilk_tarih' => '2026-01-01', 'son_tarih' => '2026-11-03', 'proje_kodu' => null, 'firma_adi' => null]]],
            $this->cagrilar,
        );
    }

    public function test_proje_ve_firma_secimi_proca_gider_erp_kimligi_olmayan_kullanici_satir_almaz(): void
    {
        $this->erpHakedisleri([]);

        $this->actingAs($this->raporcu(null))
            ->getJson(self::ADRES.'?'.self::TARIHLER.'&proje_kodu=ZONE%2010&firma_adi='.urlencode('ATİLLA GIDA'))
            ->assertOk()
            ->assertExactJson(['data' => []]);

        $this->assertSame(
            [[self::PROC, -1, ['ilk_tarih' => '2026-01-01', 'son_tarih' => '2026-11-03', 'proje_kodu' => 'ZONE 10', 'firma_adi' => 'ATİLLA GIDA']]],
            $this->cagrilar,
        );
    }

    public function test_izni_olmayan_raporu_calistiramaz(): void
    {
        $this->erpHakedisleri([]);
        $this->actingAs(User::factory()->create(['erp_kullanici_id' => 2]));

        foreach ([['get', ''], ['post', '/excel'], ['get', '/secenekler']] as [$metot, $uc]) {
            $this->json($metot, self::ADRES.$uc.'?'.self::TARIHLER, ['anahtarlar' => []])
                ->assertForbidden()
                ->assertJsonPath('kod', 'ERISIM_ENGELLI');
        }
        $this->assertSame([], $this->cagrilar);
    }

    public function test_tarihler_zorunlu_son_tarih_ilk_tarihten_once_olamaz(): void
    {
        $this->erpHakedisleri([]);
        $this->actingAs($this->raporcu());

        $this->getJson(self::ADRES.'?ilk_tarih=2026-11-03&son_tarih=2026-01-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('son_tarih', 'hatalar');
        $this->getJson(self::ADRES)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('ilk_tarih', 'hatalar');
        $this->assertSame([], $this->cagrilar);
    }

    public function test_excel_ekranda_suzulen_hakedislerle_kolon_sirasiyla_ve_toplamla_iner(): void
    {
        $this->erpHakedisleri([
            $this->hakedis(1, '2026-05-16', 'ZONE 7', 'TL', ['IMALAT_TUTARI' => '10.5', 'ODENEN_TUTAR' => '3']),
            $this->hakedis(2, '2026-06-30', 'ZONE 10', 'EUR', ['IMALAT_TUTARI' => '7', 'ONAY_ZAMANI' => null]),
            // Ekranda süzülüp dışarıda kalan: Excel'e ve toplama girmez
            $this->hakedis(3, '2026-07-01', 'ZONE 8', 'TL', ['IMALAT_TUTARI' => '100']),
        ]);
        $this->actingAs($this->raporcu());
        $parametreler = ['ilk_tarih' => '2026-01-01', 'son_tarih' => '2026-11-03', 'proje_kodu' => '', 'firma_adi' => ''];

        $this->postJson(self::ADRES.'/excel', $parametreler)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('anahtarlar', 'hatalar');

        // Ekrandaki sırayla; proc'ta artık olmayan kimlik (99) atlanır
        $yanit = $this->post(self::ADRES.'/excel', [...$parametreler, 'anahtarlar' => ['2', '99', '1']])->assertOk();
        $this->assertStringContainsString('taseron-hakedisleri.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        $sayfa = IOFactory::load($yanit->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame([
            'Sıra No', 'Firma Adı', 'Proje Adı', 'Sözleşme No', 'Sözleşme Konusu', 'Hakediş No', 'Hakediş Tarihi',
            'Dönem Başlangıcı', 'Dönem Sonu', 'Para Kodu', 'İmalat', 'Tutanak', 'Hakediş', 'İmalat Kesintisi', 'KDV',
            'KDV\'li Hakediş', 'KDV Tevkifatı', 'Cari Kesinti', 'Yansıtılacak Gider', 'Taşeron Adına Yapılan İş',
            'Net Ödenecek', 'Onay Durumu', 'Onaylayan', 'Onay Tarihi', 'Onay Saati', 'Fatura No', 'Hakediş Durumu',
            'Ödeme Talebi Tutarı', 'Ödenen Tutar',
        ], $sayfa->rangeToArray('A1:AC1')[0]);

        // Sıra No, Proje, Hakediş Tarihi, Para Kodu, İmalat, Onay Tarihi, Onay Saati, Ödenen Tutar
        $satir = fn (int $no): array => array_map(
            fn (string $kolon): mixed => $sayfa->getCell($kolon.$no)->getFormattedValue(),
            ['A', 'C', 'G', 'J', 'K', 'X', 'Y', 'AC'],
        );
        // Ekrandaki sıra (en yeni hakediş önce); tarihler Excel tarihi, gün.ay.yıl görünür
        $this->assertSame(['1', 'ZONE 10', '30.06.2026', 'EUR', '7.00', '', '', '0.00'], $satir(2));
        $this->assertSame(['2', 'ZONE 7', '16.05.2026', 'TL', '10.50', '06.07.2026', '13:53:14', '3.00'], $satir(3));
        $this->assertIsNumeric($sayfa->getCell('G2')->getValue());
        // Para koduna göre TOPLAM satırları, otomatik filtrenin dışında
        $this->assertSame(['TOPLAM', '', '', 'EUR', '7.00', '', '', '0.00'], $satir(4));
        $this->assertSame(['TOPLAM', '', '', 'TL', '10.50', '', '', '3.00'], $satir(5));
        $this->assertSame('A1:AC3', $sayfa->getAutoFilter()->getRange());
    }

    public function test_secenekler_proje_kodlari_ve_firma_adlari_turkce_sirali_tekrarsiz(): void
    {
        $this->app->instance(ErpProjeKaynagi::class, new class implements ErpProjeKaynagi
        {
            public function projeler(): array
            {
                return [
                    ['kod' => 'ZONE 10', 'ad' => 'ZONE 10', 'guid' => 'g0'],
                    ['kod' => 'ZONE 7', 'ad' => 'ZONE 7', 'guid' => 'g1'],
                    ['kod' => 'İŞLETME', 'ad' => 'İşletme', 'guid' => 'g2'],
                    ['kod' => '', 'ad' => 'Kodsuz', 'guid' => 'g3'],
                ];
            }
        });
        $this->app->instance(ErpFirmaKaynagi::class, new class implements ErpFirmaKaynagi
        {
            public function firmalar(): array
            {
                $firma = fn (string $ad): array => ['kod' => 'K', 'ad' => $ad, 'guid' => 'g', 'vergi_kimlik_no' => null, 'vergi_dairesi' => null, 'eposta' => null, 'gsm_no' => null];

                return [$firma('ZİRVE YAPI'), $firma('ÇELİK İNŞAAT'), $firma('ZİRVE YAPI'), $firma('')];
            }
        });

        $this->actingAs($this->raporcu())
            ->getJson(self::ADRES.'/secenekler')
            ->assertOk()
            ->assertExactJson(['data' => [
                'projeler' => [['kod' => 'İŞLETME', 'ad' => 'İşletme'], ['kod' => 'ZONE 7', 'ad' => 'ZONE 7'], ['kod' => 'ZONE 10', 'ad' => 'ZONE 10']],
                'firmalar' => ['ÇELİK İNŞAAT', 'ZİRVE YAPI'],
            ]]);
    }
}
