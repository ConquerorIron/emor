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
use Tests\TestCase;

/**
 * Yetkili kullanıcının gelen faturayı RED'e çekmesi / RED'i geri alması (EK-04):
 * neden zorunlu, kaynakta RED ya da "İşlendi" işaretli fatura çekilemez, her
 * değişiklik işlem geçmişine yazılır, dış sisteme istek gitmez.
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
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, 'emor_durumu' => 'yok', 'belge_no' => 'ABC2026000000001']);
        $kullanici = $this->izinli('efatura.goruntule', 'efatura.red');
        $islenecekler = fn (): array => array_column(
            $this->actingAs($kullanici)->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&sinif=islenecek')->assertOk()->json('data'),
            'id',
        );
        $this->assertSame([$fatura->id], $islenecekler());

        $this->actingAs($kullanici)
            ->putJson("/api/v1/efatura/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'Noter kanalıyla iptal edildi'])
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
            'saglayici' => 'izibiz',
            'belge_no' => 'ABC2026000000001',
            'belge_kimligi' => $fatura->ettn,
            'islem' => 'red_cekildi',
            'neden' => 'Noter kanalıyla iptal edildi',
            'kullanici_id' => $kullanici->id,
            'kullanici_adi' => 'Ayşe Muhasebe',
        ]);

        $this->actingAs($kullanici)
            ->putJson("/api/v1/efatura/faturalar/{$fatura->id}/red", ['red' => false])
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
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.red'))
            ->putJson("/api/v1/efatura/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => '   '])
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

    /** Zaten RED'e çekilmiş faturada ikinci istek ilk bilgiyi korur, geçmişe ikinci kayıt yazmaz. */
    public function test_zaten_red_olan_faturada_ilk_red_bilgisi_korunur(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);
        $kullanici = $this->izinli('efatura.goruntule', 'efatura.red');

        $this->actingAs($kullanici)->putJson("/api/v1/efatura/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'İlk neden'])->assertOk();
        $this->actingAs($kullanici)->putJson("/api/v1/efatura/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'İkinci neden'])
            ->assertOk()
            ->assertJsonPath('data.red.neden', 'İlk neden');

        $this->assertDatabaseCount('efatura_islem_gecmisi', 1);
    }

    public function test_red_izni_olmayan_403_giden_ve_baska_tanimin_faturasi_404(): void
    {
        $tanim = EntegratorBaglanti::factory()->aktif()->create();
        $giden = EFatura::factory()->giden()->create(['entegrator_baglanti_id' => $tanim->id]);
        $protelFaturasi = EFatura::factory()->protel()->create();
        $gelen = EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id]);
        $yetkili = $this->izinli('efatura.goruntule', 'efatura.red');
        $govde = ['red' => true, 'neden' => 'Deneme'];

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.gizle'))
            ->putJson("/api/v1/efatura/faturalar/{$gelen->id}/red", $govde)
            ->assertForbidden();
        $this->actingAs($yetkili)->putJson("/api/v1/efatura/faturalar/{$giden->id}/red", $govde)->assertNotFound();
        $this->actingAs($yetkili)->putJson("/api/v1/efatura/faturalar/{$protelFaturasi->id}/red", $govde)->assertNotFound();

        $this->assertSame(0, EFatura::query()->whereNotNull('harici_red_zamani')->count());
    }

    /** Tüm Faturalar'ın kendi RED izni ve ucu: satırın sağlayıcısından bağımsız aynı kural. */
    public function test_tum_faturalar_red_ucu_kendi_izniyle_calisir(): void
    {
        $protel = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $fatura = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id]);

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
}
