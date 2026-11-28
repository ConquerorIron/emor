<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Yetkili kullanıcının gelen faturayı RED'e çekmesi / RED'i geri alması (EK-04):
 * her kaynakta, işlenmiş (eMOR ya da SAP) fatura hariç (2026-11-26), neden zorunlu,
 * kaynakta RED ya da "İşlendi" işaretli fatura çekilemez, her değişiklik
 * işlem geçmişine yazılır, dış sisteme istek gitmez.
 */
final class EFaturaRedTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ARALIK = 'baslangic=2026-01-01&bitis=2026-01-31';

    /** Rolün izinleri TAM OLARAK bunlar (EFaturaEkranTest kalıbı) */
    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.md5(implode(',', $izinler))]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create(['ad' => 'Ayşe Muhasebe']);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    public function test_yetkili_kullanici_faturayi_nedenle_red_e_ceker_hizli_filtreden_duser_geri_alinca_doner(): void
    {
        $this->travelTo('2026-11-25 09:30:00');
        Http::preventStrayRequests();
        $protel = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $fatura = EFatura::factory()->protel()->create([
            'entegrator_baglanti_id' => $protel->id,
            'belge_tarihi' => '2026-01-15',
            'emor_durumu' => 'yok',
            'belge_no' => 'ABC2026000000001',
        ]);
        $kullanici = $this->izinli('protel_faturalari.goruntule', 'protel_faturalari.red');
        $islenecekler = fn (): array => array_column(
            $this->actingAs($kullanici)->getJson('/api/v1/protel/gelen/faturalar?'.self::ARALIK.'&sinif=islenecek')->assertOk()->json('data'),
            'id',
        );
        $this->assertSame([$fatura->id], $islenecekler());

        $this->actingAs($kullanici)
            ->putJson("/api/v1/protel/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'Noter kanalıyla iptal edildi'])
            ->assertOk()
            ->assertJsonPath('data.red', [
                'var' => true,
                'kaynak' => 'harici',
                'zaman' => '2026-11-25T09:30:00+00:00',
                'eden' => 'Ayşe Muhasebe',
                'neden' => 'Noter kanalıyla iptal edildi',
            ]);

        $this->assertSame([], $islenecekler());
        $this->assertDatabaseHas('efatura_islem_gecmisi', [
            'fatura_id' => $fatura->id,
            'saglayici' => 'protel',
            'belge_no' => 'ABC2026000000001',
            'belge_kimligi' => $fatura->ettn,
            'islem' => 'red_cekildi',
            'neden' => 'Noter kanalıyla iptal edildi',
            'kullanici_id' => $kullanici->id,
            'kullanici_adi' => 'Ayşe Muhasebe',
        ]);

        $this->actingAs($kullanici)
            ->putJson("/api/v1/protel/faturalar/{$fatura->id}/red", ['red' => false])
            ->assertOk()
            ->assertJsonPath('data.red.var', false)
            ->assertJsonPath('data.red.kaynak', null);

        $this->assertSame([$fatura->id], $islenecekler());
        $this->assertDatabaseHas('efatura_islem_gecmisi', [
            'fatura_id' => $fatura->id,
            'islem' => 'red_geri_alindi',
            'onceki' => 'Noter kanalıyla iptal edildi',
        ]);
        $this->assertDatabaseCount('efatura_islem_gecmisi', 2);
        Http::assertNothingSent();
    }

    public function test_red_e_cekerken_neden_zorunludur_422(): void
    {
        $fatura = EFatura::factory()->protel()->create();

        $this->actingAs($this->izinli('protel_faturalari.goruntule', 'protel_faturalari.red'))
            ->putJson("/api/v1/protel/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => '   '])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.neden.0', 'RED\'e çekme nedeni yazılmalı.');

        $this->assertNull($fatura->fresh()->harici_red_zamani);
        $this->assertDatabaseCount('efatura_islem_gecmisi', 0);
    }

    /**
     * Kaynakta RED olmuş fatura ayrıca çekilmez; "İşlendi" işaretli fatura
     * işaret kaldırılmadan çekilmez (işaret sessizce silinmez).
     */
    public function test_kaynakta_red_ve_islendi_isaretli_fatura_red_e_cekilemez_409(): void
    {
        $protel = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $kaynaktaRed = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'durum' => 'RejectAnswerCompleted']);
        $islendi = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'sap_islendi' => true]);
        $kullanici = $this->izinli('protel_faturalari.goruntule', 'protel_faturalari.red');

        $this->actingAs($kullanici)
            ->putJson("/api/v1/protel/faturalar/{$kaynaktaRed->id}/red", ['red' => true, 'neden' => 'Deneme'])
            ->assertConflict()
            ->assertJsonPath('kod', 'FATURA_KAYNAKTA_REDDEDILMIS');
        $this->actingAs($kullanici)
            ->putJson("/api/v1/protel/faturalar/{$islendi->id}/red", ['red' => true, 'neden' => 'Deneme'])
            ->assertConflict()
            ->assertJsonPath('kod', 'FATURA_ISLENDI_ISARETLI');

        $this->assertNull($kaynaktaRed->fresh()->harici_red_zamani);
        $this->assertNull($islendi->fresh()->harici_red_zamani);
        $this->assertTrue($islendi->fresh()->sap_islendi);
        $this->assertDatabaseCount('efatura_islem_gecmisi', 0);
    }

    public function test_harici_red_faturasi_islendi_isaretlenemez_409(): void
    {
        $protel = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $fatura = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'harici_red_zamani' => '2026-11-20 10:00:00']);

        $this->actingAs($this->izinli('protel_faturalari.goruntule', 'protel_faturalari.isle'))
            ->putJson("/api/v1/protel/faturalar/{$fatura->id}/islendi", ['islendi' => true])
            ->assertConflict()
            ->assertJsonPath('kod', 'FATURA_REDDEDILDI');

        $this->assertNull($fatura->fresh()->sap_islendi);
    }

    /**
     * Zaten RED'e çekilmiş faturada ikinci istek ilk bilgiyi korur; ne geçmişe ne
     * denetim günlüğüne ikinci kayıt yazar (iki kaynak aynı olayı farklı anlatmasın).
     */
    public function test_zaten_red_olan_faturada_ilk_red_bilgisi_korunur_ikinci_istek_hicbir_yere_yazilmaz(): void
    {
        // İzibiz ekranı: "eMOR VKN" kolonu (ERP okuması) yok, günlüğe başka satır düşmez
        $fatura = EFatura::factory()->create(['emor_durumu' => 'havuzda']);
        $kullanici = $this->izinli('efatura.goruntule', 'efatura.red');
        $denetim = Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('denetim')->andReturn($denetim);

        $this->actingAs($kullanici)->putJson("/api/v1/efatura/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'İlk neden'])->assertOk();
        $this->actingAs($kullanici)->putJson("/api/v1/efatura/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'İkinci neden'])
            ->assertOk()
            ->assertJsonPath('data.red.neden', 'İlk neden');

        $this->assertDatabaseCount('efatura_islem_gecmisi', 1);
        $denetim->shouldHaveReceived('info')->with('e-Fatura RED\'e çekildi', Mockery::any())->once();
    }

    public function test_red_izni_olmayan_403_baska_tanimin_faturasi_404(): void
    {
        $gelen = EFatura::factory()->protel()->create();
        $izibizFaturasi = EFatura::factory()->create();
        $govde = ['red' => true, 'neden' => 'Deneme'];

        $this->actingAs($this->izinli('protel_faturalari.goruntule', 'protel_faturalari.isle'))
            ->putJson("/api/v1/protel/faturalar/{$gelen->id}/red", $govde)
            ->assertForbidden();
        $this->actingAs($this->izinli('protel_faturalari.goruntule', 'protel_faturalari.red'))
            ->putJson("/api/v1/protel/faturalar/{$izibizFaturasi->id}/red", $govde)
            ->assertNotFound();

        $this->assertSame(0, EFatura::query()->whereNotNull('harici_red_zamani')->count());
    }

    /** Tüm Faturalar'ın kendi RED izni ve ucu. */
    public function test_tum_faturalar_red_ucu_kendi_izniyle_calisir(): void
    {
        $protel = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $fatura = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'emor_durumu' => 'yok']);

        $this->actingAs($this->izinli('tum_faturalar.goruntule', 'protel_faturalari.red'))
            ->putJson("/api/v1/tum-faturalar/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'Deneme'])
            ->assertForbidden();
        $this->actingAs($this->izinli('tum_faturalar.goruntule', 'tum_faturalar.red'))
            ->putJson("/api/v1/tum-faturalar/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'İtiraz portalında iptal'])
            ->assertOk()
            ->assertJsonPath('data.red.kaynak', 'harici')
            ->assertJsonPath('data.saglayici', 'protel');

        $this->assertDatabaseHas('efatura_islem_gecmisi', ['fatura_id' => $fatura->id, 'saglayici' => 'protel', 'islem' => 'red_cekildi']);
    }

    /**
     * Her kaynağın gelen faturası kendi ekranından RED'e çekilir (kullanıcı
     * isteği 2026-11-26); eMOR'da İşlendi / İşlendi (elle) fatura çekilemez,
     * hiçbir şey yazılmaz. Havuzdaki fatura işlenmiş sayılmaz. Geri alma açıktır.
     */
    public function test_her_kaynak_kendi_ekraninda_red_e_cekilir_emorda_islenmis_fatura_409(): void
    {
        $izibizTanimi = EntegratorBaglanti::factory()->aktif()->create();
        $havuzda = EFatura::factory()->create(['entegrator_baglanti_id' => $izibizTanimi->id, 'emor_durumu' => 'havuzda']);
        $logo = EFatura::factory()->logo()->create(['emor_durumu' => 'yok']);
        $earsiv = EFatura::factory()->earsiv()->create(['emor_durumu' => 'yok']);
        $islendi = EFatura::factory()->create(['entegrator_baglanti_id' => $izibizTanimi->id, 'emor_durumu' => 'islendi']);
        $elleIslendi = EFatura::factory()->logo()->create(['emor_durumu' => 'elle_islendi']);
        $eskiRed = EFatura::factory()->create(['entegrator_baglanti_id' => $izibizTanimi->id, 'emor_durumu' => 'islendi', 'harici_red_zamani' => '2026-11-25 10:00:00', 'harici_red_nedeni' => 'Eski']);

        foreach ([['efatura', $havuzda], ['logo', $logo], ['earsiv', $earsiv]] as [$ekran, $fatura]) {
            $izin = ['efatura' => 'efatura', 'logo' => 'logo_faturalari', 'earsiv' => 'earsiv_faturalari'][$ekran];
            $this->actingAs($this->izinli("{$izin}.goruntule", "{$izin}.red"))
                ->putJson("/api/v1/{$ekran}/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'Karşı taraf iptal etti'])
                ->assertOk()
                ->assertJsonPath('data.red.var', true);
        }

        $tum = $this->izinli('tum_faturalar.goruntule', 'tum_faturalar.red');
        foreach ([$islendi, $elleIslendi] as $fatura) {
            $this->actingAs($tum)
                ->putJson("/api/v1/tum-faturalar/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'Deneme'])
                ->assertConflict()
                ->assertJsonPath('kod', 'FATURA_EMOR_ISLENMIS');
            $this->assertNull($fatura->fresh()->harici_red_zamani);
        }

        // Yeni yüklenmiş, eMOR durumu henüz okunmamış fatura: işlenmiş olabilir (B-6)
        $bilinmiyor = EFatura::factory()->logo()->create(['emor_durumu' => null]);
        $this->actingAs($tum)
            ->putJson("/api/v1/tum-faturalar/faturalar/{$bilinmiyor->id}/red", ['red' => true, 'neden' => 'Deneme'])
            ->assertConflict()
            ->assertJsonPath('kod', 'FATURA_EMOR_BILINMIYOR');
        $this->assertNull($bilinmiyor->fresh()->harici_red_zamani);

        $this->actingAs($tum)
            ->putJson("/api/v1/tum-faturalar/faturalar/{$eskiRed->id}/red", ['red' => false])
            ->assertOk()
            ->assertJsonPath('data.red.var', false);

        $this->assertEqualsCanonicalizing(
            [$havuzda->id, $logo->id, $earsiv->id],
            EFatura::query()->whereNotNull('harici_red_zamani')->pluck('id')->all(),
        );
        $this->assertDatabaseCount('efatura_islem_gecmisi', 4);
    }

    /**
     * "SAP (Manuel İşle)" İzibiz ekranında ve Tüm Faturalar'da da (kullanıcı
     * isteği 2026-11-26): kendi izniyle; İzibiz'in giden faturası işaretlenmez.
     */
    public function test_sap_manuel_isle_izibiz_ve_tum_faturalarda_kendi_izniyle_giden_faturada_404(): void
    {
        Http::preventStrayRequests();
        $izibizTanimi = EntegratorBaglanti::factory()->aktif()->create();
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $izibizTanimi->id]);
        $giden = EFatura::factory()->create(['entegrator_baglanti_id' => $izibizTanimi->id, 'yon' => 'giden']);
        $izibizci = $this->izinli('efatura.goruntule', 'efatura.isle');

        $this->actingAs($izibizci)
            ->putJson("/api/v1/efatura/faturalar/{$gelen->id}/islendi", ['islendi' => true])
            ->assertOk()
            ->assertJsonPath('data.sap_islendi', true);
        $this->actingAs($izibizci)
            ->putJson("/api/v1/efatura/faturalar/{$giden->id}/islendi", ['islendi' => true])
            ->assertNotFound();
        $this->actingAs($this->izinli('efatura.goruntule'))
            ->putJson("/api/v1/efatura/faturalar/{$gelen->id}/islendi", ['islendi' => false])
            ->assertForbidden();

        $tum = $this->izinli('tum_faturalar.goruntule', 'tum_faturalar.isle');
        $this->actingAs($tum)
            ->putJson("/api/v1/tum-faturalar/faturalar/{$gelen->id}/islendi", ['islendi' => false])
            ->assertOk()
            ->assertJsonPath('data.sap_islendi', null);
        $this->actingAs($this->izinli('tum_faturalar.goruntule'))
            ->putJson("/api/v1/tum-faturalar/faturalar/{$gelen->id}/islendi", ['islendi' => true])
            ->assertForbidden();

        $this->assertNull($giden->fresh()->sap_islendi);
        $this->assertDatabaseCount('efatura_islem_gecmisi', 2);
    }

    /**
     * Tüm Faturalar yalnız GELEN faturaların ekranıdır: İzibiz tanımının giden
     * faturası bu uçlardan bulunmaz (RED / SAP / PDF / XML / geçmiş — 404), hiçbir
     * şey yazılmaz, entegratöre istek gitmez (inceleme B-3).
     */
    public function test_tum_faturalar_uclari_giden_faturayi_bulmaz_404(): void
    {
        Http::preventStrayRequests();
        $izibizTanimi = EntegratorBaglanti::factory()->aktif()->create();
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $izibizTanimi->id]);
        $tum = $this->izinli('tum_faturalar.goruntule', 'tum_faturalar.red', 'tum_faturalar.isle', 'tum_faturalar.pdf');

        $this->actingAs($tum)->putJson("/api/v1/tum-faturalar/faturalar/{$giden->id}/red", ['red' => true, 'neden' => 'Deneme'])->assertNotFound();
        $this->actingAs($tum)->putJson("/api/v1/tum-faturalar/faturalar/{$giden->id}/islendi", ['islendi' => true])->assertNotFound();
        $this->actingAs($tum)->getJson("/api/v1/tum-faturalar/faturalar/{$giden->id}/pdf")->assertNotFound();
        $this->actingAs($tum)->getJson("/api/v1/tum-faturalar/faturalar/{$giden->id}/xml")->assertNotFound();
        $this->actingAs($tum)->getJson("/api/v1/tum-faturalar/faturalar/{$giden->id}/gecmis")->assertNotFound();

        $this->assertNull($giden->fresh()->harici_red_zamani);
        $this->assertNull($giden->fresh()->sap_islendi);
        $this->assertDatabaseCount('efatura_islem_gecmisi', 0);
        Http::assertNothingSent();
    }
}
