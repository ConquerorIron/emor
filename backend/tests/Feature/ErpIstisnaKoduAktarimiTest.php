<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Services\ErpFaturaKaynagi;
use App\Services\ErpIstisnaKoduYazici;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Entegratördeki istisna kodu ERP'de boşsa TOHOM_E_FATURA'ya yazılır (kullanıcı
 * kararı 2026-09-24); çok kodlu virgülle yazılır; dolu koda ve havuzda olmayana dokunulmaz.
 */
final class ErpIstisnaKoduAktarimiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> ERP'ye yazılanlar [ettn, kod] */
    private array $yazilanlar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Üretimde varsayılan kapalı; açıkken davranış sınanır
        config(['efatura.erp_istisna_kodu_yaz' => true]);
    }

    /**
     * ERP okuması: verilen ETTN'ler havuzda, kodları boş (ya da verilen).
     *
     * @param  array<string, string|null>  $havuzKodlari  ETTN => ERP'deki kod
     * @param  list<string>  $erpDoluDiyor  yazmaya "satır dolu/yok" cevabı verilecek ETTN'ler
     * @param  list<string>  $islenmis  TOHOM_FATURA / TOHOM_HARCAMA_BELGESI'nde ETTN'i olanlar
     */
    private function erp(array $havuzKodlari, array $erpDoluDiyor = [], ?RuntimeException $yazimHatasi = null, array $islenmis = []): void
    {
        $this->app->instance(ErpFaturaKaynagi::class, new class($havuzKodlari, $islenmis) implements ErpFaturaKaynagi
        {
            /**
             * @param  array<string, string|null>  $havuz
             * @param  list<string>  $islenmis
             */
            public function __construct(private readonly array $havuz, private readonly array $islenmis) {}

            public function islenmisGelenEttnler(): array
            {
                return $this->islenmis;
            }

            public function islenmisGelenBelgeler(): array
            {
                return [];
            }

            public function havuzdakiGelenler(): array
            {
                return array_map(fn (?string $kod): array => ['istisna_kodu' => $kod, 'gonderici_etiketi' => null, 'alici_etiketi' => null], $this->havuz);
            }

            public function gonderilenFaturalar(): array
            {
                return [];
            }
        });

        $test = $this;
        $this->app->instance(ErpIstisnaKoduYazici::class, new class($test, $erpDoluDiyor, $yazimHatasi) implements ErpIstisnaKoduYazici
        {
            /** @param list<string> $dolu */
            public function __construct(private readonly ErpIstisnaKoduAktarimiTest $test, private readonly array $dolu, private readonly ?RuntimeException $hata) {}

            public function yaz(string $ettn, string $kod): bool
            {
                if ($this->hata !== null) {
                    throw $this->hata;
                }

                if (in_array($ettn, $this->dolu, true)) {
                    return false;
                }

                $this->test->yazildi($ettn, $kod);

                return true;
            }
        });
    }

    public function yazildi(string $ettn, string $kod): void
    {
        $this->yazilanlar[] = [$ettn, $kod];
    }

    /**
     * @param  array<string, mixed>  $alanlar
     */
    private function fatura(EntegratorBaglanti $tanim, string $ettn, array $alanlar = []): EFatura
    {
        return EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => $ettn, ...$alanlar]);
    }

    public function test_erpde_bos_olan_kod_entegratordekiyle_doldurulur_dolu_ve_havuzda_olmayana_dokunulmaz(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $bos = $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000001', ['izibiz_istisna_kodu' => '305']);
        $dolu = $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000002', ['izibiz_istisna_kodu' => '351']);
        $havuzdaYok = $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000003', ['izibiz_istisna_kodu' => '311']);
        $cokKodlu = $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000004', ['izibiz_istisna_kodu' => '308,351']);
        $entegratordeYok = $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000005');
        $this->erp([
            'aaaaaaaa-0000-0000-0000-000000000001' => null,
            'aaaaaaaa-0000-0000-0000-000000000002' => '318',
            'aaaaaaaa-0000-0000-0000-000000000004' => null,
            'aaaaaaaa-0000-0000-0000-000000000005' => null,
        ]);

        $denetim = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('denetim')->andReturn($denetim);

        $this->artisan('efatura:emor')
            ->expectsOutput('İstisna kodu ERP\'ye: 2 yazıldı, 0 uzun olduğu için atlandı, 0 yazılmadı')
            ->assertSuccessful();

        // Her yazım denetim kanalına (log seviyesinden bağımsız) düşer
        $denetim->shouldHaveReceived('info')->with('Vergi istisna kodu ERP\'ye yazıldı', Mockery::on(fn (array $baglam): bool => $baglam['kod'] === '305'))->once();
        $denetim->shouldHaveReceived('info')->with('Vergi istisna kodu ERP\'ye yazıldı', Mockery::on(fn (array $baglam): bool => $baglam['kod'] === '308,351'))->once();

        $this->assertSame([
            ['aaaaaaaa-0000-0000-0000-000000000001', '305'],
            ['aaaaaaaa-0000-0000-0000-000000000004', '308,351'],
        ], $this->yazilanlar);
        $this->assertSame('305', $bos->fresh()->vergi_istisna_kodu);
        // ERP'deki dolu kod korunur (entegratördeki farklı olsa da)
        $this->assertSame('318', $dolu->fresh()->vergi_istisna_kodu);
        $this->assertNull($havuzdaYok->fresh()->vergi_istisna_kodu);
        // Birden çok kod virgülle olduğu gibi (kullanıcı kararı)
        $this->assertSame('308,351', $cokKodlu->fresh()->vergi_istisna_kodu);
        $this->assertNull($entegratordeYok->fresh()->vergi_istisna_kodu);
    }

    public function test_islenmis_ama_havuzdan_silinmis_faturaya_erpye_update_gonderilmez(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        // Muhasebeye işlenmiş (ETTN ile) ama TOHOM_E_FATURA'dan silinmiş
        $fatura = $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000001', ['izibiz_istisna_kodu' => '305']);
        $this->erp([], islenmis: ['aaaaaaaa-0000-0000-0000-000000000001']);

        $this->artisan('efatura:emor')
            ->expectsOutput('İstisna kodu ERP\'ye: 0 yazıldı, 0 uzun olduğu için atlandı, 0 yazılmadı')
            ->assertSuccessful();

        $this->assertSame('islendi', $fatura->fresh()->emor_durumu->value);
        $this->assertSame([], $this->yazilanlar);
    }

    public function test_erp_bu_arada_doldurduysa_yazilmaz_ve_bizdeki_kod_degismez(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000001', ['izibiz_istisna_kodu' => '305']);
        $this->erp(['aaaaaaaa-0000-0000-0000-000000000001' => null], erpDoluDiyor: ['aaaaaaaa-0000-0000-0000-000000000001']);

        $this->artisan('efatura:emor')->expectsOutput('İstisna kodu ERP\'ye: 0 yazıldı, 0 uzun olduğu için atlandı, 1 yazılmadı');

        $this->assertNull($fatura->fresh()->vergi_istisna_kodu);
    }

    public function test_yazim_yetkisi_yoksa_emor_yine_tazelenir_komut_basarisiz_doner(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000001', ['izibiz_istisna_kodu' => '305']);
        $this->erp(['aaaaaaaa-0000-0000-0000-000000000001' => null], yazimHatasi: new RuntimeException('UPDATE permission was denied'));

        $this->artisan('efatura:emor')->assertFailed();

        $this->assertSame('havuzda', $fatura->fresh()->emor_durumu->value);
        $this->assertNull($fatura->fresh()->vergi_istisna_kodu);
    }

    public function test_anahtar_kapaliysa_erpye_yazilmaz(): void
    {
        config(['efatura.erp_istisna_kodu_yaz' => false]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $this->fatura($tanim, 'aaaaaaaa-0000-0000-0000-000000000001', ['izibiz_istisna_kodu' => '305']);
        $this->erp(['aaaaaaaa-0000-0000-0000-000000000001' => null]);

        $this->artisan('efatura:emor')->assertSuccessful();

        $this->assertSame([], $this->yazilanlar);
    }

    public function test_pasif_entegratorun_istisna_kodu_aktif_erpye_yazilmaz(): void
    {
        EntegratorBaglanti::factory()->aktif()->create();
        $pasif = EntegratorBaglanti::factory()->canli()->create();
        $fatura = $this->fatura($pasif, 'aaaaaaaa-0000-0000-0000-000000000001', [
            'izibiz_istisna_kodu' => '305', 'emor_durumu' => 'havuzda',
        ]);
        $this->erp([$fatura->ettn => null]);

        $this->artisan('efatura:emor')->assertSuccessful();

        $this->assertSame([], $this->yazilanlar);
        $this->assertNull($fatura->fresh()->vergi_istisna_kodu);
    }
}
