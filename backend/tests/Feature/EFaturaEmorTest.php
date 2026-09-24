<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\Entegrator\EmorDurumu;
use App\Services\ErpFaturaKaynagi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

/**
 * eMOR kolonu: e-faturanın ERP'de karşılığı var mı. Gelen: TOHOM_FATURA
 * E_FATURA_ETTN; giden: ERP_GONDERILEN_E_FATURA_LISTESI (ETTN, yoksa belge no + VKN).
 */
final class EFaturaEmorTest extends TestCase
{
    use RefreshDatabase;

    private const ISLENMIS = 'eeb4f1a9-9bc7-4576-beb6-00000000000a';

    /**
     * @param  list<string>|RuntimeException  $gelen
     * @param  list<array{ettn: string|null, belge_no: string, vkn: string}>|RuntimeException  $giden
     * @param  array<string, array{istisna_kodu: string|null, gonderici_etiketi: string|null, alici_etiketi: string|null}>  $havuz  TOHOM_E_FATURA: ETTN => ERP bilgileri
     * @param  list<array{belge_no: string, vkn: string}>  $belgeler  TOHOM_FATURA / TOHOM_HARCAMA_BELGESI: fatura no + VKN
     */
    private function erp(array|RuntimeException $gelen, array|RuntimeException $giden = [], array $havuz = [], array $belgeler = []): void
    {
        $this->app->instance(ErpFaturaKaynagi::class, new class($gelen, $giden, $havuz, $belgeler) implements ErpFaturaKaynagi
        {
            /**
             * @param  list<string>|RuntimeException  $gelen
             * @param  list<array{ettn: string|null, belge_no: string, vkn: string}>|RuntimeException  $giden
             * @param  array<string, array{istisna_kodu: string|null, gonderici_etiketi: string|null, alici_etiketi: string|null}>  $havuz
             * @param  list<array{belge_no: string, vkn: string}>  $belgeler
             */
            public function __construct(
                private readonly array|RuntimeException $gelen,
                private readonly array|RuntimeException $giden,
                private readonly array $havuz,
                private readonly array $belgeler,
            ) {}

            public function islenmisGelenBelgeler(): array
            {
                return $this->belgeler;
            }

            public function islenmisGelenEttnler(): array
            {
                return $this->gelen instanceof RuntimeException ? throw $this->gelen : $this->gelen;
            }

            public function havuzdakiGelenler(): array
            {
                return $this->havuz;
            }

            public function gonderilenFaturalar(): array
            {
                return $this->giden instanceof RuntimeException ? throw $this->giden : $this->giden;
            }
        });
    }

    private function tanim(): EntegratorBaglanti
    {
        return EntegratorBaglanti::factory()->aktif()->create();
    }

    public function test_erpde_ettni_olan_gelen_fatura_islendi_digerleri_islenmedi_olur(): void
    {
        $tanim = $this->tanim();
        // İzibiz ETTN'i küçük harfle saklanır; ERP büyük harf dönebilir
        $islenmis = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $islenmemis = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);
        $this->erp([strtoupper(self::ISLENMIS)]);

        $this->artisan('efatura:emor')
            ->expectsOutput('eMOR gelen: işlendi 1, elle işlendi 0, havuzda 0, yok 1, değişen 2')
            ->expectsOutput('eMOR giden: işlendi 0, elle işlendi 0, havuzda 0, yok 0, değişen 0')
            ->assertSuccessful();

        $this->assertSame(EmorDurumu::Islendi, $islenmis->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::Yok, $islenmemis->fresh()->emor_durumu);
    }

    public function test_giden_fatura_ettn_ile_ya_da_ettnsiz_erp_satirinda_belge_no_ve_vkn_ile_eslesir(): void
    {
        $tanim = $this->tanim();
        $giden = fn (array $alanlar): EFatura => EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, ...$alanlar]);
        $ettnIle = $giden(['ettn' => self::ISLENMIS]);
        $noVknIle = $giden(['belge_no' => 'INS2026000000250', 'alici_vkn' => '0730433545']);
        // ERP satırında ETTN VAR ve farklı: belge no aynı olsa da eşleşmez
        $celisen = $giden(['belge_no' => 'KUR2026000000001', 'alici_vkn' => '1111111111']);
        $yok = $giden([]);
        // Gelen faturalar giden listesinden etkilenmez
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);

        $this->erp([], [
            ['ettn' => strtoupper(self::ISLENMIS), 'belge_no' => 'ABC', 'vkn' => '9'],
            ['ettn' => null, 'belge_no' => 'ins2026000000250', 'vkn' => '0730433545'],
            ['ettn' => 'ffffffff-0000-0000-0000-000000000001', 'belge_no' => 'KUR2026000000001', 'vkn' => '1111111111'],
        ]);

        $this->artisan('efatura:emor')
            ->expectsOutput('eMOR giden: işlendi 2, elle işlendi 0, havuzda 0, yok 2, değişen 4')
            ->assertSuccessful();

        $this->assertSame(EmorDurumu::Islendi, $ettnIle->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::Islendi, $noVknIle->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::Yok, $celisen->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::Yok, $yok->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::Yok, $gelen->fresh()->emor_durumu);
    }

    public function test_gelen_fatura_havuzdaysa_havuzda_muhasebelestiyse_islendi_ikisinde_de_yoksa_yok_olur(): void
    {
        $tanim = $this->tanim();
        $gelen = fn (string $ettn): EFatura => EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => $ettn]);
        $islendi = $gelen(self::ISLENMIS);
        $havuzda = $gelen('58795094-831a-435b-a43a-7d8f763d381b');
        $yok = $gelen('aaaaaaaa-0000-0000-0000-000000000009');

        // Muhasebeleşen fatura havuzda da durur; işlendi önce gelir
        $this->erp([self::ISLENMIS], havuz: [
            self::ISLENMIS => $this->havuzKaydi(),
            '58795094-831A-435B-A43A-7D8F763D381B' => $this->havuzKaydi(istisnaKodu: '351'),
        ]);

        $this->artisan('efatura:emor')
            ->expectsOutput('eMOR gelen: işlendi 1, elle işlendi 0, havuzda 1, yok 1, değişen 3')
            ->assertSuccessful();

        $this->assertSame(EmorDurumu::Islendi, $islendi->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::Havuzda, $havuzda->fresh()->emor_durumu);
        $this->assertSame('351', $havuzda->fresh()->vergi_istisna_kodu);
        $this->assertSame(EmorDurumu::Yok, $yok->fresh()->emor_durumu);
    }

    public function test_ettnsiz_elle_islenmis_fatura_no_ve_vkn_birlikte_eslesince_elle_islendi_olur(): void
    {
        $tanim = $this->tanim();
        $fatura = fn (string $no, string $vkn, string $ettn): EFatura => EFatura::factory()->create([
            'entegrator_baglanti_id' => $tanim->id, 'belge_no' => $no, 'gonderici_vkn' => $vkn, 'ettn' => $ettn,
        ]);
        // ETTN ile işlenmiş olan elle eşleşmeye bakılmadan işlendi
        $ettnIle = $fatura('ABC2026000000001', '1111111111', self::ISLENMIS);
        // Havuzdan silinip elle girilmiş (numara ERP'de küçük harf ve boşluklu)
        $elle = $fatura('ABC2026000000002', '1111111111', 'eeb4f1a9-9bc7-4576-beb6-00000000000b');
        // Havuzda duruyor ama elle işlenmiş: elle işlendi havuzdan önce gelir
        $havuzdaElle = $fatura('ABC2026000000003', '2222222222', 'eeb4f1a9-9bc7-4576-beb6-00000000000c');
        // Aynı numara başka firmanın: VKN tutmadıkça eşleşmez
        $baskaFirma = $fatura('ABC2026000000004', '3333333333', 'eeb4f1a9-9bc7-4576-beb6-00000000000d');
        $this->erp([self::ISLENMIS], havuz: [
            'eeb4f1a9-9bc7-4576-beb6-00000000000c' => $this->havuzKaydi(),
        ], belgeler: [
            ['belge_no' => 'ABC2026000000001', 'vkn' => '1111111111'],
            ['belge_no' => ' abc2026000000002 ', 'vkn' => '1111111111'],
            ['belge_no' => 'ABC2026000000003', 'vkn' => '2222222222'],
            ['belge_no' => 'ABC2026000000004', 'vkn' => '9999999999'],
        ]);

        $this->artisan('efatura:emor')
            ->expectsOutput('eMOR gelen: işlendi 1, elle işlendi 2, havuzda 0, yok 1, değişen 4')
            ->assertSuccessful();

        $this->assertSame(EmorDurumu::Islendi, $ettnIle->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::ElleIslendi, $elle->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::ElleIslendi, $havuzdaElle->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::Yok, $baskaFirma->fresh()->emor_durumu);
    }

    public function test_islenmeyenler_filtresi_elle_islenenleri_de_islenmis_sayar(): void
    {
        $tanim = $this->tanim();
        $yok = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_durumu' => 'yok']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_durumu' => 'elle_islendi']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_durumu' => 'islendi']);

        $this->actingAs(User::factory()->yonetici()->create())
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31&emor=islenmemis')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $yok->id);
    }

    public function test_gelen_faturanin_vergi_istisna_kodu_erpden_ettn_ile_yazilir_ve_kalkinca_silinir(): void
    {
        $tanim = $this->tanim();
        $istisnali = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS, 'fatura_tipi' => 'ISTISNA']);
        $digeri = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);
        // Giden faturaya gelen kodu yazılmaz
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);

        $this->erp([], havuz: [strtoupper(self::ISLENMIS) => $this->havuzKaydi(istisnaKodu: '318')]);
        $this->artisan('efatura:emor')->assertSuccessful();

        $this->assertSame('318', $istisnali->fresh()->vergi_istisna_kodu);
        $this->assertNull($digeri->fresh()->vergi_istisna_kodu);
        $this->assertNull($giden->fresh()->vergi_istisna_kodu);

        $this->erp([]);
        $this->artisan('efatura:emor')->assertSuccessful();
        $this->assertNull($istisnali->fresh()->vergi_istisna_kodu);

        $this->actingAs(User::factory()->yonetici()->create())
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31')
            ->assertJsonPath('data.0.vergi_istisna_kodu', null);
    }

    public function test_gb_pk_etiketi_izibizde_yoksa_erp_havuzundan_gosterilir(): void
    {
        $tanim = $this->tanim();
        $bos = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS, 'belge_no' => 'A1']);
        // İzibiz etiketi varsa o önceliklidir
        $izibizli = EFatura::factory()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'ettn' => 'aaaaaaaa-0000-0000-0000-000000000002',
            'belge_no' => 'A2',
            'gonderici_etiketi' => 'urn:mail:izibizgb@ornek.test',
        ]);
        $this->erp([], havuz: [
            self::ISLENMIS => $this->havuzKaydi(gb: 'urn:mail:defaultgb@ornek.test', pk: 'urn:mail:defaultpk@bizim.test'),
            'aaaaaaaa-0000-0000-0000-000000000002' => $this->havuzKaydi(gb: 'urn:mail:erpgb@ornek.test'),
        ]);

        $this->artisan('efatura:emor')->assertSuccessful();

        $this->actingAs(User::factory()->yonetici()->create())
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31&sirala=belge_no&yon=asc')
            ->assertJsonPath('data.0.id', $bos->id)
            ->assertJsonPath('data.0.gonderici_etiketi', 'urn:mail:defaultgb@ornek.test')
            ->assertJsonPath('data.0.alici_etiketi', 'urn:mail:defaultpk@bizim.test')
            ->assertJsonPath('data.1.id', $izibizli->id)
            ->assertJsonPath('data.1.gonderici_etiketi', 'urn:mail:izibizgb@ornek.test');
    }

    /**
     * @return array{istisna_kodu: string|null, gonderici_etiketi: string|null, alici_etiketi: string|null}
     */
    private function havuzKaydi(?string $istisnaKodu = null, ?string $gb = null, ?string $pk = null): array
    {
        return ['istisna_kodu' => $istisnaKodu, 'gonderici_etiketi' => $gb, 'alici_etiketi' => $pk];
    }

    public function test_erpden_silinen_fatura_islenmedi_olur_ve_degismeyen_satir_yazilmaz(): void
    {
        $tanim = $this->tanim();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);

        $this->erp([self::ISLENMIS]);
        $this->artisan('efatura:emor')->expectsOutput('eMOR gelen: işlendi 1, elle işlendi 0, havuzda 0, yok 0, değişen 1');
        $this->artisan('efatura:emor')->expectsOutput('eMOR gelen: işlendi 1, elle işlendi 0, havuzda 0, yok 0, değişen 0');

        $this->erp([]);
        $this->artisan('efatura:emor')->expectsOutput('eMOR gelen: işlendi 0, elle işlendi 0, havuzda 0, yok 1, değişen 1');

        $this->assertSame(EmorDurumu::Yok, $fatura->fresh()->emor_durumu);
    }

    public function test_bir_yon_okunamazsa_bayraklari_degismez_digeri_tazelenir_komut_basarisiz_doner(): void
    {
        $tanim = $this->tanim();
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS, 'emor_durumu' => 'islendi']);
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        Log::spy();
        $this->erp(new RuntimeException('bağlantı zaman aşımı'), [['ettn' => self::ISLENMIS, 'belge_no' => 'X', 'vkn' => '1']]);

        $this->artisan('efatura:emor')->assertFailed();

        // "Okunamadı" asla "işlenmedi" sayılmaz
        $this->assertSame(EmorDurumu::Islendi, $gelen->fresh()->emor_durumu);
        $this->assertSame(EmorDurumu::Islendi, $giden->fresh()->emor_durumu);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_erp_senkronla_dugmesi_secilen_yonun_emorunu_hemen_tazeler(): void
    {
        $tanim = $this->tanim();
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $this->erp([self::ISLENMIS], [['ettn' => self::ISLENMIS, 'belge_no' => 'X', 'vkn' => '1']]);

        $this->actingAs(User::factory()->yonetici()->create())
            ->postJson('/api/v1/efatura/erp-senkron', ['yon' => 'giden'])
            ->assertOk()
            ->assertExactJson(['data' => ['islendi' => 1, 'elle_islendi' => 0, 'havuzda' => 0, 'yok' => 0, 'degisen' => 1]]);

        $this->assertSame(EmorDurumu::Islendi, $giden->fresh()->emor_durumu);
        $this->assertNull($gelen->fresh()->emor_durumu);
    }

    public function test_erp_senkronla_gecersiz_yonu_reddeder_yon_yoksa_gelen_sayar(): void
    {
        $tanim = $this->tanim();
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'ettn' => self::ISLENMIS]);
        $this->erp([self::ISLENMIS]);
        $yonetici = User::factory()->yonetici()->create();

        $this->actingAs($yonetici)
            ->postJson('/api/v1/efatura/erp-senkron', ['yon' => 'yanlis'])
            ->assertUnprocessable();

        // Deploy öncesi açık kalmış sekmenin düğmesi yön göndermiyordu
        $this->actingAs($yonetici)->postJson('/api/v1/efatura/erp-senkron')->assertOk();
        $this->assertSame(EmorDurumu::Islendi, $gelen->fresh()->emor_durumu);
    }

    public function test_erp_senkronla_erp_okunamazsa_502_doner_ve_bayrak_degismez(): void
    {
        $tanim = $this->tanim();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_durumu' => 'islendi']);
        $this->erp(new RuntimeException('bağlantı zaman aşımı'));

        $this->actingAs(User::factory()->yonetici()->create())
            ->postJson('/api/v1/efatura/erp-senkron', ['yon' => 'gelen'])
            ->assertStatus(502)
            ->assertJsonPath('kod', 'ERP_OKUNAMADI');

        $this->assertSame(EmorDurumu::Islendi, $fatura->fresh()->emor_durumu);
    }

    public function test_erp_senkronla_senkron_izni_ister(): void
    {
        $kullanici = User::factory()->create();
        $rol = Rol::query()->create(['ad' => 'Görüntüleyici']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'efatura.goruntule']);
        $kullanici->roller()->attach($rol);
        $this->erp([]);

        $this->actingAs($kullanici)->postJson('/api/v1/efatura/erp-senkron', ['yon' => 'gelen'])->assertForbidden();
    }

    public function test_liste_emor_bayragini_doner(): void
    {
        $tanim = $this->tanim();
        EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_durumu' => 'islendi']);

        $this->actingAs(User::factory()->yonetici()->create())
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31')
            ->assertOk()
            ->assertJsonPath('data.0.emor_durumu', 'islendi');
    }
}
