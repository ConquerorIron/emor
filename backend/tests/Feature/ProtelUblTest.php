<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;
use App\Models\EntegratorBaglanti;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Protel UBL verileri (kullanıcı onayı 2026-11-26): fatura başına tek istek,
 * onaylı PDF metodunun dört alanı ve çıktı türü Ubl; İzibiz'le aynı tablolar.
 */
final class ProtelUblTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const GIRIS_URL = 'https://authrestapitest.protel.com.tr/v1/Auth/AccessToken';

    private const PDF_URL = 'https://einvoicerestapitest.protel.com.tr/v1/Document/GetInvoicePdf';

    /** @var list<array<string, mixed>> Protel'e giden belge istekleri */
    private array $istekler = [];

    /** @var array<string, int|string> ETTN => HTTP durumu, 'baska' (başka faturanın XML'i) ya da 'bos' (boş içerik) */
    private array $sorunlar = [];

    /** @var list<int> Sıradaki giriş isteklerinin HTTP durumları; bitince giriş başarılı */
    private array $girisDurumlari = [];

    private function ubl(string $ettn): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            ."<cbc:UUID>{$ettn}</cbc:UUID>"
            .'<cac:AccountingSupplierParty><cac:Party><cac:PartyTaxScheme><cac:TaxScheme><cbc:Name>Beyoğlu</cbc:Name></cac:TaxScheme></cac:PartyTaxScheme></cac:Party></cac:AccountingSupplierParty>'
            .'<cac:TaxTotal><cbc:TaxAmount currencyID="TRY">26.73</cbc:TaxAmount>'
            .'<cac:TaxSubtotal><cbc:TaxableAmount currencyID="TRY">133.65</cbc:TaxableAmount><cbc:TaxAmount currencyID="TRY">26.73</cbc:TaxAmount><cbc:Percent>20</cbc:Percent><cac:TaxCategory><cac:TaxScheme><cbc:Name>KDV</cbc:Name><cbc:TaxTypeCode>0015</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>'
            .'</cac:TaxTotal>'
            .'<cac:WithholdingTaxTotal><cac:TaxSubtotal><cbc:TaxableAmount currencyID="TRY">26.73</cbc:TaxableAmount><cbc:TaxAmount currencyID="TRY">18.71</cbc:TaxAmount><cbc:Percent>70</cbc:Percent><cac:TaxCategory><cac:TaxScheme><cbc:Name>TEMİZLİK</cbc:Name><cbc:TaxTypeCode>613</cbc:TaxTypeCode></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal></cac:WithholdingTaxTotal>'
            .'<cac:LegalMonetaryTotal><cbc:LineExtensionAmount currencyID="TRY">133.65</cbc:LineExtensionAmount><cbc:TaxExclusiveAmount currencyID="TRY">133.65</cbc:TaxExclusiveAmount><cbc:TaxInclusiveAmount currencyID="TRY">160.38</cbc:TaxInclusiveAmount><cbc:AllowanceTotalAmount currencyID="TRY">0</cbc:AllowanceTotalAmount><cbc:PayableAmount currencyID="TRY">141.67</cbc:PayableAmount></cac:LegalMonetaryTotal>'
            .'</Invoice>';
    }

    private function sahteProtel(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) {
            if ($istek->url() === self::GIRIS_URL) {
                $durum = array_shift($this->girisDurumlari) ?? 200;

                return match ($durum) {
                    200 => ProtelIstemcisiTest::girisYaniti(),
                    401 => Http::response(['result' => 'LoginFailed'], 401),
                    default => Http::response(['status' => 'Forbidden'], $durum),
                };
            }
            if ($istek->url() !== self::PDF_URL) {
                return null;
            }

            $this->istekler[] = $istek->data();
            $ettn = (string) $istek->data()['uuid'];
            $sorun = $this->sorunlar[$ettn] ?? null;

            if (is_int($sorun)) {
                return Http::response(['status' => 'NotFound'], $sorun);
            }

            if ($sorun === 'bos') {
                return Http::response(['isSuccess' => false, 'resultMessage' => 'Success', 'outputDatas' => ['']]);
            }

            // Protel düz XML'i base64 verir, BOM'lu olabilir (ölçüm 2026-11-26)
            $xml = "\xEF\xBB\xBF".$this->ubl($sorun === 'baska' ? 'ffffffff-0000-0000-0000-000000000000' : strtoupper($ettn));

            return Http::response(['isSuccess' => false, 'resultMessage' => 'Success', 'outputDatas' => [base64_encode($xml)]]);
        });
    }

    private function fatura(EntegratorBaglanti $tanim, int $no, array $alanlar = []): EFatura
    {
        return EFatura::factory()->protel()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'ettn' => sprintf('cccccccc-0000-0000-0000-%012d', $no),
            ...$alanlar,
        ]);
    }

    public function test_ubl_fatura_basina_onayli_dort_alanla_istenir_kalemler_yazilir_bir_kez_okunur(): void
    {
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $fatura = $this->fatura($tanim, 1);
        $this->sahteProtel();

        $this->artisan('efatura:ubl-oku --saglayici=protel')
            ->expectsOutput('UBL [test] Protel: 1 istek, 1 fatura okundu, 0 okunamadı')
            ->assertSuccessful();

        $this->assertSame([[
            'uuidType' => 'DocumentUUID',
            'uuid' => $fatura->ettn,
            'documentDirection' => 'Incoming',
            'outputType' => 'Ubl',
        ]], $this->istekler);
        $fatura->refresh();
        $this->assertSame(['133.6500', '160.3800', 'Beyoğlu'], [$fatura->vergi_haric_tutar, $fatura->vergi_dahil_tutar, $fatura->satici_vergi_dairesi]);
        $this->assertSame(
            [['vergi', '0015', '26.7300'], ['tevkifat', '613', '18.7100']],
            EFaturaVergiKalemi::query()->where('efatura_id', $fatura->id)->orderBy('sira')->get()
                ->map(fn (EFaturaVergiKalemi $k): array => [$k->tur, $k->vergi_kodu, $k->tutar])->all(),
        );

        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertSuccessful();
        $this->assertCount(1, $this->istekler);
    }

    public function test_faturaya_ozgu_404_ve_baska_faturanin_xmli_okunamadi_sayilir_digerleri_okunur_calisma_sinirli(): void
    {
        config(['efatura.protel_ubl_azami_istek' => 3]);
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $bulunamayan = $this->fatura($tanim, 1);
        $baskasi = $this->fatura($tanim, 2);
        $saglam = $this->fatura($tanim, 3);
        $sinirDisi = $this->fatura($tanim, 4);
        $this->sorunlar = [$bulunamayan->ettn => 404, $baskasi->ettn => 'baska'];
        $this->sahteProtel();

        // En yeniden: 4, 3, 2 — 1 bu çalışmanın sınırı dışında
        $this->artisan('efatura:ubl-oku --saglayici=protel')
            ->expectsOutput('UBL [test] Protel: 3 istek, 2 fatura okundu, 1 okunamadı')
            ->assertSuccessful();

        $this->assertNotNull($sinirDisi->fresh()->ubl_okundu);
        $this->assertNotNull($saglam->fresh()->ubl_okundu);
        $this->assertNull($baskasi->fresh()->ubl_okundu);
        $this->assertSame(1, $baskasi->fresh()->ubl_hata);
        $this->assertSame(0, EFaturaVergiKalemi::query()->where('efatura_id', $baskasi->id)->count());

        $this->artisan('efatura:ubl-oku --saglayici=protel')
            ->expectsOutput('UBL [test] Protel: 1 istek, 0 fatura okundu, 1 okunamadı')
            ->assertSuccessful();
        $this->assertSame(1, $bulunamayan->fresh()->ubl_hata);
    }

    public function test_sunucu_hatasi_turu_durdurur_faturayi_sayacsiz_erteler_sonraki_tur_eski_faturayi_okur(): void
    {
        Sleep::fake();
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        // En yeni (en büyük kimlik) ilk istenir
        $eski = $this->fatura($tanim, 1);
        $ilk = $this->fatura($tanim, 2);
        $this->sorunlar = [$ilk->ettn => 503];
        $this->sahteProtel();

        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertFailed();

        // 5xx'te istemci kısa bir yeniden deneme yapar; ikinci faturaya geçilmez
        $this->assertSame([$ilk->ettn], array_values(array_unique(array_column($this->istekler, 'uuid'))));
        $this->assertSame(0, $ilk->fresh()->ubl_hata);
        $this->assertNotNull($ilk->fresh()->ubl_son_deneme);
        $this->assertSame(0, EFatura::query()->whereNotNull('ubl_okundu')->count());

        // Sorunlu fatura kuyruğun başını kilitlemez
        $this->artisan('efatura:ubl-oku --saglayici=protel')
            ->expectsOutput('UBL [test] Protel: 1 istek, 1 fatura okundu, 0 okunamadı')
            ->assertSuccessful();
        $this->assertNotNull($eski->fresh()->ubl_okundu);
    }

    public function test_404_disindaki_4xx_turu_durdurur_fatura_okunamadi_sayilmaz(): void
    {
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        foreach (range(1, 5) as $no) {
            $this->sorunlar[$this->fatura($tanim, $no)->ettn] = 403;
        }
        $this->sahteProtel();

        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertFailed();

        $this->assertCount(1, $this->istekler);
        $this->assertSame(0, (int) EFatura::query()->sum('ubl_hata'));
    }

    public function test_hic_okunmadan_art_arda_uc_404_turu_keser_hicbir_fatura_isaretlenmez(): void
    {
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        foreach (range(1, 5) as $no) {
            $this->sorunlar[$this->fatura($tanim, $no)->ettn] = 404;
        }
        $this->sahteProtel();

        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertFailed();

        $this->assertCount(3, $this->istekler);
        // Sayaç artmaz ama istenen üç fatura ertelenir: sonraki tur kalan ikisiyle sürer
        $this->assertSame(0, (int) EFatura::query()->sum('ubl_hata'));
        $this->assertSame(3, EFatura::query()->whereNotNull('ubl_son_deneme')->count());

        $this->artisan('efatura:ubl-oku --saglayici=protel');
        $this->assertCount(5, $this->istekler);
    }

    public function test_bos_icerikli_ubl_okunamadi_sayilir_diger_fatura_okunur(): void
    {
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $saglam = $this->fatura($tanim, 1);
        $bos = $this->fatura($tanim, 2);
        $this->sorunlar = [$bos->ettn => 'bos'];
        $this->sahteProtel();

        $this->artisan('efatura:ubl-oku --saglayici=protel')
            ->expectsOutput('UBL [test] Protel: 2 istek, 1 fatura okundu, 1 okunamadı')
            ->assertSuccessful();

        $this->assertSame(1, $bos->fresh()->ubl_hata);
        $this->assertNotNull($saglam->fresh()->ubl_okundu);
    }

    public function test_giris_reddedilirse_tek_giris_istegi_gider_fatura_istenmez_isaretlenmez(): void
    {
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        foreach (range(1, 3) as $no) {
            $this->fatura($tanim, $no);
        }
        $this->girisDurumlari = [403, 403, 403];
        $this->sahteProtel();

        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertFailed();

        $this->assertCount(1, Http::recorded(fn (Request $istek): bool => $istek->url() === self::GIRIS_URL));
        $this->assertSame([], $this->istekler);
        $this->assertSame(0, EFatura::query()->whereNotNull('ubl_son_deneme')->count());
    }

    public function test_kimlik_reddinde_tur_kesilir_sonraki_tur_giris_denemez(): void
    {
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $fatura = $this->fatura($tanim, 1);
        $this->girisDurumlari = [401, 401];
        $this->sahteProtel();

        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertFailed();
        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertFailed();

        // Reddedilen kimlik yeniden denenmez (D8): ikinci turda Protel'e hiç istek yok
        $this->assertCount(1, Http::recorded(fn (Request $istek): bool => $istek->url() === self::GIRIS_URL));
        $this->assertSame([], $this->istekler);
        $this->assertSame(0, $fatura->fresh()->ubl_hata);
    }

    public function test_dongude_token_yenilemesinin_hatasi_faturaya_ozgu_sayilmaz(): void
    {
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $eski = $this->fatura($tanim, 1);
        $ilk = $this->fatura($tanim, 2);
        // Fatura ucu token'ı reddeder; yenileme girişi 404 döner (fatura değil giriş sorunu)
        $this->sorunlar = [$ilk->ettn => 401];
        $this->girisDurumlari = [200, 404];
        $this->sahteProtel();

        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertFailed();

        $this->assertSame(0, $ilk->fresh()->ubl_hata);
        $this->assertNull($ilk->fresh()->ubl_son_deneme);
        $this->assertNull($eski->fresh()->ubl_okundu);
    }

    public function test_protel_modulu_kapaliyken_istek_gitmez(): void
    {
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $this->fatura($tanim, 1);
        $this->sahteProtel();
        config(['entegrator.protel.aktif' => false]);

        $this->artisan('efatura:ubl-oku --saglayici=protel')->assertSuccessful();

        Http::assertNothingSent();
    }
}
