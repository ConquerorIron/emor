<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;
use App\Models\EntegratorBaglanti;
use App\Models\User;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\IzibizIstemcisi;
use App\Services\Entegrator\IzibizUblServisi;
use App\Services\Entegrator\UblOkuyucu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * Gelen faturaların UBL verileri (vergi kalemleri, tutarlar, kur, vergi
 * dairesi, istisna kodu) İzibiz'den toplu indirmeyle, fatura başına bir kez;
 * İzibiz istemcisinin yalnız-okuma kilidi.
 */
final class IzibizUblTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<list<int>> Toplu indirmeye gelen kimlik partileri */
    private array $istenenler = [];

    /** @var list<int> İzibiz'in 10008 ile reddettiği (sorunlu) faturalar */
    private array $sorunlular = [];

    /** @var array<int, string> kaynak_id => UBL */
    private array $ubller = [];

    /** @var array<int, string> kaynak_id => partisinin yanıtı: 'baglanti' (bağlantı hatası), 'bozuk' (zip açılmıyor), '99999' (10008 dışı 400) */
    private array $partiSorunlari = [];

    /** Giriş ucunun HTTP durumu (200 dışı: reddedilir) */
    private int $girisDurumu = 200;

    /** Zipe klasör girdisi ve 0 baytlık dosya da eklenir */
    private bool $bosGirdiler = false;

    /**
     * Gerçek UBL-TR yapısında belge (canlı ölçüm 2026-11-26).
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4?: string}>  $vergiler  [kod, oran, matrah, tutar, istisna kodu]
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $tevkifatlar  [kod, oran, matrah, tutar]
     * @param  array<string, string>  $toplamlar  LegalMonetaryTotal alanları
     */
    private function ubl(string $ettn, array $vergiler = [], array $tevkifatlar = [], array $toplamlar = [], ?string $kur = null, string $vergiDairesi = 'Kadıköy', string $vergiToplami = '0'): string
    {
        $kalem = function (array $v, string $ad): string {
            $istisna = isset($v[4]) ? "<cbc:TaxExemptionReasonCode>{$v[4]}</cbc:TaxExemptionReasonCode><cbc:TaxExemptionReason>{$v[4]} sayılı istisna</cbc:TaxExemptionReason>" : '';

            return "<cac:TaxSubtotal><cbc:TaxableAmount currencyID=\"TRY\">{$v[2]}</cbc:TaxableAmount><cbc:TaxAmount currencyID=\"TRY\">{$v[3]}</cbc:TaxAmount><cbc:Percent>{$v[1]}</cbc:Percent>"
                ."<cac:TaxCategory>{$istisna}<cac:TaxScheme><cbc:Name>{$ad}</cbc:Name><cbc:TaxTypeCode>{$v[0]}</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>";
        };
        $toplamlar = ['LineExtensionAmount' => '100.00', 'TaxExclusiveAmount' => '100.00', 'TaxInclusiveAmount' => '120.00', 'AllowanceTotalAmount' => '0.00', 'PayableAmount' => '120.00', ...$toplamlar];

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            ."<cbc:ID>ABC</cbc:ID><cbc:UUID>{$ettn}</cbc:UUID>"
            // Referans belgenin UUID'i faturanınki sanılmamalı
            .'<cac:DespatchDocumentReference><cbc:UUID>ffffffff-0000-0000-0000-000000000000</cbc:UUID></cac:DespatchDocumentReference>'
            .'<cac:AccountingSupplierParty><cac:Party><cac:PartyTaxScheme><cac:TaxScheme><cbc:Name>'.$vergiDairesi.'</cbc:Name></cac:TaxScheme></cac:PartyTaxScheme></cac:Party></cac:AccountingSupplierParty>'
            .($kur !== null ? "<cac:PricingExchangeRate><cbc:SourceCurrencyCode>USD</cbc:SourceCurrencyCode><cbc:TargetCurrencyCode>TRY</cbc:TargetCurrencyCode><cbc:CalculationRate>{$kur}</cbc:CalculationRate></cac:PricingExchangeRate>" : '')
            ."<cac:TaxTotal><cbc:TaxAmount currencyID=\"TRY\">{$vergiToplami}</cbc:TaxAmount>".implode('', array_map(fn (array $v): string => $kalem($v, 'KDV'), $vergiler)).'</cac:TaxTotal>'
            .($tevkifatlar === [] ? '' : '<cac:WithholdingTaxTotal>'.implode('', array_map(fn (array $v): string => $kalem($v, 'TEVKİFAT'), $tevkifatlar)).'</cac:WithholdingTaxTotal>')
            .'<cac:LegalMonetaryTotal>'.implode('', array_map(fn (string $ad, string $deger): string => "<cbc:{$ad} currencyID=\"TRY\">{$deger}</cbc:{$ad}>", array_keys($toplamlar), $toplamlar)).'</cac:LegalMonetaryTotal>'
            // Satır düzeyi vergi belge toplamına ayrıca eklenmemeli
            .'<cac:InvoiceLine><cac:TaxTotal><cac:TaxSubtotal><cbc:TaxAmount currencyID="TRY">999</cbc:TaxAmount><cac:TaxCategory><cac:TaxScheme><cbc:TaxTypeCode>0015</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal></cac:TaxTotal></cac:InvoiceLine>'
            .'</Invoice>';
    }

    /** İzibiz sahtesi: toplu indirme isteklerini kaydeder, partideki UBL'leri zipler. */
    private function sahteIzibiz(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) {
            if (str_ends_with($istek->url(), '/v1/auth/token')) {
                return $this->girisDurumu === 200
                    ? Http::response(['data' => ['accessToken' => 't', 'validity' => '2099-01-01 00:00:00', 'customerType' => 'C'], 'error' => null])
                    : Http::response(['data' => null, 'error' => ['code' => '10008', 'message' => 'Beklenmedik bir hata']], $this->girisDurumu);
            }

            if (! str_ends_with($istek->url(), '/v1/einvoices/inbox/download/ubl') || $istek->method() !== 'POST') {
                return null;
            }

            $idler = array_column($istek->data(), 'id');
            $this->istenenler[] = $idler;
            $sorun = array_values(array_intersect_key($this->partiSorunlari, array_flip($idler)))[0] ?? null;

            if ($sorun === 'baglanti') {
                return Http::failedConnection()($istek);
            }

            if ($sorun === 'bozuk') {
                return Http::response(['data' => ['filename' => 'x.zip', 'content' => base64_encode('PK-zip-degil')], 'error' => null]);
            }

            if ($sorun === '99999') {
                return Http::response(['data' => null, 'error' => ['code' => '99999', 'message' => 'Geçersiz istek']], 400);
            }

            if (array_intersect($idler, $this->sorunlular) !== []) {
                return Http::response(['data' => null, 'error' => ['code' => '10008', 'message' => 'Beklenmedik bir hata']], 400);
            }

            return Http::response(['data' => ['filename' => 'x.zip', 'content' => base64_encode($this->zip(array_intersect_key($this->ubller, array_flip($idler))))], 'error' => null]);
        });
    }

    /**
     * @param  array<int, string>  $ubller  kaynak_id => UBL
     */
    private function zip(array $ubller): string
    {
        $yol = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($yol, ZipArchive::OVERWRITE);
        if ($this->bosGirdiler) {
            $zip->addEmptyDir('faturalar');
            $zip->addFromString('faturalar/bos.xml', '');
        }
        foreach ($ubller as $id => $ubl) {
            $zip->addFromString("F{$id}.xml", $ubl);
        }
        $zip->close();
        $icerik = (string) file_get_contents($yol);
        unlink($yol);

        return $icerik;
    }

    /**
     * @param  array<string, mixed>  $alanlar
     */
    private function fatura(EntegratorBaglanti $tanim, int $kaynakId, array $alanlar = [], ?string $ubl = null): EFatura
    {
        $ettn = sprintf('aaaaaaaa-0000-0000-0000-%012d', $kaynakId);
        $this->ubller[$kaynakId] = $ubl ?? $this->ubl(strtoupper($ettn), [['0015', '20', '100.00', '20.00']]);

        return EFatura::factory()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'kaynak_id' => $kaynakId,
            'ettn' => $ettn,
            ...$alanlar,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function kalemler(EFatura $fatura): array
    {
        return EFaturaVergiKalemi::query()->where('efatura_id', $fatura->id)->orderBy('sira')
            ->get(['tur', 'vergi_kodu', 'oran', 'matrah', 'tutar', 'istisna_kodu'])
            ->map(fn (EFaturaVergiKalemi $k): array => $k->only(['tur', 'vergi_kodu', 'oran', 'matrah', 'tutar', 'istisna_kodu']))
            ->all();
    }

    public function test_ubl_verileri_toplu_okunur_vergi_kalemleri_tutarlar_ve_kur_yazilir_fatura_bir_kez_istenir(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        // İki oran; sıfır oranlı kalemde istisna (gerçek EUR örneğinin yapısı)
        $ikiOranli = $this->fatura($tanim, 101, [], $this->ubl(
            'AAAAAAAA-0000-0000-0000-000000000101',
            [['0015', '0.00', '3.23', '0.00', '305'], ['0015', '20.00', '284.15', '56.83']],
            toplamlar: ['LineExtensionAmount' => '297.38', 'AllowanceTotalAmount' => '10.00', 'TaxExclusiveAmount' => '287.38', 'TaxInclusiveAmount' => '344.21', 'PayableAmount' => '344.21'],
            vergiToplami: '56.83',
        ));
        // Tevkifatlı dövizli: belge vergi toplamına NET KDV yazılmış (kimi satıcı böyle yazıyor)
        $tevkifatli = $this->fatura($tanim, 102, ['para_birimi' => 'USD'], $this->ubl(
            'AAAAAAAA-0000-0000-0000-000000000102',
            [['0015', '20.00', '4321527.88', '864305.58']],
            [['607', '90', '864305.58', '777875.01']],
            ['LineExtensionAmount' => '4321527.88', 'TaxExclusiveAmount' => '4321527.88', 'TaxInclusiveAmount' => '5185833.46', 'PayableAmount' => '4407958.45'],
            kur: '43.9846',
            vergiToplami: '86430.57',
        ));
        // TRY faturada kur 0.000000 gelir: kur yok sayılır
        $tryKurSifir = $this->fatura($tanim, 103, [], $this->ubl('AAAAAAAA-0000-0000-0000-000000000103', [['0015', '20', '100.00', '20.00']], kur: '0.000000', vergiDairesi: ''));
        // Aday değil: giden fatura
        $giden = $this->fatura($tanim, 104, ['yon' => 'giden']);
        $this->sahteIzibiz();

        $this->artisan('efatura:ubl-oku')
            ->expectsOutput('UBL [test] İzibiz: 1 istek, 3 fatura okundu, 0 okunamadı')
            ->assertSuccessful();

        $this->assertSame([[103, 102, 101]], $this->istenenler);

        $this->assertSame([
            ['tur' => 'vergi', 'vergi_kodu' => '0015', 'oran' => '0.0000', 'matrah' => '3.2300', 'tutar' => '0.0000', 'istisna_kodu' => '305'],
            ['tur' => 'vergi', 'vergi_kodu' => '0015', 'oran' => '20.0000', 'matrah' => '284.1500', 'tutar' => '56.8300', 'istisna_kodu' => null],
        ], $this->kalemler($ikiOranli));
        $ikiOranli->refresh();
        $this->assertSame(['297.3800', '10.0000', '287.3800', '344.2100', null, 'Kadıköy', '305'], [
            $ikiOranli->satir_toplami, $ikiOranli->indirim_tutari, $ikiOranli->vergi_haric_tutar, $ikiOranli->vergi_dahil_tutar,
            $ikiOranli->kur, $ikiOranli->satici_vergi_dairesi, $ikiOranli->izibiz_istisna_kodu,
        ]);
        $this->assertNotNull($ikiOranli->ubl_okundu);

        $this->assertSame([
            ['tur' => 'vergi', 'vergi_kodu' => '0015', 'oran' => '20.0000', 'matrah' => '4321527.8800', 'tutar' => '864305.5800', 'istisna_kodu' => null],
            ['tur' => 'tevkifat', 'vergi_kodu' => '607', 'oran' => '90.0000', 'matrah' => '864305.5800', 'tutar' => '777875.0100', 'istisna_kodu' => null],
        ], $this->kalemler($tevkifatli));
        $this->assertSame('43.984600', $tevkifatli->fresh()->kur);

        $tryKurSifir->refresh();
        $this->assertNull($tryKurSifir->kur);
        $this->assertNull($tryKurSifir->satici_vergi_dairesi);
        $this->assertNull($giden->fresh()->ubl_okundu);

        // Okunmuş fatura bir daha istenmez
        $this->artisan('efatura:ubl-oku')->assertSuccessful();
        $this->assertCount(1, $this->istenenler);
    }

    public function test_gecersiz_ya_da_kolona_sigmayan_tutarli_ubl_yazilmaz_digerleri_okunur_ve_yuvarlanir(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        // 5. kesir basamağı yarım yukarı yuvarlanır
        $yuvarlanan = $this->fatura($tanim, 201, [], $this->ubl('AAAAAAAA-0000-0000-0000-000000000201', [['0015', '20', '1234.56785', '10.12345']]));
        // 17 tam basamak numeric(20,4)'e sığmaz: yarım veri yazılmaz
        $tasan = $this->fatura($tanim, 202, [], $this->ubl('AAAAAAAA-0000-0000-0000-000000000202', [['0015', '20', '10000000000000000', '20.00']]));
        // Türkçe biçimli tutar xs:decimal değildir
        $bicimDisi = $this->fatura($tanim, 203, [], $this->ubl('AAAAAAAA-0000-0000-0000-000000000203', [['0015', '20', '100.00', '20.00']], toplamlar: ['PayableAmount' => '1.234,50', 'TaxInclusiveAmount' => '1.234,50']));
        $this->sahteIzibiz();

        $this->artisan('efatura:ubl-oku')
            ->expectsOutput('UBL [test] İzibiz: 1 istek, 1 fatura okundu, 2 okunamadı')
            ->assertSuccessful();

        $this->assertSame([
            ['tur' => 'vergi', 'vergi_kodu' => '0015', 'oran' => '20.0000', 'matrah' => '1234.5679', 'tutar' => '10.1235', 'istisna_kodu' => null],
        ], $this->kalemler($yuvarlanan));

        // Sınırın iç yanı: 16 tam basamak geçerli (SQLite numeric'i float saklar; sınır okuyucuda sınanır)
        $sinirda = app(UblOkuyucu::class)->oku($this->ubl('AAAAAAAA-0000-0000-0000-000000000204', [['0015', '20', '9999999999999999.99994', '1']]));
        $this->assertSame('9999999999999999.9999', $sinirda?->kalemler[0]['matrah']);

        foreach ([$tasan, $bicimDisi] as $fatura) {
            $fatura->refresh();
            $this->assertSame([], $this->kalemler($fatura));
            $this->assertNull($fatura->ubl_okundu);
            $this->assertNull($fatura->vergi_dahil_tutar);
            $this->assertSame(1, $fatura->ubl_hata);
        }
    }

    public function test_sorunlu_fatura_partiyi_bozunca_bolunur_digerleri_okunur(): void
    {
        config(['efatura.ubl_parti_boyutu' => 4, 'efatura.ubl_azami_istek' => 10]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        foreach ([301, 302, 303, 304] as $id) {
            $this->fatura($tanim, $id);
        }
        $this->sorunlular = [303];
        $this->sahteIzibiz();

        $this->artisan('efatura:ubl-oku')
            ->expectsOutput('UBL [test] İzibiz: 5 istek, 3 fatura okundu, 1 okunamadı')
            ->assertSuccessful();

        // [304,303,302,301] → [304,303] + [302,301] → [304] + [303]
        $this->assertSame([[304, 303, 302, 301], [304, 303], [304], [303], [302, 301]], $this->istenenler);
        $sorunlu = EFatura::query()->where('kaynak_id', 303)->firstOrFail();
        $this->assertNull($sorunlu->ubl_okundu);
        $this->assertSame(1, $sorunlu->ubl_hata);

        // Okunamayan fatura hemen yeniden istenmez (bir gün sonra)
        $this->artisan('efatura:ubl-oku')->assertSuccessful();
        $this->assertCount(5, $this->istenenler);

        $this->travel(25)->hours();
        $this->artisan('efatura:ubl-oku')->assertSuccessful();
        $this->assertSame([303], $this->istenenler[5]);
    }

    public function test_calisma_basina_istek_sayisi_sinirlidir(): void
    {
        config(['efatura.ubl_parti_boyutu' => 2, 'efatura.ubl_azami_istek' => 2]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        foreach (range(401, 406) as $id) {
            $this->fatura($tanim, $id);
        }
        $this->sahteIzibiz();

        $this->artisan('efatura:ubl-oku')->assertSuccessful();

        $this->assertCount(2, $this->istenenler);
        $this->assertSame(4, EFatura::query()->whereNotNull('ubl_okundu')->count());
    }

    public function test_doctypeli_ubl_okunmaz(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE Invoice [<!ENTITY x "318">]>'
            .substr($this->ubl('AAAAAAAA-0000-0000-0000-000000000701', [['0015', '0', '1', '0', '318']]), strlen('<?xml version="1.0" encoding="UTF-8"?>'));

        $this->assertSame([], app(IzibizUblServisi::class)->ayikla($this->zip([701 => $xml])));
    }

    public function test_istisna_kodlari_kolon_sinirinda_kod_ortasindan_bolunmez(): void
    {
        // "35" + 24 × ",3xx" = 98 karakter; 25. kod 100. karakterde bölünürdü
        $kodlar = ['35', ...array_map('strval', range(301, 330))];
        $vergiler = array_map(fn (string $kod): array => ['0015', '0', '1', '0', $kod], $kodlar);

        $veri = app(UblOkuyucu::class)->oku($this->ubl('AAAAAAAA-0000-0000-0000-000000000801', $vergiler));

        $this->assertSame(implode(',', array_slice($kodlar, 0, 25)), $veri?->istisnaKodlari);
    }

    public function test_kur_yalniz_belge_para_biriminden_tryye_ise_okunur(): void
    {
        $okuyucu = app(UblOkuyucu::class);
        $ubl = fn (string $kaynak, string $hedef): string => str_replace(
            ['<cbc:ID>ABC</cbc:ID>', '<cbc:SourceCurrencyCode>USD</cbc:SourceCurrencyCode><cbc:TargetCurrencyCode>TRY</cbc:TargetCurrencyCode>'],
            ['<cbc:ID>ABC</cbc:ID><cbc:DocumentCurrencyCode>USD</cbc:DocumentCurrencyCode>', "<cbc:SourceCurrencyCode>{$kaynak}</cbc:SourceCurrencyCode><cbc:TargetCurrencyCode>{$hedef}</cbc:TargetCurrencyCode>"],
            $this->ubl('AAAAAAAA-0000-0000-0000-000000000802', [['0015', '20', '100.00', '20.00']], kur: '43.9846'),
        );

        $this->assertSame('43.984600', $okuyucu->oku($ubl('USD', 'TRY'))?->kur);
        // Ters yönlü (TRY → döviz) ve belge para biriminden farklı kaynaklı kur yazılmaz; fatura yine okunur
        $tersYonlu = $okuyucu->oku($ubl('TRY', 'USD'));
        $this->assertNotNull($tersYonlu);
        $this->assertNull($tersYonlu->kur);
        $this->assertNull($okuyucu->oku($ubl('EUR', 'TRY'))?->kur);
    }

    public function test_klasor_ve_bos_dosya_girdili_zipte_diger_ubl_okunur(): void
    {
        $this->bosGirdiler = true;

        $veriler = app(IzibizUblServisi::class)->ayikla($this->zip([901 => $this->ubl('AAAAAAAA-0000-0000-0000-000000000901', [['0015', '20', '100.00', '20.00']])]));

        $this->assertSame(['aaaaaaaa-0000-0000-0000-000000000901'], array_keys($veriler));
    }

    public function test_10008_disindaki_400_partiyi_bolmez_turu_durdurur_sayac_artmaz(): void
    {
        config(['efatura.ubl_parti_boyutu' => 4]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        foreach ([501, 502, 503, 504] as $id) {
            $this->fatura($tanim, $id);
        }
        $this->partiSorunlari = [502 => '99999'];
        $this->sahteIzibiz();

        $this->artisan('efatura:ubl-oku')->assertFailed();

        $this->assertSame([[504, 503, 502, 501]], $this->istenenler);
        $this->assertSame(0, (int) EFatura::query()->sum('ubl_hata'));
        $this->assertSame(0, EFatura::query()->whereNotNull('ubl_okundu')->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function partiyeBagliErisimHatalari(): array
    {
        return [
            'bağlantı hatası' => ['baglanti'],
            'açılmayan zip' => ['bozuk'],
        ];
    }

    #[DataProvider('partiyeBagliErisimHatalari')]
    public function test_erisim_hatasi_partiyi_erteler_sayac_artmaz_sonraki_tur_eski_partiyi_okur(string $sorun): void
    {
        Sleep::fake();
        config(['efatura.ubl_parti_boyutu' => 2]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        foreach ([601, 602, 603, 604] as $id) {
            $this->fatura($tanim, $id);
        }
        $this->partiSorunlari = [604 => $sorun];
        $this->sahteIzibiz();

        $this->artisan('efatura:ubl-oku')->assertFailed();

        $this->assertSame([604, 603], end($this->istenenler));
        $this->assertSame(0, (int) EFatura::query()->sum('ubl_hata'));
        $this->assertSame(2, EFatura::query()->whereNotNull('ubl_son_deneme')->count());

        // Sorunlu parti kuyruğun başını kilitlemez: sonraki tur eski partiyle sürer
        $this->artisan('efatura:ubl-oku')
            ->expectsOutput('UBL [test] İzibiz: 1 istek, 2 fatura okundu, 0 okunamadı')
            ->assertSuccessful();
        $this->assertSame([602, 601], end($this->istenenler));
    }

    public function test_hic_okunmadan_art_arda_uc_okunamayan_fatura_turu_keser_isaretlenmez(): void
    {
        config(['efatura.ubl_parti_boyutu' => 5]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        foreach (range(951, 955) as $id) {
            // Belge biçimi değişmiş gibi: hiçbiri okunamıyor (sistemik)
            $this->fatura($tanim, $id, [], '<Invoice/>');
        }
        $this->sahteIzibiz();

        $this->artisan('efatura:ubl-oku')->assertFailed();

        // Sayaç artmaz (kalıcı bırakılmaz) ama ertelenir: sonraki tur aynı faturalarda takılmaz
        $this->assertSame(0, (int) EFatura::query()->sum('ubl_hata'));
        $this->assertSame(5, EFatura::query()->whereNotNull('ubl_son_deneme')->count());
    }

    public function test_uc_basarisiz_gunden_sonra_fatura_istenmez_gunluge_yazilir_yeniden_dene_ile_yine_okunur(): void
    {
        Log::spy();
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $sorunlu = $this->fatura($tanim, 971);
        // --yeniden-dene yalnız seçilen sağlayıcının gelen, okunmamış faturalarına dokunur
        $giden = $this->fatura($tanim, 972, ['yon' => 'giden', 'ubl_hata' => 3]);
        $protelinki = EFatura::factory()->protel()->create(['ubl_hata' => 3]);
        $this->sorunlular = [971];
        $this->sahteIzibiz();

        foreach ([1, 2, 3] as $gun) {
            $this->artisan('efatura:ubl-oku')->assertSuccessful();
            $this->assertSame($gun, $sorunlu->fresh()->ubl_hata);
            $this->travel(25)->hours();
        }
        $this->artisan('efatura:ubl-oku')->assertSuccessful();

        $this->assertCount(3, $this->istenenler);
        Log::shouldHaveReceived('warning')
            ->with('UBL okunamadı, fatura bırakıldı (yeniden denemek için: efatura:ubl-oku --yeniden-dene)', ['fatura_idleri' => [$sorunlu->id]])
            ->once();

        $this->sorunlular = [];
        $this->artisan('efatura:ubl-oku --yeniden-dene')
            ->expectsOutput('UBL [test] İzibiz: 1 faturanın okuma sayacı sıfırlandı')
            ->expectsOutput('UBL [test] İzibiz: 1 istek, 1 fatura okundu, 0 okunamadı')
            ->assertSuccessful();

        $this->assertNotNull($sorunlu->fresh()->ubl_okundu);
        $this->assertSame(3, $giden->fresh()->ubl_hata);
        $this->assertSame(3, $protelinki->fresh()->ubl_hata);
    }

    public function test_giris_reddedilirse_tek_giris_istegi_gider_hicbir_fatura_isaretlenmez(): void
    {
        config(['efatura.ubl_parti_boyutu' => 2]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        foreach ([981, 982, 983] as $id) {
            $this->fatura($tanim, $id);
        }
        $this->girisDurumu = 400;
        $this->sahteIzibiz();

        $this->artisan('efatura:ubl-oku')->assertFailed();

        $this->assertCount(1, Http::recorded(fn (Request $istek): bool => str_ends_with($istek->url(), '/v1/auth/token')));
        $this->assertSame([], $this->istenenler);
        $this->assertSame(0, EFatura::query()->whereNotNull('ubl_son_deneme')->count());
    }

    public function test_okundu_isaretleme_ve_yanit_uclari_istemciden_cagrilamaz(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        Http::preventStrayRequests();
        Http::fake();
        $istemci = app(IzibizIstemcisi::class);

        foreach (['/v1/einvoices/inbox/erp-read-flag/true', '/v1/einvoices/inbox/portal-read-flag/true', '/v1/einvoices/inbox/5/response'] as $yol) {
            try {
                $istemci->getJson($tanim, $yol);
                $this->fail("Engellenmedi: {$yol}");
            } catch (InvalidArgumentException) {
                // beklenen
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $istemci->ublIndir($tanim, FaturaYonu::Gelen, range(1, 101));
    }

    public function test_liste_iki_kaynagin_istisna_kodunu_doner_filtre_ikisinde_arar(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $izibizde = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'izibiz_istisna_kodu' => '308,351', 'vergi_istisna_kodu' => '318', 'belge_no' => 'A1']);
        $erpde = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'vergi_istisna_kodu' => '350', 'belge_no' => 'A2']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'belge_no' => 'A3']);
        $kullanici = User::factory()->yonetici()->create();
        $idler = fn (string $sorgu): array => array_column(
            $this->actingAs($kullanici)->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31&sirala=belge_no&yon=asc&'.$sorgu)->json('data'),
            'id',
        );

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31&sirala=belge_no&yon=asc')
            ->assertJsonPath('data.0.izibiz_istisna_kodu', '308,351')
            ->assertJsonPath('data.0.vergi_istisna_kodu', '318')
            ->assertJsonPath('secenekler.istisna_kodlari', ['308', '318', '350', '351']);

        $this->assertSame([$izibizde->id], $idler('istisna_kodu=351'));
        $this->assertSame([$erpde->id], $idler('istisna_kodu=350'));
        $this->assertSame([$izibizde->id, $erpde->id], $idler('istisnali=evet'));
    }
}
