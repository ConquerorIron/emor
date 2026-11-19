<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaOkumaSonucu;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\ProtelFaturaKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
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
     * @param  list<array<string, mixed>>  $kayitlar
     */
    private function protelYaniti(array $kayitlar): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => ProtelIstemcisiTest::girisYaniti(),
            // Başarılı yanıtta da isSuccess=false geliyor (PRT-01)
            self::LISTE_URL => Http::response(['invoiceList' => $kayitlar, 'status' => 'Success', 'isSuccess' => false]),
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
        $this->assertTrue($f->erpOkundu);
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

    public function test_senkron_protel_faturalarini_protel_tanimina_yazar_ikinci_calisma_mukerrer_uretmez(): void
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
        // Yalnız gelen yön çalışır
        $this->assertSame(['gelen'], EFaturaSenkronCalismasi::query()->distinct()->pluck('yon')->all());
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
}
