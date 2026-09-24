<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EFaturaSenkronServisi;
use App\Services\Entegrator\FaturaYonu;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use LogicException;
use RuntimeException;
use Tests\TestCase;

final class EFaturaSenkronTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $degisen
     * @return array<string, mixed>
     */
    private function kayit(int $id, array $degisen = []): array
    {
        return [
            'id' => $id,
            'issueDate' => '2026-01-10',
            'createDate' => '2026-01-10T21:30:24',
            'uuid' => sprintf('eeb4f1a9-9bc7-4576-beb6-%012d', $id),
            'documentNo' => sprintf('ABC2026%09d', $id),
            'currency' => 'TRY',
            'documentStatus' => ['value' => 'RECEIVED', 'label' => 'Alındı'],
            'amount' => '1.250,50',
            'taxAmount' => '208,42',
            'profile' => 'TICARIFATURA',
            'invoiceType' => 'SATIS',
            'erpReadFlag' => false,
            'readStatus' => false,
            'accountingSupplier' => ['identifier' => '0123456789', 'name' => 'Gönderen A.Ş.'],
            'accountingCustomer' => ['identifier' => '9876543210', 'name' => 'Alıcı A.Ş.'],
            ...$degisen,
        ];
    }

    /**
     * Sunucu adı => [kayıtlar | HTTP durumu, beklenen adet]. Sahte bir kez
     * kurulur ve yanıtı her istekte buradan okur; test içinde değiştirilebilir.
     *
     * @var array<string, array{0: list<array<string, mixed>>|int, 1: int|null}>
     */
    private array $izibizYanitlari = [];

    private bool $sahteKuruldu = false;

    /**
     * @param  list<array<string, mixed>>|int  $kayitlarVeyaDurum
     */
    private function sahteIzibiz(array|int $kayitlarVeyaDurum, ?int $beklenenAdet = null, string $sunucu = 'apitest.izibiz.com.tr'): void
    {
        $this->izibizYanitlari[$sunucu] = [$kayitlarVeyaDurum, $beklenenAdet];

        if ($this->sahteKuruldu) {
            return;
        }

        $this->sahteKuruldu = true;
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) {
            if (str_ends_with($istek->url(), '/v1/auth/token')) {
                return Http::response([
                    'data' => ['accessToken' => 'erisim-token', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'],
                    'error' => null,
                ]);
            }

            $sunucu = (string) parse_url($istek->url(), PHP_URL_HOST);

            if (! str_contains($istek->url(), '/v1/einvoices/inbox?') || ! isset($this->izibizYanitlari[$sunucu])) {
                return null;
            }

            [$kayitlarVeyaDurum, $beklenenAdet] = $this->izibizYanitlari[$sunucu];

            if (is_int($kayitlarVeyaDurum)) {
                return Http::response('', $kayitlarVeyaDurum);
            }

            return Http::response(['data' => [
                'contents' => $kayitlarVeyaDurum,
                'pageable' => ['totalPages' => 1, 'totalElements' => $beklenenAdet ?? count($kayitlarVeyaDurum)],
            ], 'error' => null]);
        });
    }

    private function servis(): EFaturaSenkronServisi
    {
        return app(EFaturaSenkronServisi::class);
    }

    private function gun(string $tarih): CarbonImmutable
    {
        return CarbonImmutable::parse($tarih);
    }

    public function test_faturalar_ozet_olarak_yazilir_ve_calisma_tam_kaydedilir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = EntegratorBaglanti::factory()->create();
        $this->sahteIzibiz([$this->kayit(1), $this->kayit(2)]);

        [$calisma] = $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');

        $this->assertSame(EFaturaSenkronCalismasi::DURUM_TAM, $calisma->durum);
        $this->assertSame([2, 2, 2, 0, 0], [$calisma->beklenen_adet, $calisma->okunan_adet, $calisma->yeni_adet, $calisma->guncellenen_adet, $calisma->hatali_adet]);
        $fatura = EFatura::query()->where('kaynak_id', 1)->firstOrFail();
        $this->assertSame('gelen', $fatura->yon);
        $this->assertSame('eeb4f1a9-9bc7-4576-beb6-000000000001', $fatura->ettn);
        $this->assertSame('1250.5000', $fatura->tutar);
        $this->assertSame('2026-01-10', $fatura->belge_tarihi->toDateString());
        // İstanbul 21:30:24 → UTC 18:30:24
        $this->assertSame('2026-01-10T18:30:24+00:00', $fatura->olusturma_zamani?->utc()->toIso8601String());
        $this->assertFalse($fatura->erp_okundu);
        $this->assertSame($tanim->id, $fatura->entegrator_baglanti_id);
    }

    public function test_tekrar_senkron_kayit_cogaltmaz_durumu_gunceller_ilk_gorulmeyi_korur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = EntegratorBaglanti::factory()->create();
        $this->sahteIzibiz([$this->kayit(1), $this->kayit(2)]);
        $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');

        $this->travelTo('2026-09-23 12:00:00');
        $this->sahteIzibiz([$this->kayit(1, ['documentStatus' => ['value' => 'ACCEPTED', 'label' => 'Kabul Edildi'], 'erpReadFlag' => true]), $this->kayit(2), $this->kayit(3)]);
        [$calisma] = $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'zamanlanmis');

        $this->assertSame([1, 2], [$calisma->yeni_adet, $calisma->guncellenen_adet]);
        $this->assertDatabaseCount('efatura_faturalari', 3);
        $fatura = EFatura::query()->where('kaynak_id', 1)->firstOrFail();
        $this->assertSame('ACCEPTED', $fatura->durum);
        $this->assertTrue($fatura->erp_okundu);
        $this->assertSame('2026-09-23 11:24:41', $fatura->ilk_gorulme->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-23 12:00:00', $fatura->son_gorulme->utc()->format('Y-m-d H:i:s'));
    }

    public function test_aralik_takvim_aylarina_bolunur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = EntegratorBaglanti::factory()->create();
        $this->sahteIzibiz([]);

        $calismalar = $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-15'), $this->gun('2026-03-05'), 'ilk_tarama');

        $this->assertSame(
            [['2026-01-15', '2026-01-31'], ['2026-02-01', '2026-02-28'], ['2026-03-01', '2026-03-05']],
            array_map(fn ($c) => [$c->baslangic->toDateString(), $c->bitis->toDateString()], $calismalar),
        );
        Http::assertSent(fn (Request $istek): bool => ($istek->data()['startDate'] ?? null) === '2026-02-01'
            && ($istek->data()['endDate'] ?? null) === '2026-02-28');
    }

    public function test_beklenmeyen_hatada_calisma_basarisiz_kapanir_kilit_birakilir_ve_hata_iletilir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = EntegratorBaglanti::factory()->create();
        Http::preventStrayRequests();
        Http::fake(fn () => throw new LogicException('beklenmeyen'));

        try {
            $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');
            $this->fail('Beklenmeyen hata yukarı iletilmeliydi.');
        } catch (LogicException) {
        }

        $calisma = EFaturaSenkronCalismasi::query()->sole();
        $this->assertSame(['basarisiz', 'BEKLENMEYEN_HATA', LogicException::class], [$calisma->durum, $calisma->hata_kodu, $calisma->hata_mesaji]);
        $this->assertNotNull($calisma->bitti);
        $this->assertTrue(Cache::lock("efatura-senkron:{$tanim->id}:gelen", 10)->get());
    }

    public function test_izibiz_hatasinda_calisma_basarisiz_kaydedilir_ve_mevcut_ozetler_korunur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        Sleep::fake();
        $tanim = EntegratorBaglanti::factory()->create();
        $this->sahteIzibiz([$this->kayit(1)]);
        $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');

        $this->sahteIzibiz(503);
        [$calisma] = $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'zamanlanmis');

        $this->assertSame(EFaturaSenkronCalismasi::DURUM_BASARISIZ, $calisma->durum);
        $this->assertSame('ENTEGRATOR_ERISILEMEDI', $calisma->hata_kodu);
        $this->assertNotNull($calisma->bitti);
        $this->assertDatabaseCount('efatura_faturalari', 1);
    }

    public function test_yazma_hatasinda_calisma_basarisiz_kapanir_ve_hata_fatura_verisi_icermez(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = EntegratorBaglanti::factory()->create();
        $this->sahteIzibiz([$this->kayit(1, ['accountingCustomer' => ['identifier' => '9876543210', 'name' => 'Gizli Alıcı Unvanı']])]);
        // Yazma hatası üret (PostgreSQL'de ör. kolon uzunluğu aşımı)
        Schema::drop('efatura_faturalari');

        try {
            $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');
            $this->fail('Yazma hatası bekleniyordu.');
        } catch (RuntimeException $hata) {
            $this->assertStringStartsWith('e-Fatura özetleri yazılamadı:', $hata->getMessage());
            $this->assertStringNotContainsString('Gizli Alıcı Unvanı', $hata->getMessage());
        }

        $calisma = EFaturaSenkronCalismasi::query()->sole();
        $this->assertSame(EFaturaSenkronCalismasi::DURUM_BASARISIZ, $calisma->durum);
        $this->assertSame('YAZMA_HATASI', $calisma->hata_kodu);
        $this->assertStringNotContainsString('Gizli Alıcı Unvanı', (string) $calisma->hata_mesaji);
        $this->assertNotNull($calisma->bitti);
    }

    public function test_eksik_okumada_okunan_kayitlar_yazilir_calisma_eksik_isaretlenir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = EntegratorBaglanti::factory()->create();
        $this->sahteIzibiz([$this->kayit(1)], beklenenAdet: 2);

        [$calisma] = $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');

        $this->assertSame(EFaturaSenkronCalismasi::DURUM_EKSIK, $calisma->durum);
        $this->assertSame('sayim_uyusmuyor', $calisma->eksik_nedeni);
        $this->assertDatabaseCount('efatura_faturalari', 1);
    }

    public function test_gecersiz_tarihli_kayit_diger_faturalarin_yazilmasini_engellemez(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = EntegratorBaglanti::factory()->create();
        $this->sahteIzibiz([
            $this->kayit(1),
            $this->kayit(2, ['issueDate' => '2026-02-30']),
        ]);

        [$calisma] = $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');

        $this->assertSame(EFaturaSenkronCalismasi::DURUM_EKSIK, $calisma->durum);
        $this->assertSame(1, $calisma->hatali_adet);
        $this->assertSame([1], EFatura::query()->pluck('kaynak_id')->all());
    }

    public function test_ayni_tanim_ve_yon_icin_suren_senkron_varsa_istek_atmadan_atlanir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = EntegratorBaglanti::factory()->create();
        $this->sahteIzibiz([$this->kayit(1)]);
        $kilit = Cache::lock("efatura-senkron:{$tanim->id}:gelen", 60);
        $kilit->get();

        $sonuc = $this->servis()->senkronEt($tanim, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');

        $this->assertSame([], $sonuc);
        Http::assertNothingSent();
        $this->assertDatabaseCount('efatura_senkron_calismalari', 0);
        $kilit->release();
    }

    public function test_test_ve_canli_hesabin_ayni_kaynak_idli_faturalari_karismaz(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $test = EntegratorBaglanti::factory()->create();
        $canli = EntegratorBaglanti::factory()->canli()->create();
        $this->sahteIzibiz([$this->kayit(1)]);
        $this->servis()->senkronEt($test, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');

        $this->sahteIzibiz([$this->kayit(1, ['amount' => '99,00'])], sunucu: 'api.izibiz.com.tr');
        $this->servis()->senkronEt($canli, FaturaYonu::Gelen, $this->gun('2026-01-01'), $this->gun('2026-01-31'), 'manuel');

        $this->assertDatabaseCount('efatura_faturalari', 2);
        $this->assertSame('1250.5000', EFatura::query()->where('entegrator_baglanti_id', $test->id)->value('tutar'));
        $this->assertSame('99.0000', EFatura::query()->where('entegrator_baglanti_id', $canli->id)->value('tutar'));
    }

    public function test_komut_aktif_tanim_yoksa_istek_atmadan_basariyla_cikar(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        EntegratorBaglanti::factory()->create(['aktif' => false]);

        $this->artisan('efatura:senkron', ['--gun' => 2])
            ->expectsOutput('Aktif entegratör ortamı yok; senkron atlandı.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_komut_senkron_kapaliysa_hicbir_sey_yapmaz(): void
    {
        config(['entegrator.izibiz.senkron_aktif' => false]);
        Http::preventStrayRequests();
        Http::fake();
        EntegratorBaglanti::factory()->aktif()->create();

        $this->artisan('efatura:senkron', ['--gun' => 2])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDatabaseCount('efatura_senkron_calismalari', 0);
    }

    public function test_komut_eksik_okumada_basarisiz_cikis_kodu_verir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        EntegratorBaglanti::factory()->aktif()->create();
        $this->sahteIzibiz([$this->kayit(1)], beklenenAdet: 2);

        $this->artisan('efatura:senkron', ['--gun' => 2, '--yon' => 'gelen'])->assertExitCode(1);

        $this->assertSame(EFaturaSenkronCalismasi::DURUM_EKSIK, EFaturaSenkronCalismasi::query()->sole()->durum);
    }

    public function test_komut_gun_secenegini_istanbul_takvimine_gore_hesaplar_ve_iki_yonu_senkronlar(): void
    {
        // UTC 22:30 = İstanbul'da ertesi gün 01:30
        $this->travelTo('2026-09-22 22:30:00');
        EntegratorBaglanti::factory()->aktif()->create();
        Http::preventStrayRequests();
        Http::fake([
            'https://apitest.izibiz.com.tr/v1/auth/token' => Http::response(['data' => ['accessToken' => 't', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'], 'error' => null]),
            'https://apitest.izibiz.com.tr/v1/einvoices/*' => Http::response(['data' => ['contents' => [], 'pageable' => ['totalPages' => 0, 'totalElements' => 0]], 'error' => null]),
        ]);

        $this->artisan('efatura:senkron', ['--gun' => 2, '--tarih-turu' => 'DELIVERY'])->assertSuccessful();

        $calismalar = EFaturaSenkronCalismasi::query()->orderBy('id')->get();
        $this->assertSame(['gelen', 'giden'], $calismalar->pluck('yon')->all());
        $this->assertSame('2026-09-22', $calismalar[0]->baslangic->toDateString());
        $this->assertSame('2026-09-23', $calismalar[0]->bitis->toDateString());
        $this->assertSame('DELIVERY', $calismalar[0]->tarih_turu);
        $this->assertSame('zamanlanmis', $calismalar[0]->tetikleyen);
    }

    public function test_komut_gecersiz_secenekleri_istek_atmadan_reddeder(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        EntegratorBaglanti::factory()->aktif()->create();

        $this->artisan('efatura:senkron')->assertExitCode(2);
        $this->artisan('efatura:senkron', ['--gun' => 2, '--baslangic' => '2026-01-01'])->assertExitCode(2);
        $this->artisan('efatura:senkron', ['--baslangic' => '2026-02-30'])->assertExitCode(2);
        $this->artisan('efatura:senkron', ['--gun' => 2, '--yon' => 'her-ikisi'])->assertExitCode(2);

        Http::assertNothingSent();
    }
}
