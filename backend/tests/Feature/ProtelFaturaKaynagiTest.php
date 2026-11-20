<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EntegratorFatura;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaOkumaSonucu;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\ProtelFaturaKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PRT-07 / PRT-08: Protel gelen fatura listesinin okunması ve senkronu.
 */
final class ProtelFaturaKaynagiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const GIRIS_URL = 'https://authrestapitest.protel.com.tr/v1/Auth/AccessToken';

    private const LISTE_URL = 'https://einvoicerestapitest.protel.com.tr/v1/Document/GetInvoiceList';

    /**
     * PRT-01'de alınan gerçek kaydın biçimi (değerler sentetik). En uzun
     * unvan (111 karakter) ve Türkçe karakter G4 gereği.
     *
     * @return array<string, mixed>
     */
    private function kayit(array $degisen = []): array
    {
        return [
            'id' => 12236857,
            'invoiceUUID' => 'C14FFE5C-F9E9-42D9-AD5E-C8C84D592E84',
            'profileId' => 'TEMELFATURA',
            'documentId' => 'ABC2026000000401',
            'erpReferenceId' => null,
            'createAt' => '2026-05-29T18:48:37.402647',
            'issueDate' => '2026-05-25T00:00:00',
            'issueTime' => '2026-05-25T14:05:09',
            'documentType' => 'SATIS',
            'documentCurrencyCode' => 'TRY',
            'supplierId' => '0123456789',
            'supplierTitle' => mb_str_pad('Şişli İletişim Hizmetleri A.Ş.', 111, 'Ğ'),
            'supplierAlias' => 'urn:mail:defaultgb@ornek.test',
            'customerId' => '1234567890',
            'customerTitle' => 'Deneme Otel A.Ş.',
            'customerAlias' => 'urn:mail:defaultpk@ornek.test',
            'taxTotal' => 401.98,
            'payableAmount' => 1786.0,
            'isRead' => 0,
            'isPrinted' => 1,
            'erpStatus' => 'Booked',
            'status' => 'AcceptAnswerCompleted',
            'statusEnumValue' => 100,
            'statusDescription' => 'Kabul Edildi',
            'gibStatus' => '1200',
            'gibStatusMessage' => 'ZARF_BASARIYLA_ISLENDI',
            'isErpRecieved' => 1,
            'lineCount' => 4,
            'appRespMessage' => null,
            'despatchId' => 'IRS2026000000007',
            'gtB_REFNO' => null,
            'gtB_GCB_TESCILNO' => null,
            'gtB_FIILI_IHRACAT_TARIHI' => null,
            ...$degisen,
        ];
    }

    private function tanim(): EntegratorBaglanti
    {
        return EntegratorBaglanti::factory()->protel()->aktif()->create();
    }

    /**
     * Ham JSON gövdesi kurulur: `'@ham:…'` değerleri tırnaksız JSON sayısı
     * olarak yazılır. PHP float'ının kaybettiği basamaklar ancak böyle
     * sınanır (PRT-R8).
     *
     * @param  list<array<string, mixed>>  $kayitlar
     */
    private function protelYaniti(array $kayitlar): void
    {
        // Başarılı yanıtta da isSuccess=false geliyor (PRT-01)
        $govde = (string) preg_replace(
            '/"@ham:([^"]+)"/',
            '$1',
            json_encode(['invoiceList' => $kayitlar, 'status' => 'Success', 'isSuccess' => false], JSON_THROW_ON_ERROR),
        );

        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => ProtelIstemcisiTest::girisYaniti(),
            self::LISTE_URL => Http::response($govde, 200, ['Content-Type' => 'application/json']),
        ]);
    }

    private function oku(EntegratorBaglanti $tanim, string $tarihTuru = ProtelFaturaKaynagi::TARIH_BELGE): FaturaOkumaSonucu
    {
        return app(ProtelFaturaKaynagi::class)->oku(
            $tanim,
            FaturaYonu::Gelen,
            CarbonImmutable::parse('2026-05-01'),
            CarbonImmutable::parse('2026-05-31'),
            $tarihTuru,
        );
    }

    public function test_kayit_ortak_bicime_eksiksiz_cevrilir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->protelYaniti([$this->kayit()]);

        $sonuc = $this->oku($this->tanim());

        $this->assertTrue($sonuc->tam());
        $f = $sonuc->faturalar[0];
        $this->assertSame(12236857, $f->kaynakId);
        $this->assertSame('c14ffe5c-f9e9-42d9-ad5e-c8c84d592e84', $f->ettn);
        $this->assertSame('ABC2026000000401', $f->belgeNo);
        $this->assertSame('2026-05-25', $f->belgeTarihi);
        $this->assertSame('14:05:09', $f->belgeSaati);
        $this->assertSame('2026-05-29T18:48:37', $f->olusturmaZamani);
        $this->assertSame('1786', $f->tutar);
        $this->assertSame('401.98', $f->vergiTutari);
        $this->assertSame(111, mb_strlen((string) $f->gondericiUnvan));
        $this->assertSame('AcceptAnswerCompleted', $f->durum);
        $this->assertSame('Kabul Edildi', $f->durumAciklamasi);
        $this->assertSame(1200, $f->gibDurumKodu);
        // isErpRecieved otelin ERP'sidir, eMOR'un okuması değil
        $this->assertNull($f->erpOkundu);
        $this->assertTrue($f->otelErpOkundu);
        $this->assertFalse($f->okundu);
        $this->assertSame('Booked', $f->erpDurumu);
        $this->assertTrue($f->yazdirildi);
        $this->assertSame('IRS2026000000007', $f->irsaliyeNo);
    }

    public function test_tarih_turu_protelin_suzgecine_cevrilir_ve_tum_durumlar_istenir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->protelYaniti([]);

        $this->oku($this->tanim(), ProtelFaturaKaynagi::TARIH_ULASMA);

        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::LISTE_URL && $istek->data() === [
            'startDate' => '2026-05-01T00:00:00',
            'endDate' => '2026-05-31T23:59:59',
            'filterDateType' => 'CreateDate',
            'documentDirection' => 'Incoming',
            'erpRecievedStatus' => 'All',
            'erpStatus' => 'All',
        ]);
    }

    public function test_bozuk_kayit_dusurulmez_okuma_eksik_sayilir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->protelYaniti([
            $this->kayit(),
            $this->kayit(['id' => 2, 'issueDate' => '2026-02-30T00:00:00', 'payableAmount' => 'bin']),
        ]);

        $sonuc = $this->oku($this->tanim());

        $this->assertCount(1, $sonuc->faturalar);
        $this->assertSame(FaturaOkumaSonucu::EKSIK_VERI_HATASI, $sonuc->eksikNedeni);
        $this->assertSame(['kaynak_id' => 2, 'alanlar' => ['issueDate', 'payableAmount']], $sonuc->hataliKayitlar[0]);
    }

    /**
     * Tutar ve KDV float'a çevrilmeden okunur (PRT-R8): 16 tam basamak ve
     * kuruş aynen kalır; 4 basamağa yarım sıfırdan uzağa yuvarlanır;
     * yuvarlamayla 17 basamağa taşan değer hatalı kayıttır.
     */
    public function test_tutar_ve_kdv_ondalik_hassasiyetiyle_okunur_tasan_deger_hatali_kayittir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->protelYaniti([
            $this->kayit(['id' => 1, 'payableAmount' => '@ham:1234567890123456.23', 'taxTotal' => '@ham:-9999999999999999.9999']),
            // dize içindeki sayılar ve kaçışlar olduğu gibi kalır
            $this->kayit(['id' => 2, 'payableAmount' => '@ham:12.34565', 'taxTotal' => '@ham:-0.00005', 'supplierTitle' => 'Kat "12.5" \\ 3e2 Ltd.']),
            $this->kayit(['id' => 3, 'payableAmount' => '@ham:9999999999999999.99995']),
        ]);

        $sonuc = $this->oku($this->tanim());

        $this->assertSame(
            [[1, '1234567890123456.23', '-9999999999999999.9999'], [2, '12.3457', '-0.0001']],
            array_map(fn (EntegratorFatura $f): array => [$f->kaynakId, $f->tutar, $f->vergiTutari], $sonuc->faturalar),
        );
        $this->assertSame('Kat "12.5" \\ 3e2 Ltd.', $sonuc->faturalar[1]->gondericiUnvan);
        $this->assertSame([['kaynak_id' => 3, 'alanlar' => ['payableAmount']]], $sonuc->hataliKayitlar);
    }

    /**
     * Hassas okuma alanın JSON türünü değiştirmez (PRT-R12): sayısal fatura
     * numarası metin, tırnaklı tutar / KDV sayı sayılmaz — hatalı kayıttır.
     */
    public function test_sayisal_fatura_no_ve_tirnakli_tutar_kdv_hatali_kayittir_gecerli_kayit_hassas_okunur(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->protelYaniti([
            $this->kayit(['id' => 1, 'payableAmount' => '@ham:1234567890123456.23']),
            $this->kayit(['id' => 2, 'documentId' => '@ham:123.45']),
            $this->kayit(['id' => 3, 'documentId' => '@ham:12345']),
            $this->kayit(['id' => 4, 'payableAmount' => '0']),
            $this->kayit(['id' => 5, 'taxTotal' => '12.5']),
        ]);

        $sonuc = $this->oku($this->tanim());

        $this->assertSame([[1, '1234567890123456.23']], array_map(
            fn (EntegratorFatura $f): array => [$f->kaynakId, $f->tutar],
            $sonuc->faturalar,
        ));
        $this->assertSame(FaturaOkumaSonucu::EKSIK_VERI_HATASI, $sonuc->eksikNedeni);
        $this->assertSame([
            ['kaynak_id' => 2, 'alanlar' => ['documentId']],
            ['kaynak_id' => 3, 'alanlar' => ['documentId']],
            ['kaynak_id' => 4, 'alanlar' => ['payableAmount']],
            ['kaynak_id' => 5, 'alanlar' => ['taxTotal']],
        ], $sonuc->hataliKayitlar);
    }

    /**
     * Üslü JSON sayısı float'a uğramadan düz ondalığa çevrilir (PRT-R13):
     * `1.5e3` düz `1500.0` ile aynı; küçük değer 4 basamakta sıfır; yuvarlamadan
     * sonra 16 tam basamağı aşan ya da sonsuza giden değer hatalı kayıttır.
     */
    public function test_uslu_tutar_duz_ondalikla_ayni_okunur_sigmayan_hatali_kayittir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->protelYaniti([
            $this->kayit(['id' => 1, 'payableAmount' => '@ham:1.5e3', 'taxTotal' => '@ham:1500.0']),
            $this->kayit(['id' => 2, 'payableAmount' => '@ham:1.234567890123456789E+15', 'taxTotal' => '@ham:-2.5E-3']),
            $this->kayit(['id' => 3, 'payableAmount' => '@ham:5e-5', 'taxTotal' => '@ham:-4.9e-5']),
            $this->kayit(['id' => 4, 'payableAmount' => '@ham:1e-400', 'taxTotal' => '@ham:0e99999']),
            $this->kayit(['id' => 5, 'payableAmount' => '@ham:9.9999999999999999E15', 'taxTotal' => null]),
            $this->kayit(['id' => 6, 'payableAmount' => '@ham:1e16']),
            $this->kayit(['id' => 7, 'payableAmount' => '@ham:9.99999999999999999999e15']),
            $this->kayit(['id' => 8, 'payableAmount' => '@ham:1e400']),
        ]);

        $sonuc = $this->oku($this->tanim());

        $this->assertSame([
            [1, '1500', '1500'],
            [2, '1234567890123456.789', '-0.0025'],
            [3, '0.0001', '0'],
            [4, '0', '0'],
            [5, '9999999999999999.9', null],
        ], array_map(
            fn (EntegratorFatura $f): array => [$f->kaynakId, $f->tutar, $f->vergiTutari],
            $sonuc->faturalar,
        ));
        $this->assertSame([
            ['kaynak_id' => 6, 'alanlar' => ['payableAmount']],
            ['kaynak_id' => 7, 'alanlar' => ['payableAmount']],
            ['kaynak_id' => 8, 'alanlar' => ['payableAmount']],
        ], $sonuc->hataliKayitlar);
    }

    /**
     * Virgülün yerini üs ile katsayının basamakları birlikte belirler
     * (PRT-R15): beş haneli üs uzun katsayıyla dengelenebilir. Değeri 1 olan
     * iki gösterim 1, kolona sığmayan eşdeğerleri hatalı kayıttır.
     */
    public function test_uzun_katsayiyla_dengelenen_buyuk_us_gercek_degeriyle_okunur(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $sifirlar = str_repeat('0', 10000);
        $this->protelYaniti([
            $this->kayit(['id' => 1, 'payableAmount' => '@ham:0.'.substr($sifirlar, 1).'1e10000', 'taxTotal' => '@ham:1'.$sifirlar.'e-10000']),
            $this->kayit(['id' => 2, 'payableAmount' => '@ham:0.'.substr($sifirlar, 1).'1e10017']),
            $this->kayit(['id' => 3, 'payableAmount' => '@ham:1'.$sifirlar.'0000000000000000e-10000']),
        ]);

        $sonuc = $this->oku($this->tanim());

        $this->assertSame([[1, '1', '1']], array_map(
            fn (EntegratorFatura $f): array => [$f->kaynakId, $f->tutar, $f->vergiTutari],
            $sonuc->faturalar,
        ));
        $this->assertSame([
            ['kaynak_id' => 2, 'alanlar' => ['payableAmount']],
            ['kaynak_id' => 3, 'alanlar' => ['payableAmount']],
        ], $sonuc->hataliKayitlar);
    }

    public function test_liste_dizisi_yoksa_yanit_gecersizdir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => ProtelIstemcisiTest::girisYaniti(),
            self::LISTE_URL => Http::response(['status' => 'Success']),
        ]);

        try {
            $this->oku($this->tanim());
            $this->fail('Yanıt geçersiz sayılmalıydı.');
        } catch (EntegratorHatasi $hata) {
            $this->assertSame(EntegratorHatasi::YANIT_GECERSIZ, $hata->kod);
        }
    }

    public function test_180_gunu_asan_aralik_ve_giden_yon_istek_gonderilmeden_reddedilir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $tanim = $this->tanim();
        $kaynak = app(ProtelFaturaKaynagi::class);
        $reddedilen = 0;

        foreach ([
            fn () => $kaynak->oku($tanim, FaturaYonu::Gelen, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-06-30')),
            fn () => $kaynak->oku($tanim, FaturaYonu::Giden, CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31')),
        ] as $islem) {
            try {
                $islem();
            } catch (InvalidArgumentException) {
                $reddedilen++;
            }
        }

        $this->assertSame(2, $reddedilen);
        Http::assertNothingSent();
    }

    public function test_senkron_protel_faturalarini_protel_tanimina_yazar_otel_erp_bayragini_emor_okumasi_saymaz_mukerrer_uretmez(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00', 'Europe/Istanbul'));
        $tanim = $this->tanim();
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => ProtelIstemcisiTest::girisYaniti(),
            self::LISTE_URL => Http::sequence()
                ->push(['invoiceList' => [$this->kayit()]])
                ->push(['invoiceList' => [$this->kayit(['erpStatus' => 'Cancelled', 'isPrinted' => 0])]]),
        ]);

        $this->artisan('efatura:senkron', ['--saglayici' => 'protel', '--baslangic' => '2026-05-01'])->assertSuccessful();
        $this->artisan('efatura:senkron', ['--saglayici' => 'protel', '--baslangic' => '2026-05-01'])->assertSuccessful();

        $fatura = EFatura::query()->sole();
        $this->assertSame($tanim->id, $fatura->entegrator_baglanti_id);
        $this->assertSame('gelen', $fatura->yon);
        $this->assertSame('Cancelled', $fatura->erp_durumu);
        $this->assertFalse($fatura->yazdirildi);
        $this->assertTrue($fatura->otel_erp_okundu);
        $this->assertNull($fatura->erp_okundu);
        // Yalnız gelen yön çalışır
        $this->assertSame(['gelen'], EFaturaSenkronCalismasi::query()->distinct()->pluck('yon')->all());
    }

    /**
     * Kimlik reddi kendiliğinden düzelmez: ilk taramanın kalan ayları ayrı ayrı
     * giriş denemesin — saniyeler içinde biriken başarısız girişler otelin
     * hesabını kilitleyebilir (PRT-F1).
     */
    public function test_kimlik_reddinde_senkron_kalan_aylari_okumaz(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00', 'Europe/Istanbul'));
        $this->tanim();
        Http::preventStrayRequests();
        Http::fake([self::GIRIS_URL => Http::response(['isSuccessed' => false, 'result' => 'LoginFailed'], 401)]);

        $this->artisan('efatura:senkron', ['--saglayici' => 'protel', '--baslangic' => '2026-03-01'])->assertFailed();

        Http::assertSentCount(1);
        $calisma = EFaturaSenkronCalismasi::query()->sole();
        $this->assertSame(EntegratorHatasi::KIMLIK_HATALI, $calisma->hata_kodu);
        $this->assertSame('2026-03-01', $calisma->baslangic->toDateString());
    }

    /** Aralık denetimi tanım başınadır: İzibiz'in yeni çalışması Protel'i bekletmez. */
    public function test_protel_senkron_araligi_izibiz_calismalarindan_bagimsizdir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00', 'Europe/Istanbul'));
        $this->tanim();
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        EFaturaSenkronCalismasi::query()->create([
            'entegrator_baglanti_id' => $izibiz->id,
            'yon' => 'gelen',
            'tarih_turu' => ProtelFaturaKaynagi::TARIH_ULASMA,
            'baslangic' => '2026-05-31',
            'bitis' => '2026-05-31',
            'tetikleyen' => EFaturaSenkronCalismasi::TETIKLEYEN_ZAMANLANMIS,
            'durum' => EFaturaSenkronCalismasi::DURUM_TAM,
            'basladi' => CarbonImmutable::now()->subMinute(),
            'bitti' => CarbonImmutable::now()->subMinute(),
        ]);
        $this->protelYaniti([]);

        $this->artisan('efatura:senkron', [
            '--saglayici' => 'protel', '--gun' => 1, '--tarih-turu' => 'DELIVERY', '--aralik-denetimi' => true,
        ])->assertSuccessful();

        $this->assertCount(1, Http::recorded(fn (Request $istek): bool => $istek->url() === self::LISTE_URL));
    }

    public function test_modul_kapaliyken_senkron_protele_istek_gondermez(): void
    {
        config(['entegrator.protel.aktif' => false]);
        Http::preventStrayRequests();
        Http::fake();
        $this->tanim();

        $this->artisan('efatura:senkron', ['--saglayici' => 'protel', '--gun' => 10])
            ->expectsOutputToContain('PROTEL_AKTIF')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, EFaturaSenkronCalismasi::query()->count());
    }

    public function test_izibiz_senkronu_protel_tanimini_kullanmaz(): void
    {
        config(['entegrator.protel.aktif' => true, 'entegrator.izibiz.senkron_aktif' => true]);
        Http::preventStrayRequests();
        Http::fake();
        // Yalnız Protel tanımı aktif: İzibiz senkronu "aktif ortam yok" der
        $this->tanim();

        $this->artisan('efatura:senkron', ['--gun' => 10])
            ->expectsOutputToContain('Aktif entegratör ortamı yok')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Saklama sözleşmesi: satır sayısı PostgreSQL `integer`, tutar
     * `numeric(20,4)` (16 tam basamak, yuvarlamadan sonra). Taşan değer hatalı
     * kayıttır; sağlam faturaların toplu yazımını engellemez (PRT-R5).
     */
    public function test_tasan_sayisal_alan_hatali_kayittir_saglam_faturalar_yazilir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00', 'Europe/Istanbul'));
        $this->tanim();
        $this->protelYaniti([
            $this->kayit(['id' => 1, 'lineCount' => 2147483647, 'payableAmount' => 9999999999999999, 'taxTotal' => -9999999999999999]),
            $this->kayit(['id' => 2, 'lineCount' => 2147483648]),
            $this->kayit(['id' => 3, 'payableAmount' => 10000000000000000]),
            // yuvarlamadan sonra 17 basamak
            $this->kayit(['id' => 4, 'payableAmount' => '@ham:9999999999999999.99995']),
        ]);

        $this->artisan('efatura:senkron', ['--saglayici' => 'protel', '--baslangic' => '2026-05-01'])->assertFailed();

        $fatura = EFatura::query()->sole();
        $this->assertSame(1, $fatura->kaynak_id);
        $this->assertSame(2147483647, $fatura->satir_sayisi);
        $this->assertSame('9999999999999999.0000', $fatura->tutar);
        $calisma = EFaturaSenkronCalismasi::query()->sole();
        $this->assertSame(EFaturaSenkronCalismasi::DURUM_EKSIK, $calisma->durum);
        $this->assertSame(3, $calisma->hatali_adet);
    }

    /**
     * Yazım yolunda da float'a çevrilmez (PRT-R8). SQLite `numeric` 15 anlamlı
     * basamaktan fazlasını saklamaz; sözleşme canlı veritabanınındır.
     */
    public function test_postgresql_buyuk_ondalik_tutar_ve_kdvyi_aynen_saklar(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite numeric 15 anlamlı basamak saklar; yalnız PostgreSQL.');
        }

        config(['entegrator.protel.aktif' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-05-31 12:00', 'Europe/Istanbul'));
        $this->tanim();
        $this->protelYaniti([
            $this->kayit(['payableAmount' => '@ham:1234567890123456.23', 'taxTotal' => '@ham:-9999999999999999.9999']),
        ]);

        $this->artisan('efatura:senkron', ['--saglayici' => 'protel', '--baslangic' => '2026-05-01'])->assertSuccessful();

        $fatura = EFatura::query()->sole();
        $this->assertSame('1234567890123456.2300', $fatura->tutar);
        $this->assertSame('-9999999999999999.9999', $fatura->vergi_tutari);
    }
}
