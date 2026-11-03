<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\User;
use App\Services\Rapor\ErpRaporKaynagi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Raporlar → Gider Yansıtma Faturaları: ERP proc'unun 22 kolonu, fatura
 * başına satır; Excel'de TL genel toplam + para koduna göre Döviz toplamları.
 * Proc sahte kaynakla taklit edilir (ortak akış TaseronHakedisleriRaporTest'te).
 */
final class GiderYansitmaFaturalariRaporTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = '/api/v1/raporlar/gider-yansitma-faturalari';

    private const PARAMETRELER = ['ilk_tarih' => '2026-01-01', 'son_tarih' => '2026-11-03', 'proje_kodu' => '', 'firma_adi' => ''];

    /** @var list<string> çalıştırılan proc'lar */
    private array $procler = [];

    /**
     * @param  list<array<string, mixed>>  $faturalar
     */
    private function erpFaturalari(array $faturalar): void
    {
        $this->app->instance(ErpRaporKaynagi::class, new class($faturalar, $this->procler) implements ErpRaporKaynagi
        {
            /**
             * @param  list<array<string, mixed>>  $faturalar
             * @param  list<string>  $procler
             */
            public function __construct(private readonly array $faturalar, private array &$procler) {}

            public function satirlar(string $proc, int $erpKullaniciId, array $parametreler): array
            {
                $this->procler[] = $proc;

                return $this->faturalar;
            }
        });
    }

    /**
     * Canlıdaki gibi proc satırı (decimal metni, nchar boşluk dolgusu)
     *
     * @param  array<string, string>  $degerler
     * @return array<string, mixed>
     */
    private function fatura(string $no, string $tarih, string $para, array $degerler = []): array
    {
        return [
            'TARIH' => "{$tarih} 00:00:00.000",
            'FATURA_NO' => str_pad($no, 50),
            'UNVAN' => 'MFK KOÇ LTD.ŞTİ',
            'PROJE_KODU' => str_pad('ZONE 4-5 - ANA BÜTÇE', 50),
            'ACIKLAMA' => 'HAKEDİŞE İSTİNADEN YANSITMA BEDELİ',
            'KDV_MATRAHI' => '100.00',
            'KDV_TL' => '20.00',
            'VERGI_TL' => '20.00',
            'TEVKIFAT_TL' => '.00',
            'GENEL_TOPLAM_TL' => '120.00',
            'KESINTI_TOPLAMI_TL' => '.00',
            'NET_TOPLAM_TL' => '120.00',
            'KDV_MATRAHI_DOVIZ' => '100.00',
            'KDV_DOVIZ' => '20.00',
            'VERGI_DOVIZ' => '20.00',
            'TEVKIFAT_DOVIZ' => '.00',
            'GENEL_TOPLAM_DOVIZ' => '120.00',
            'KESINTI_TOPLAMI_DOVIZ' => '.00',
            'NET_TOPLAM_DOVIZ' => '120.00',
            'PARA_KODU' => str_pad($para, 5),
            'KAYIT_EDEN_KULLANICI' => 'ferhat.topcuoglu',
            'GUNCELLEYEN_KULLANICI' => 'umit.ozen',
            ...$degerler,
        ];
    }

    private function raporcu(string $izin = 'gider_yansitma_faturalari.goruntule'): User
    {
        $kullanici = User::factory()->create(['erp_kullanici_id' => 2]);
        $rol = Rol::query()->create(['ad' => 'Raporcu']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => $izin]);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    public function test_fatura_basina_satir_proc_kolonlari_sirasiyla_en_yenisi_once(): void
    {
        $this->erpFaturalari([
            $this->fatura('INS2026000000001', '2026-01-10', 'TL'),
            $this->fatura('INS2026000000003', '2026-02-01', 'USD', ['GENEL_TOPLAM_TL' => '4300.00', 'GENEL_TOPLAM_DOVIZ' => '100.00']),
            $this->fatura('INS2026000000002', '2026-02-01', 'TL'),
        ]);

        $yanit = $this->actingAs($this->raporcu())
            ->getJson(self::ADRES.'?'.http_build_query(self::PARAMETRELER))
            ->assertOk();

        // Tarih yeniden eskiye; aynı günde büyük fatura numarası önce
        $this->assertSame(
            ['INS2026000000003', 'INS2026000000002', 'INS2026000000001'],
            array_column($yanit->json('data'), 'fatura_no'),
        );
        // Proc sırası; Para Kodu Döviz kolonlarının hemen önünde
        $this->assertSame([
            'tarih', 'fatura_no', 'unvan', 'proje_kodu', 'aciklama', 'kdv_matrahi_tl', 'kdv_tl', 'vergi_tl',
            'tevkifat_tl', 'genel_toplam_tl', 'kesinti_toplami_tl', 'net_toplam_tl', 'para_kodu', 'kdv_matrahi_doviz',
            'kdv_doviz', 'vergi_doviz', 'tevkifat_doviz', 'genel_toplam_doviz', 'kesinti_toplami_doviz',
            'net_toplam_doviz', 'kayit_eden', 'guncelleyen',
        ], array_keys($yanit->json('data.0')));
        $yanit->assertJsonPath('data.0.tarih', '2026-02-01')
            ->assertJsonPath('data.0.proje_kodu', 'ZONE 4-5 - ANA BÜTÇE')
            ->assertJsonPath('data.0.para_kodu', 'USD')
            ->assertJsonPath('data.0.genel_toplam_tl', 4300)
            ->assertJsonPath('data.0.genel_toplam_doviz', 100)
            ->assertJsonPath('data.0.kdv_matrahi_tl', 100)
            ->assertJsonPath('data.0.tevkifat_tl', 0)
            ->assertJsonPath('data.0.kayit_eden', 'ferhat.topcuoglu')
            ->assertJsonPath('data.0.guncelleyen', 'umit.ozen');
        $this->assertSame(['dbo.ERP_GIDER_YANSITMA_FATURALARI'], $this->procler);
    }

    public function test_baska_raporun_izni_bu_raporu_acmaz(): void
    {
        $this->erpFaturalari([]);
        $this->actingAs($this->raporcu('taseron_hakedisleri.goruntule'));

        $this->getJson(self::ADRES.'?'.http_build_query(self::PARAMETRELER))
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');
        $this->assertSame([], $this->procler);
    }

    public function test_excel_tl_genel_toplam_ve_para_koduna_gore_doviz_toplamiyla_iner(): void
    {
        $this->erpFaturalari([
            $this->fatura('INS1', '2026-01-10', 'TL'),
            $this->fatura('INS2', '2026-02-01', 'USD', ['GENEL_TOPLAM_TL' => '4300.00', 'GENEL_TOPLAM_DOVIZ' => '100.00']),
            $this->fatura('INS3', '2026-03-01', 'USD', ['GENEL_TOPLAM_TL' => '2150.00', 'GENEL_TOPLAM_DOVIZ' => '50.00']),
            // Ekranda süzülüp dışarıda kalan: Excel'e ve toplamlara girmez
            $this->fatura('INS4', '2026-04-01', 'EUR', ['GENEL_TOPLAM_TL' => '9999.00', 'GENEL_TOPLAM_DOVIZ' => '999.00']),
        ]);

        $yanit = $this->actingAs($this->raporcu())
            ->post(self::ADRES.'/excel', [...self::PARAMETRELER, 'anahtarlar' => ['INS3', 'INS2', 'INS1']])
            ->assertOk();
        $this->assertStringContainsString('gider-yansitma-faturalari.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        $sayfa = IOFactory::load($yanit->baseResponse->getFile()->getPathname())->getActiveSheet();
        $this->assertSame('Gider Yansıtma Faturaları', $sayfa->getTitle());
        $this->assertSame([
            'Sıra No', 'Tarih', 'Fatura No', 'Ünvan', 'Proje Kodu', 'Açıklama', 'KDV Matrahı (TL)', 'KDV (TL)',
            'Vergi (TL)', 'Tevkifat (TL)', 'Genel Toplam (TL)', 'Kesinti Toplamı (TL)', 'Net Toplam (TL)', 'Para Kodu',
            'KDV Matrahı (Döviz)', 'KDV (Döviz)', 'Vergi (Döviz)', 'Tevkifat (Döviz)', 'Genel Toplam (Döviz)',
            'Kesinti Toplamı (Döviz)', 'Net Toplam (Döviz)', 'Kayıt Eden', 'Güncelleyen',
        ], $sayfa->rangeToArray('A1:W1')[0]);

        // Sıra No, Tarih, Fatura No, Genel Toplam (TL), Para Kodu, Genel Toplam (Döviz)
        $satir = fn (int $no): array => array_map(
            fn (string $kolon): mixed => $sayfa->getCell($kolon.$no)->getFormattedValue(),
            ['A', 'B', 'C', 'K', 'N', 'S'],
        );
        $this->assertSame(['1', '01.03.2026', 'INS3', '2,150.00', 'USD', '50.00'], $satir(2));
        $this->assertSame(['3', '10.01.2026', 'INS1', '120.00', 'TL', '120.00'], $satir(4));
        // TL kolonları tek genel toplam; Döviz kolonları para koduna göre
        $this->assertSame(['GENEL TOPLAM (TL)', '', '', '6,570.00', '', ''], $satir(5));
        $this->assertSame(['TOPLAM', '', '', '', 'TL', '120.00'], $satir(6));
        $this->assertSame(['TOPLAM', '', '', '', 'USD', '150.00'], $satir(7));
        $this->assertSame('', $sayfa->getCell('A8')->getFormattedValue());
        $this->assertSame('A1:W4', $sayfa->getAutoFilter()->getRange());
    }
}
