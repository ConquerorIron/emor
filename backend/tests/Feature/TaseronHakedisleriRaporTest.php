<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\Kesinti\ErpFirmaKaynagi;
use App\Services\Kesinti\ErpProjeKaynagi;
use App\Services\Rapor\TaseronHakedisKaynagi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Raporlar → Taşeron Hakedişleri: ERP proc'unun hakedişleri proje + para
 * koduna göre özetlenir. Proc ve WEB_ERP_* view'ları sahte kaynakla taklit
 * edilir (gerçek proc test ortamında çalıştırılamıyor).
 */
final class TaseronHakedisleriRaporTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/raporlar/taseron-hakedisleri';

    private const TARIHLER = 'ilk_tarih=2026-01-01&son_tarih=2026-11-03';

    /**
     * Proc'a giden çağrılar (kullanıcı kimliği + parametreler)
     *
     * @var list<array{0: int, 1: array<string, string|null>}>
     */
    private array $cagrilar = [];

    /**
     * @param  list<array<string, mixed>>  $hakedisler
     */
    private function erpHakedisleri(array $hakedisler): void
    {
        $this->app->instance(TaseronHakedisKaynagi::class, new class($hakedisler, $this->cagrilar) implements TaseronHakedisKaynagi
        {
            /**
             * @param  list<array<string, mixed>>  $hakedisler
             * @param  list<array{0: int, 1: array<string, string|null>}>  $cagrilar
             */
            public function __construct(private readonly array $hakedisler, private array &$cagrilar) {}

            public function hakedisler(int $erpKullaniciId, array $parametreler): array
            {
                $this->cagrilar[] = [$erpKullaniciId, $parametreler];

                return $this->hakedisler;
            }
        });
    }

    /**
     * Proc satırı: verilmeyen tutarlar 0 (proc float metni döndürür)
     *
     * @param  array<string, string>  $tutarlar
     * @return array<string, mixed>
     */
    private function hakedis(string $proje, string $para, array $tutarlar = []): array
    {
        return [
            'FIRMA_ADI' => 'ATİLLA GIDA',
            'PROJE_ADI' => $proje,
            // nchar: sonu boşlukla dolu gelir
            'PARA_KODU' => str_pad($para, 5),
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
            ...$tutarlar,
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

    public function test_hakedisler_proje_ve_para_koduna_gore_toplanir_turkce_siralanir(): void
    {
        $this->erpHakedisleri([
            $this->hakedis('ZONE 7', 'TL', ['IMALAT_TUTARI' => '58776.989999999998', 'ODEME_TALEBI_YAPILAN_TUTAR' => '816187.76000000001']),
            $this->hakedis('ZONE 7', 'USD', ['IMALAT_TUTARI' => '100.5']),
            $this->hakedis('İŞLETME', 'TL', ['NET_ODENECEK' => '10']),
            $this->hakedis('ZONE 7', 'TL', ['IMALAT_TUTARI' => '181886.53', 'ODEME_TALEBI_YAPILAN_TUTAR' => '519632.41999999998']),
            $this->hakedis('ÇEVRE', 'TL', ['IMALAT_TUTARI' => '1.25']),
        ]);

        $yanit = $this->actingAs($this->raporcu())
            ->getJson(self::ADRES.'?'.self::TARIHLER.'&proje_kodu=&firma_adi=')
            ->assertOk();

        // Ç ve İ, Z'den önce (bayt sırasında sona düşerdi)
        $this->assertSame(
            [['ÇEVRE', 'TL'], ['İŞLETME', 'TL'], ['ZONE 7', 'TL'], ['ZONE 7', 'USD']],
            array_map(fn (array $satir): array => [$satir['proje_adi'], $satir['para_kodu']], $yanit->json('data')),
        );
        // Her hakediş kuruşa yuvarlanıp toplanır; ödeme talebi proc'un ODEME_TALEBI_YAPILAN_TUTAR'ı
        $yanit->assertJsonPath('data.2.imalat', 240663.52)
            ->assertJsonPath('data.2.odeme_talebi_tutari', 1335820.18)
            ->assertJsonPath('data.1.net_odenecek', 10)
            ->assertJsonPath('toplamlar.0.para_kodu', 'TL')
            ->assertJsonPath('toplamlar.0.imalat', 240664.77)
            ->assertJsonPath('toplamlar.1.para_kodu', 'USD')
            ->assertJsonPath('toplamlar.1.imalat', 100.5);

        // Boş proje / firma = hepsi (NULL); proc kullanıcının ERP kimliğiyle süzer
        $this->assertSame(
            [[2, ['ilk_tarih' => '2026-01-01', 'son_tarih' => '2026-11-03', 'proje_kodu' => null, 'firma_adi' => null]]],
            $this->cagrilar,
        );
    }

    public function test_proje_ve_firma_secimi_proca_gider_erp_kimligi_olmayan_kullanici_satir_almaz(): void
    {
        $this->erpHakedisleri([]);

        $this->actingAs($this->raporcu(null))
            ->getJson(self::ADRES.'?'.self::TARIHLER.'&proje_kodu=ZONE%2010&firma_adi='.urlencode('ATİLLA GIDA'))
            ->assertOk()
            ->assertExactJson(['data' => [], 'toplamlar' => []]);

        $this->assertSame(
            [[-1, ['ilk_tarih' => '2026-01-01', 'son_tarih' => '2026-11-03', 'proje_kodu' => 'ZONE 10', 'firma_adi' => 'ATİLLA GIDA']]],
            $this->cagrilar,
        );
    }

    public function test_izni_olmayan_raporu_calistiramaz(): void
    {
        $this->erpHakedisleri([]);
        $this->actingAs(User::factory()->create(['erp_kullanici_id' => 2]));

        foreach (['', '/excel', '/secenekler'] as $uc) {
            $this->getJson(self::ADRES.$uc.'?'.self::TARIHLER)
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

    public function test_excel_ekranin_kolon_sirasiyla_ve_para_koduna_gore_toplamla_iner(): void
    {
        $this->erpHakedisleri([
            $this->hakedis('ZONE 7', 'TL', ['IMALAT_TUTARI' => '10.5', 'ODENEN_TUTAR' => '3']),
            $this->hakedis('ZONE 10', 'TL', ['IMALAT_TUTARI' => '2']),
            $this->hakedis('ZONE 10', 'EUR', ['IMALAT_TUTARI' => '7']),
        ]);

        $yanit = $this->actingAs($this->raporcu())->get(self::ADRES.'/excel?'.self::TARIHLER)->assertOk();
        $this->assertStringContainsString('taseron-hakedisleri.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        $sayfa = IOFactory::load($yanit->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame(
            ['Sıra No', 'Proje Adı', 'Para Kodu', 'İmalat', 'Tutanak', 'Hakediş', 'İmalat Kesintisi', 'KDV', 'KDV\'li Hakediş', 'KDV Tevkifatı',
                'Cari Kesinti', 'Yansıtılacak Gider', 'Taşeron Adına Yapılan İş', 'Net Ödenecek', 'Ödeme Talebi Tutarı', 'Ödenen Tutar'],
            $sayfa->rangeToArray('A1:P1')[0],
        );
        $satir = fn (int $no): array => array_map(
            fn (string $kolon): mixed => $sayfa->getCell($kolon.$no)->getValue(),
            ['A', 'B', 'C', 'D', 'P'],
        );
        // Ekrandaki sıra: sayılar sayı gibi (ZONE 7, ZONE 10'dan önce)
        $this->assertEquals([1, 'ZONE 7', 'TL', 10.5, 3], $satir(2));
        $this->assertEquals([2, 'ZONE 10', 'EUR', 7, 0], $satir(3));
        $this->assertEquals([3, 'ZONE 10', 'TL', 2, 0], $satir(4));
        // Para koduna göre TOPLAM satırları, otomatik filtrenin dışında
        $this->assertEquals(['TOPLAM', null, 'EUR', 7, 0], $satir(5));
        $this->assertEquals(['TOPLAM', null, 'TL', 12.5, 3], $satir(6));
        $this->assertSame('A1:P4', $sayfa->getAutoFilter()->getRange());
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
