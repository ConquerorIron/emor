<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaIslemi;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/**
 * e-Fatura işlem geçmişi (EK-03): elle işlemler kullanıcı bazında, durum
 * değişikliğiyle aynı işlemde yazılır; kayıt değiştirilemez, silinemez;
 * fatura silinse de okunur kalır.
 */
final class EFaturaIslemGecmisiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ARALIK = 'baslangic=2026-11-01&bitis=2026-11-30';

    /** Rolün izinleri TAM OLARAK bunlar (EFaturaEkranTest kalıbı) */
    private function izinli(string $ad, string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.md5($ad.implode(',', $izinler))]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create(['ad' => $ad]);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    public function test_gizleme_ve_red_satirin_gecmisinde_en_yeni_ustte_kullanici_adiyla_gorunur(): void
    {
        $fatura = EFatura::factory()->protel()->create();
        $kullanici = $this->izinli('Ali Veli', 'protel_faturalari.goruntule', 'protel_faturalari.isle', 'protel_faturalari.red');

        $this->travelTo('2026-11-25 08:00:00');
        $this->actingAs($kullanici)->putJson("/api/v1/protel/faturalar/{$fatura->id}/islendi", ['islendi' => true])->assertOk();
        $this->travelTo('2026-11-25 08:05:00');
        $this->actingAs($kullanici)->putJson("/api/v1/protel/faturalar/{$fatura->id}/islendi", ['islendi' => false])->assertOk();
        $this->travelTo('2026-11-25 08:10:00');
        $this->actingAs($kullanici)->putJson("/api/v1/protel/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'İptal/itiraz portalı'])->assertOk();

        $gecmis = $this->actingAs($kullanici)->getJson("/api/v1/protel/faturalar/{$fatura->id}/gecmis")->assertOk()->json('data');

        $this->assertSame(
            [
                ['red_cekildi', 'Ali Veli', 'İptal/itiraz portalı', '2026-11-25T08:10:00+00:00'],
                ['islendi_kaldirildi', 'Ali Veli', null, '2026-11-25T08:05:00+00:00'],
                ['islendi_isaretlendi', 'Ali Veli', null, '2026-11-25T08:00:00+00:00'],
            ],
            array_map(fn (array $k): array => [$k['islem'], $k['kullanici_adi'], $k['neden'], $k['zaman']], $gecmis),
        );
    }

    public function test_gecmis_yazilamazsa_durum_degisikligi_de_geri_alinir(): void
    {
        $fatura = EFatura::factory()->protel()->create();
        EFaturaIslemi::creating(fn (): never => throw new RuntimeException('geçmiş yazılamadı'));

        $this->actingAs($this->izinli('Ali Veli', 'protel_faturalari.goruntule', 'protel_faturalari.red'))
            ->putJson("/api/v1/protel/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'Deneme'])
            ->assertServerError();

        $this->assertNull($fatura->fresh()->harici_red_zamani);
    }

    public function test_gecmis_kaydi_degistirilemez_silinemez(): void
    {
        $kayit = EFaturaIslemi::query()->create([
            'saglayici' => 'izibiz', 'islem' => 'gizlendi', 'kullanici_adi' => 'Ali Veli', 'created_at' => now(),
        ]);

        try {
            $kayit->update(['kullanici_adi' => 'Başkası']);
            $this->fail('Güncelleme reddedilmeliydi.');
        } catch (LogicException) {
        }
        try {
            $kayit->delete();
            $this->fail('Silme reddedilmeliydi.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('efatura_islem_gecmisi', ['id' => $kayit->id, 'kullanici_adi' => 'Ali Veli']);
    }

    public function test_fatura_silinse_de_gecmis_belge_kimligiyle_okunur(): void
    {
        $protel = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $fatura = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'belge_no' => 'OTL2026000000009']);
        $kullanici = $this->izinli('Ali Veli', 'protel_faturalari.goruntule', 'protel_faturalari.isle', 'efatura_islem_gecmisi.goruntule');
        $this->travelTo('2026-11-25 08:00:00');
        $this->actingAs($kullanici)->putJson("/api/v1/protel/faturalar/{$fatura->id}/islendi", ['islendi' => true])->assertOk();

        $fatura->delete();

        $this->actingAs($kullanici)->getJson('/api/v1/efatura/islem-gecmisi?'.self::ARALIK)
            ->assertOk()
            ->assertJsonPath('data.0.fatura_id', null)
            ->assertJsonPath('data.0.belge_no', 'OTL2026000000009')
            ->assertJsonPath('data.0.belge_kimligi', $fatura->ettn)
            ->assertJsonPath('data.0.saglayici', 'protel')
            ->assertJsonPath('data.0.islem', 'islendi_isaretlendi');
    }

    /**
     * Ekran süzgeçleri: tarih aralığı İstanbul günüdür (UTC 21:30 = ertesi gün
     * 00:30), kullanıcı, işlem türü ve belge no / neden araması.
     */
    public function test_islem_gecmisi_ekrani_istanbul_gunu_kullanici_islem_ve_aramayla_suzer(): void
    {
        $ali = User::factory()->create(['ad' => 'Ali Veli']);
        $ayse = User::factory()->create(['ad' => 'Ayşe Kaya']);
        $kayit = fn (User $u, string $islem, string $zaman, string $belgeNo, ?string $neden = null): int => EFaturaIslemi::query()->create([
            'saglayici' => 'izibiz', 'islem' => $islem, 'belge_no' => $belgeNo, 'neden' => $neden,
            'kullanici_id' => $u->id, 'kullanici_adi' => $u->ad, 'created_at' => $zaman,
        ])->id;
        $kasimSonu = $kayit($ali, 'red_cekildi', '2026-11-30 20:00:00', 'ABC1', 'Noter');
        $aralikBasi = $kayit($ali, 'gizlendi', '2026-11-30 21:30:00', 'ABC2');
        $ayseninki = $kayit($ayse, 'red_cekildi', '2026-11-10 09:00:00', 'XYZ9', 'İtiraz');
        $yetkili = $this->izinli('Yönetici', 'efatura_islem_gecmisi.goruntule');
        $idler = fn (string $sorgu): array => array_column(
            $this->actingAs($yetkili)->getJson('/api/v1/efatura/islem-gecmisi?'.$sorgu)->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$kasimSonu, $ayseninki], $idler(self::ARALIK));
        $this->assertSame([$aralikBasi], $idler('baslangic=2026-12-01&bitis=2026-12-01'));
        $this->assertSame([$ayseninki], $idler(self::ARALIK.'&kullanici_id='.$ayse->id));
        $this->assertSame([$kasimSonu, $ayseninki], $idler(self::ARALIK.'&islem=red_cekildi'));
        $this->assertSame([$kasimSonu], $idler(self::ARALIK.'&ara=noter'));
        $this->actingAs($yetkili)->getJson('/api/v1/efatura/islem-gecmisi?'.self::ARALIK)
            ->assertJsonPath('secenekler.kullanicilar', [['id' => $ali->id, 'ad' => 'Ali Veli'], ['id' => $ayse->id, 'ad' => 'Ayşe Kaya']]);
    }

    public function test_islem_gecmisi_ekrani_izinsiz_kullaniciya_403(): void
    {
        $this->actingAs($this->izinli('Ali Veli', 'efatura.goruntule'))
            ->getJson('/api/v1/efatura/islem-gecmisi?'.self::ARALIK)
            ->assertForbidden();
    }
}
