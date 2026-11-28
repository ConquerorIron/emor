<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaYuklemesi;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Statik kaynak ekranlarının (Logo, e-Arşiv / e-SMM — EK-07, EK-08) yetki ve
 * kapsam sınırı: rotalar döngüyle üretilir (routes/api.php), her uç kendi
 * işlem iznini ister; bir kaynağın rotası diğerinin kaydını bulmaz (inceleme A-1).
 */
final class StatikKaynakEkranTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Rolün izinleri TAM OLARAK bunlar (EFaturaEkranTest kalıbı) */
    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.md5(implode(',', $izinler))]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /**
     * Her uç, eksik olan TEK izinle: görüntüleme başka ekranın, işlem izni başka
     * işlemin ya da başka kaynağın.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function izinsizUclar(): array
    {
        $uclar = [];
        foreach (['logo' => ['logo_faturalari', 'earsiv_faturalari'], 'earsiv' => ['earsiv_faturalari', 'logo_faturalari']] as $onEk => [$izin, $diger]) {
            $uclar += [
                "{$onEk}: başka kaynağın izniyle liste" => ['GET', "/api/v1/{$onEk}/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31", ["{$diger}.goruntule"]],
                "{$onEk}: dışarı aktarma izni olmadan Excel" => ['GET', "/api/v1/{$onEk}/gelen/faturalar/excel?baslangic=2026-01-01&bitis=2026-01-31", ["{$izin}.goruntule", "{$izin}.isle"]],
                "{$onEk}: işleme izni olmadan İşlendi" => ['PUT', "/api/v1/{$onEk}/faturalar/1/islendi", ["{$izin}.goruntule", "{$izin}.red"]],
                "{$onEk}: RED izni olmadan RED" => ['PUT', "/api/v1/{$onEk}/faturalar/1/red", ["{$izin}.goruntule", "{$izin}.isle"]],
                "{$onEk}: başka kaynağın izniyle geçmiş" => ['GET', "/api/v1/{$onEk}/faturalar/1/gecmis", ["{$diger}.goruntule"]],
                "{$onEk}: içe aktarma izni olmadan kolonlar" => ['GET', "/api/v1/{$onEk}/ice-aktar/kolonlar", ["{$izin}.goruntule"]],
                "{$onEk}: içe aktarma izni olmadan kontrol" => ['POST', "/api/v1/{$onEk}/ice-aktar/kontrol", ["{$izin}.goruntule"]],
                "{$onEk}: başka kaynağın izniyle yüklemeler" => ['GET', "/api/v1/{$onEk}/yuklemeler", ["{$diger}.goruntule", "{$diger}.ice_aktar"]],
                "{$onEk}: başka kaynağın içe aktarma izniyle parti geri alma" => ['DELETE', "/api/v1/{$onEk}/yuklemeler/1", ["{$izin}.goruntule", "{$diger}.ice_aktar"]],
            ];
        }

        return $uclar;
    }

    /** @param  list<string>  $izinler */
    #[DataProvider('izinsizUclar')]
    public function test_ucun_izni_olmayan_kullanici_403_alir(string $yontem, string $adres, array $izinler): void
    {
        $this->actingAs($this->izinli(...$izinler))->json($yontem, $adres)->assertForbidden();
    }

    /**
     * Logo rotası e-Arşiv kaynağının faturasını ve partisini bulmaz: RED, İşlendi,
     * geçmiş ve parti geri alma 404; hiçbir kayıt değişmez.
     */
    public function test_bir_kaynagin_rotasi_digerinin_faturasini_ve_partisini_bulmaz_404(): void
    {
        $earsiv = app(EntegratorBaglantiServisi::class)->statikTanim(EntegratorBaglanti::SAGLAYICI_EARSIV);
        $parti = EFaturaYuklemesi::query()->create([
            'entegrator_baglanti_id' => $earsiv->id, 'belge_turu' => 'fatura', 'dosya_adi' => 'earsiv.xlsx',
            'kullanici_adi' => 'Ayşe', 'yeni' => 1, 'guncellenen' => 0, 'degismeyen' => 0,
        ]);
        $fatura = EFatura::factory()->earsiv()->create(['yukleme_id' => $parti->id]);
        $logocu = $this->izinli('logo_faturalari.goruntule', 'logo_faturalari.isle', 'logo_faturalari.red', 'logo_faturalari.ice_aktar');

        $this->actingAs($logocu)->putJson("/api/v1/logo/faturalar/{$fatura->id}/red", ['red' => true, 'neden' => 'Deneme'])->assertNotFound();
        $this->actingAs($logocu)->putJson("/api/v1/logo/faturalar/{$fatura->id}/islendi", ['islendi' => true])->assertNotFound();
        $this->actingAs($logocu)->getJson("/api/v1/logo/faturalar/{$fatura->id}/gecmis")->assertNotFound();
        $this->actingAs($logocu)->deleteJson("/api/v1/logo/yuklemeler/{$parti->id}")->assertNotFound();

        $this->assertDatabaseCount('efatura_yuklemeleri', 1);
        $this->assertDatabaseCount('efatura_faturalari', 1);
        $this->assertNull($fatura->fresh()->harici_red_zamani);
        $this->assertNull($fatura->fresh()->sap_islendi);
        $this->assertDatabaseCount('efatura_islem_gecmisi', 0);
    }

    /** Kimlik `int` parametreye sığmazsa 500 değil 404 (inceleme A-5). */
    public function test_on_sekiz_haneyi_asan_fatura_ve_parti_kimligi_404(): void
    {
        $yonetici = User::factory()->create(['sistem_yoneticisi' => true]);

        $this->actingAs($yonetici)->putJson('/api/v1/logo/faturalar/99999999999999999999/red', ['red' => true, 'neden' => 'Deneme'])->assertNotFound();
        $this->actingAs($yonetici)->deleteJson('/api/v1/earsiv/yuklemeler/99999999999999999999')->assertNotFound();
        $this->actingAs($yonetici)->getJson('/api/v1/tum-faturalar/faturalar/99999999999999999999/gecmis')->assertNotFound();
    }
}
