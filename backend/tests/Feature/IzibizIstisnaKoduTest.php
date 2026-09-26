<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Models\User;
use App\Services\Entegrator\FaturaYonu;
use App\Services\Entegrator\IzibizIstemcisi;
use App\Services\Entegrator\IzibizIstisnaKoduServisi;
use App\Services\ErpBelgeArsivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Vergi istisna kodu önce ERP havuzundaki UBL'den, yoksa İzibiz UBL'inden (toplu
 * indirme, fatura başına bir kez) ve
 * İzibiz istemcisinin yalnız-okuma kilidi.
 */
final class IzibizIstisnaKoduTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<list<int>> Toplu indirmeye gelen kimlik partileri */
    private array $istenenler = [];

    /** @var list<int> İzibiz'in 10008 ile reddettiği (sorunlu) faturalar */
    private array $sorunlular = [];

    /** @var array<int, string> kaynak_id => UBL */
    private array $ubller = [];

    private function ubl(string $ettn, string ...$kodlar): string
    {
        $vergiler = implode('', array_map(fn (string $kod): string => "<cac:TaxSubtotal><cac:TaxCategory><cbc:TaxExemptionReasonCode>{$kod}</cbc:TaxExemptionReasonCode><cac:TaxScheme><cbc:Name>KDV</cbc:Name></cac:TaxScheme></cac:TaxCategory></cac:TaxSubtotal>", $kodlar));

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            ."<cbc:ID>ABC</cbc:ID><cbc:UUID>{$ettn}</cbc:UUID>"
            // Referans belgenin UUID'i faturanınki sanılmamalı
            .'<cac:DespatchDocumentReference><cbc:UUID>ffffffff-0000-0000-0000-000000000000</cbc:UUID></cac:DespatchDocumentReference>'
            ."<cac:TaxTotal>{$vergiler}</cac:TaxTotal></Invoice>";
    }

    /**
     * ERP havuzu sahtesi: küçük harf ETTN => UBL (null: havuzda, istisna etiketi yok).
     *
     * @param  array<string, string|null>  $havuz
     */
    private function erpHavuzu(array $havuz, ?RuntimeException $hata = null): void
    {
        $this->app->instance(ErpBelgeArsivi::class, new class($havuz, $hata) implements ErpBelgeArsivi
        {
            /** @param array<string, string|null> $havuz */
            public function __construct(private readonly array $havuz, private readonly ?RuntimeException $hata) {}

            public function pdf(string $ettn): ?string
            {
                return null;
            }

            public function xml(string $ettn): ?string
            {
                return null;
            }

            public function istisnaXmlleri(array $ettnler): array
            {
                if ($this->hata !== null) {
                    throw $this->hata;
                }

                return array_intersect_key($this->havuz, array_flip(array_map(mb_strtolower(...), $ettnler)));
            }
        });
    }

    /** İzibiz sahtesi; ERP havuzu varsayılan boştur (fatura havuzda değil). */
    private function sahteIzibiz(): void
    {
        $this->erpHavuzu([]);
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) {
            if (str_ends_with($istek->url(), '/v1/auth/token')) {
                return Http::response(['data' => ['accessToken' => 't', 'validity' => '2099-01-01 00:00:00', 'customerType' => 'C'], 'error' => null]);
            }

            if (! str_ends_with($istek->url(), '/v1/einvoices/inbox/download/ubl') || $istek->method() !== 'POST') {
                return null;
            }

            $idler = array_column($istek->data(), 'id');
            $this->istenenler[] = $idler;

            if (array_intersect($idler, $this->sorunlular) !== []) {
                return Http::response(['data' => null, 'error' => ['code' => '10008', 'message' => 'Beklenmedik bir hata']], 400);
            }

            $yol = tempnam(sys_get_temp_dir(), 'zip');
            $zip = new ZipArchive;
            $zip->open($yol, ZipArchive::OVERWRITE);
            foreach ($idler as $id) {
                $zip->addFromString("F{$id}.xml", $this->ubller[$id]);
            }
            $zip->close();
            $icerik = base64_encode((string) file_get_contents($yol));
            unlink($yol);

            return Http::response(['data' => ['filename' => 'x.zip', 'content' => $icerik], 'error' => null]);
        });
    }

    /**
     * @param  array<string, mixed>  $alanlar
     */
    private function fatura(EntegratorBaglanti $tanim, int $kaynakId, array $alanlar = [], string ...$kodlar): EFatura
    {
        $ettn = sprintf('aaaaaaaa-0000-0000-0000-%012d', $kaynakId);
        $this->ubller[$kaynakId] = $this->ubl(strtoupper($ettn), ...$kodlar);

        return EFatura::factory()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'kaynak_id' => $kaynakId,
            'ettn' => $ettn,
            'fatura_tipi' => 'ISTISNA',
            'vergi_tutari' => '0.0000',
            // ERP'nin çekmesi için tanınan süre geçmiş: havuzda yoksa İzibiz'e gidilir
            'created_at' => now()->subDays(2),
            ...$alanlar,
        ]);
    }

    public function test_istisna_kodlari_toplu_okunur_ve_fatura_bir_kez_istenir(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $tekKod = $this->fatura($tanim, 101, [], '318');
        $ikiKod = $this->fatura($tanim, 102, [], '308', '351');
        $kodsuz = $this->fatura($tanim, 103, ['fatura_tipi' => 'SATIS']);
        // Aday değil: vergisi olan satış faturası
        $vergili = $this->fatura($tanim, 104, ['fatura_tipi' => 'SATIS', 'vergi_tutari' => '18.0000']);
        // Aday değil: giden fatura
        $this->fatura($tanim, 105, ['yon' => 'giden']);
        $this->sahteIzibiz();

        $this->artisan('efatura:istisna-kodlari')
            ->expectsOutput('İstisna kodu [test]: ERP arşivinden 0 fatura (0 kodlu); İzibiz: 1 istek, 3 fatura okundu (2 kodlu), 0 okunamadı')
            ->assertSuccessful();

        $this->assertSame([[103, 102, 101]], $this->istenenler);
        $this->assertSame('318', $tekKod->fresh()->izibiz_istisna_kodu);
        $this->assertSame('308,351', $ikiKod->fresh()->izibiz_istisna_kodu);
        $this->assertNull($kodsuz->fresh()->izibiz_istisna_kodu);
        $this->assertNotNull($kodsuz->fresh()->izibiz_ubl_okundu);
        $this->assertNull($vergili->fresh()->izibiz_ubl_okundu);

        // Okunmuş fatura bir daha istenmez
        $this->artisan('efatura:istisna-kodlari')->assertSuccessful();
        $this->assertCount(1, $this->istenenler);
    }

    public function test_sorunlu_fatura_partiyi_bozunca_bolunur_digerleri_okunur(): void
    {
        config(['efatura.istisna_parti_boyutu' => 4, 'efatura.istisna_azami_istek' => 10]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        foreach ([201, 202, 203, 204] as $id) {
            $this->fatura($tanim, $id, [], '351');
        }
        $this->sorunlular = [203];
        $this->sahteIzibiz();

        $this->artisan('efatura:istisna-kodlari')
            ->expectsOutput('İstisna kodu [test]: ERP arşivinden 0 fatura (0 kodlu); İzibiz: 5 istek, 3 fatura okundu (3 kodlu), 1 okunamadı')
            ->assertSuccessful();

        // [204,203,202,201] → [204,203] + [202,201] → [204] + [203]
        $this->assertSame([[204, 203, 202, 201], [204, 203], [204], [203], [202, 201]], $this->istenenler);
        $sorunlu = EFatura::query()->where('kaynak_id', 203)->firstOrFail();
        $this->assertNull($sorunlu->izibiz_ubl_okundu);
        $this->assertSame(1, $sorunlu->izibiz_ubl_hata);

        // Okunamayan fatura hemen yeniden istenmez (bir gün sonra)
        $this->artisan('efatura:istisna-kodlari')->assertSuccessful();
        $this->assertCount(5, $this->istenenler);
    }

    public function test_calisma_basina_istek_sayisi_sinirlidir(): void
    {
        config(['efatura.istisna_parti_boyutu' => 2, 'efatura.istisna_azami_istek' => 2]);
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        foreach (range(301, 306) as $id) {
            $this->fatura($tanim, $id, [], '318');
        }
        $this->sahteIzibiz();

        $this->artisan('efatura:istisna-kodlari')->assertSuccessful();

        $this->assertCount(2, $this->istenenler);
        $this->assertSame(4, EFatura::query()->whereNotNull('izibiz_ubl_okundu')->count());
    }

    // --- Önce ERP havuzundaki UBL ------------------------------------------------

    public function test_havuzdaki_faturanin_kodu_erp_xmlinden_okunur_izibize_gidilmez(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $havuzdaKodlu = $this->fatura($tanim, 401, [], '350');
        $havuzdaKodsuz = $this->fatura($tanim, 402, ['fatura_tipi' => 'SATIS']);
        // İzibiz'de üç kez okunamamış fatura ERP'den okunabilir
        $izibizdeOkunamayan = $this->fatura($tanim, 403, ['izibiz_ubl_hata' => 3], '318');
        // Havuzda yok, yeni: ERP'nin çekmesi beklenir
        $yeni = $this->fatura($tanim, 404, ['created_at' => now()], '351');
        // Havuzda yok, bekleme süresi geçmiş: İzibiz'den
        $eski = $this->fatura($tanim, 405, [], '308');
        $this->sahteIzibiz();
        $this->erpHavuzu([
            $havuzdaKodlu->ettn => $this->ubller[401],
            $havuzdaKodsuz->ettn => null,
            $izibizdeOkunamayan->ettn => $this->ubller[403],
        ]);

        $this->artisan('efatura:istisna-kodlari')
            ->expectsOutput('İstisna kodu [test]: ERP arşivinden 3 fatura (2 kodlu); İzibiz: 1 istek, 1 fatura okundu (1 kodlu), 0 okunamadı')
            ->assertSuccessful();

        $this->assertSame([[405]], $this->istenenler);
        $this->assertSame('350', $havuzdaKodlu->fresh()->izibiz_istisna_kodu);
        $this->assertNull($havuzdaKodsuz->fresh()->izibiz_istisna_kodu);
        $this->assertNotNull($havuzdaKodsuz->fresh()->izibiz_ubl_okundu);
        $this->assertSame('318', $izibizdeOkunamayan->fresh()->izibiz_istisna_kodu);
        $this->assertNull($yeni->fresh()->izibiz_ubl_okundu);
        $this->assertSame('308', $eski->fresh()->izibiz_istisna_kodu);

        // ERP bir gün içinde çekmezse yeni fatura da İzibiz'den okunur
        $this->travel(25)->hours();
        $this->artisan('efatura:istisna-kodlari')->assertSuccessful();

        $this->assertSame([[405], [404]], $this->istenenler);
        $this->assertSame('351', $yeni->fresh()->izibiz_istisna_kodu);
    }

    public function test_erpdeki_xmli_okunamayan_fatura_gunde_bir_denenir(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = $this->fatura($tanim, 601, [], '318');
        $this->sahteIzibiz();
        // Havuzda ama XML'i başka bir faturanın
        $this->erpHavuzu([$fatura->ettn => $this->ubl('BBBBBBBB-0000-0000-0000-000000000601', '318')]);

        $this->artisan('efatura:istisna-kodlari')->assertSuccessful();

        $this->assertNull($fatura->fresh()->izibiz_ubl_okundu);
        $this->assertNotNull($fatura->fresh()->izibiz_ubl_son_deneme);
        // Aynı gün ne ERP'den yeniden ne İzibiz'den okunur
        $this->assertSame([], $this->istenenler);
    }

    public function test_doctypeli_ubl_okunmaz(): void
    {
        $xml = '<?xml version="1.0"?><!DOCTYPE Invoice [<!ENTITY x "318">]>'
            .substr($this->ubl('AAAAAAAA-0000-0000-0000-000000000701', '318'), strlen('<?xml version="1.0" encoding="UTF-8"?>'));
        $yol = (string) tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($yol, ZipArchive::OVERWRITE);
        $zip->addFromString('F701.xml', $xml);
        $zip->close();
        $icerik = (string) file_get_contents($yol);
        unlink($yol);

        $this->assertSame([], app(IzibizIstisnaKoduServisi::class)->kodlariAyikla($icerik));
    }

    public function test_erpye_ulasilamazsa_yeni_fatura_beklemeden_izibizden_okunur(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = $this->fatura($tanim, 501, ['created_at' => now()], '318');
        $this->sahteIzibiz();
        $this->erpHavuzu([], new RuntimeException('SELECT permission was denied'));

        $this->artisan('efatura:istisna-kodlari')
            ->expectsOutput('İstisna kodu [test]: ERP arşivinden 0 fatura (0 kodlu); İzibiz: 1 istek, 1 fatura okundu (1 kodlu), 0 okunamadı')
            ->assertSuccessful();

        $this->assertSame([[501]], $this->istenenler);
        $this->assertSame('318', $fatura->fresh()->izibiz_istisna_kodu);
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
