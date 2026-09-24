<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\FaturaOkumaSonucu;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\IzibizFaturaKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class IzibizFaturaKaynagiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://apitest.izibiz.com.tr/v1/auth/token';

    private function tanim(): EntegratorBaglanti
    {
        return EntegratorBaglanti::factory()->create();
    }

    private function kaynak(): IzibizFaturaKaynagi
    {
        return app(IzibizFaturaKaynagi::class);
    }

    private function tarih(string $gun): CarbonImmutable
    {
        return CarbonImmutable::parse($gun);
    }

    /**
     * Test hesabında gözlenen liste kaydı biçimi (kişisel veri yok).
     *
     * @param  array<string, mixed>  $degisen
     * @return array<string, mixed>
     */
    private function kayit(int $id, array $degisen = []): array
    {
        return [
            'id' => $id,
            'documentType' => 'SATIS',
            'issueDate' => '2026-03-20',
            'issueTime' => null,
            'createDate' => '2026-03-21T21:30:24',
            'uuid' => sprintf('EEB4F1A9-9BC7-4576-BEB6-%012d', $id),
            'documentNo' => sprintf('ABC2026%09d', $id),
            'currency' => 'TRY',
            'direction' => 'IN',
            'documentStatus' => ['value' => 'RECEIVED', 'label' => 'Alındı'],
            'amount' => '5.155.262,20',
            'taxAmount' => '122,50',
            'lineCount' => 3,
            'profile' => 'TEMELFATURA',
            'readStatus' => false,
            'erpReadFlag' => true,
            'accountingSupplier' => ['identifier' => '0123456789', 'name' => 'Gönderen A.Ş.'],
            'accountingCustomer' => ['identifier' => '9876543210', 'name' => 'Alıcı A.Ş.'],
            'invoiceType' => 'SATIS',
            'statusDesc' => 'Alındı',
            'responseDescription' => null,
            'envelope' => ['identifier' => 'zarf', 'gibStatusCode' => 1300, 'gibStatusDescription' => 'BAŞARIYLA TAMAMLANDI'],
            ...$degisen,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $kayitlar
     * @return array<string, mixed>
     */
    private function sayfa(array $kayitlar, int $toplamSayfa, int $toplamAdet): array
    {
        return [
            'data' => [
                'contents' => $kayitlar,
                'pageable' => ['page' => 0, 'size' => 100, 'totalElements' => $toplamAdet, 'totalPages' => $toplamSayfa],
            ],
            'error' => null,
        ];
    }

    /**
     * Token isteğini ve kutunun sayfalarını (page parametresine göre) sahteler.
     *
     * @param  array<int, array<string, mixed>|int>  $sayfalar  sayfa no => gövde | HTTP durumu
     */
    private function sahteIzibiz(string $kutu, array $sayfalar): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) use ($kutu, $sayfalar) {
            if ($istek->url() === self::TOKEN_URL) {
                return Http::response([
                    'data' => ['accessToken' => 'erisim-token', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'],
                    'error' => null,
                ]);
            }

            if (str_starts_with($istek->url(), "https://apitest.izibiz.com.tr/v1/einvoices/{$kutu}?")) {
                $yanit = $sayfalar[(int) $istek->data()['page']] ?? null;

                return is_int($yanit) ? Http::response('', $yanit) : Http::response($yanit);
            }

            return null;
        });
    }

    public function test_tek_sayfa_gelen_faturalar_ortak_bicime_cevrilir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [0 => $this->sayfa([$this->kayit(267640388)], 1, 1)]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-03-20'), $this->tarih('2026-03-20'));

        $this->assertTrue($sonuc->tam());
        $this->assertSame(1, $sonuc->beklenenAdet);
        $fatura = $sonuc->faturalar[0];
        $this->assertSame(FaturaYonu::Gelen, $fatura->yon);
        $this->assertSame(267640388, $fatura->kaynakId);
        $this->assertSame('eeb4f1a9-9bc7-4576-beb6-000267640388', $fatura->ettn);
        $this->assertSame('ABC2026267640388', $fatura->belgeNo);
        $this->assertSame('2026-03-20', $fatura->belgeTarihi);
        $this->assertSame('5155262.20', $fatura->tutar);
        $this->assertSame('122.50', $fatura->vergiTutari);
        $this->assertSame('0123456789', $fatura->gondericiVkn);
        $this->assertSame('Alıcı A.Ş.', $fatura->aliciUnvan);
        $this->assertSame('RECEIVED', $fatura->durum);
        $this->assertSame(1300, $fatura->gibDurumKodu);
        $this->assertTrue($fatura->erpOkundu);
        $this->assertFalse($fatura->okundu);
    }

    public function test_sayfalar_sonuna_kadar_kararli_sirayla_okunur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [
            0 => $this->sayfa([$this->kayit(1), $this->kayit(2)], 3, 5),
            1 => $this->sayfa([$this->kayit(3), $this->kayit(4)], 3, 5),
            2 => $this->sayfa([$this->kayit(5)], 3, 5),
        ]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-31'));

        $this->assertTrue($sonuc->tam());
        $this->assertSame(3, $sonuc->okunanSayfa);
        $this->assertSame([1, 2, 3, 4, 5], array_map(fn ($f) => $f->kaynakId, $sonuc->faturalar));
        Http::assertSent(fn (Request $istek): bool => str_contains($istek->url(), '/v1/einvoices/inbox?')
            && $istek->data() === [
                'dateType' => 'DOCUMENT',
                'startDate' => '2026-01-01',
                'endDate' => '2026-01-31',
                'page' => 2,
                'pageSize' => 100,
                'sort' => 'asc',
                'sortProperty' => 'id',
            ]);
    }

    public function test_giden_faturalar_outbox_kutusundan_okunur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('outbox', [0 => $this->sayfa([$this->kayit(9, ['direction' => 'OUT', 'erpReadFlag' => null])], 1, 1)]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Giden, $this->tarih('2026-03-20'), $this->tarih('2026-03-20'));

        $this->assertSame(FaturaYonu::Giden, $sonuc->faturalar[0]->yon);
        $this->assertNull($sonuc->faturalar[0]->erpOkundu);
    }

    public function test_bos_ama_basarili_okuma_tam_sayilir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [0 => $this->sayfa([], 0, 0)]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-01'));

        $this->assertTrue($sonuc->tam());
        $this->assertSame([], $sonuc->faturalar);
        $this->assertSame(1, $sonuc->okunanSayfa);
    }

    public function test_sayfa_ortasinda_hata_bos_liste_degil_istisna_olur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        Sleep::fake();
        $this->sahteIzibiz('inbox', [
            0 => $this->sayfa([$this->kayit(1)], 2, 2),
            1 => 503,
        ]);

        $this->expectException(EntegratorHatasi::class);

        $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-31'));
    }

    public function test_bicimi_bozuk_sayfa_yaniti_gecersiz_yanit_hatasi_olur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [0 => ['data' => ['contents' => [$this->kayit(1)]], 'error' => null]]);

        try {
            $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-01'));
            $this->fail('Geçersiz yanıt hatası bekleniyordu.');
        } catch (EntegratorHatasi $hata) {
            $this->assertSame(EntegratorHatasi::YANIT_GECERSIZ, $hata->kod);
        }
    }

    public function test_sayfa_siniri_asilirsa_okuma_eksik_isaretlenir(): void
    {
        config(['entegrator.izibiz.azami_sayfa' => 2]);
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [
            0 => $this->sayfa([$this->kayit(1)], 3, 3),
            1 => $this->sayfa([$this->kayit(2)], 3, 3),
            2 => $this->sayfa([$this->kayit(3)], 3, 3),
        ]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-31'));

        $this->assertFalse($sonuc->tam());
        $this->assertSame(FaturaOkumaSonucu::EKSIK_SAYFA_SINIRI, $sonuc->eksikNedeni);
        $this->assertSame(2, $sonuc->okunanSayfa);
    }

    public function test_sayfalar_arasi_kayma_ile_tekrar_eden_kayit_tekil_tutulur_ve_eksik_sayilir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        // Okuma sırasında bir kayıt silindi: 2. sayfa 2 numaralı kaydı yeniden getirdi, 3 atlandı
        $this->sahteIzibiz('inbox', [
            0 => $this->sayfa([$this->kayit(1), $this->kayit(2)], 2, 4),
            1 => $this->sayfa([$this->kayit(2), $this->kayit(4)], 2, 4),
        ]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-31'));

        $this->assertSame([1, 2, 4], array_map(fn ($f) => $f->kaynakId, $sonuc->faturalar));
        $this->assertSame(FaturaOkumaSonucu::EKSIK_SAYIM_UYUSMUYOR, $sonuc->eksikNedeni);
    }

    public function test_bozuk_kayit_sessizce_dusurulmez_veri_hatasi_olarak_raporlanir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [0 => $this->sayfa([
            $this->kayit(1),
            $this->kayit(2, ['amount' => 'on iki lira', 'uuid' => '']),
        ], 1, 2)]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-01'));

        $this->assertSame([1], array_map(fn ($f) => $f->kaynakId, $sonuc->faturalar));
        $this->assertSame([['kaynak_id' => 2, 'alanlar' => ['uuid', 'amount']]], $sonuc->hataliKayitlar);
        $this->assertSame(FaturaOkumaSonucu::EKSIK_VERI_HATASI, $sonuc->eksikNedeni);
    }

    public function test_takvim_ve_veritabani_sinirlarini_asan_kayitlar_eksik_veri_olur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [0 => $this->sayfa([
            $this->kayit(1),
            $this->kayit(2, ['issueDate' => '2026-02-30']),
            $this->kayit(3, ['amount' => '10,12345']),
            $this->kayit(4, ['currency' => 'EURO']),
        ], 1, 4)]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-01'));

        $this->assertSame([1], array_map(fn ($f) => $f->kaynakId, $sonuc->faturalar));
        $this->assertSame([
            ['kaynak_id' => 2, 'alanlar' => ['issueDate']],
            ['kaynak_id' => 3, 'alanlar' => ['amount']],
            ['kaynak_id' => 4, 'alanlar' => ['currency']],
        ], $sonuc->hataliKayitlar);
        $this->assertSame(FaturaOkumaSonucu::EKSIK_VERI_HATASI, $sonuc->eksikNedeni);
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function tutarlar(): array
    {
        return [
            'binlik ve kuruş' => ['5.155.262,20', '5155262.20'],
            'yalnız kuruş' => ['440,90', '440.90'],
            'sıfır' => ['0,00', '0.00'],
            'binliksiz büyük sayı' => ['1234,5', '1234.5'],
            'kuruşsuz' => ['1.000', '1000'],
            'eksi' => ['-12,50', '-12.50'],
            'nokta ondalık (İngilizce biçim)' => ['12.5', null],
            'metin' => ['on iki', null],
            'boş' => ['', null],
        ];
    }

    #[DataProvider('tutarlar')]
    public function test_turkce_tutar_ondalik_metne_cevrilir(string $ham, ?string $beklenen): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [0 => $this->sayfa([$this->kayit(1, ['amount' => $ham])], 1, 1)]);

        $sonuc = $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-01'));

        $this->assertSame($beklenen, $sonuc->faturalar[0]->tutar ?? null);
    }

    public function test_okuma_yalniz_get_yapar_ve_okundu_bayraklarina_dokunmaz(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $this->sahteIzibiz('inbox', [
            0 => $this->sayfa([$this->kayit(1)], 2, 2),
            1 => $this->sayfa([$this->kayit(2)], 2, 2),
        ]);

        $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih('2026-01-01'), $this->tarih('2026-01-31'));

        // ERP yeni faturaları erpReadFlag ile alıyor: bayrakları değiştiren hiçbir
        // istek gitmemeli; tek POST token alımıdır
        $istekler = Http::recorded()->map(fn (array $kayit) => $kayit[0]);
        $this->assertCount(3, $istekler);
        foreach ($istekler as $istek) {
            $this->assertStringNotContainsString('read-flag', $istek->url());
            $this->assertTrue(
                $istek->method() === 'GET' || ($istek->method() === 'POST' && $istek->url() === self::TOKEN_URL),
                "Beklenmeyen istek: {$istek->method()} {$istek->url()}",
            );
        }
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function gecersizAraliklar(): array
    {
        return [
            'bitiş başlangıçtan önce' => ['2026-02-01', '2026-01-31', 'DOCUMENT'],
            'aralık 366 günden uzun' => ['2026-01-01', '2027-01-02', 'DOCUMENT'],
            'bilinmeyen tarih türü' => ['2026-01-01', '2026-01-31', 'CREATE'],
        ];
    }

    #[DataProvider('gecersizAraliklar')]
    public function test_gecersiz_aralik_istek_atmadan_reddedilir(string $baslangic, string $bitis, string $tur): void
    {
        Http::preventStrayRequests();
        Http::fake();

        try {
            $this->kaynak()->oku($this->tanim(), FaturaYonu::Gelen, $this->tarih($baslangic), $this->tarih($bitis), $tur);
            $this->fail('InvalidArgumentException bekleniyordu.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }
}
