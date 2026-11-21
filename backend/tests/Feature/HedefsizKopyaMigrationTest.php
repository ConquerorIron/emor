<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\KopyaDurumu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

/**
 * BY-028-R9: hedefi tanımsız kopya için `yedek_hedefi_id` boş bırakılabilir.
 * Yükseltmede var olan kopyalar ve plan bağları korunur (K6); hedefsiz kayıt
 * yedek başına olaydaki hedef anahtarıyla tekildir (G7) ve yalnız
 * `konum_belirsiz` olabilir (K3: model + PostgreSQL CHECK).
 */
final class HedefsizKopyaMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): Migration
    {
        /** @var Migration */
        return require database_path('migrations/2026_11_21_204545_add_olay_hedefi_to_yedek_kopyalari_table.php');
    }

    /**
     * @param  array<string, mixed>  $ek
     */
    private function hedefsizEkle(int $yedekId, string $anahtar, array $ek = []): void
    {
        DB::table('yedek_kopyalari')->insert([
            'yedek_id' => $yedekId, 'yedek_hedefi_id' => null, 'durum' => 'konum_belirsiz', 'olay_hedef_anahtari' => $anahtar,
            'uzak_kimlik' => 'u', 'created_at' => now(), 'updated_at' => now(), ...$ek,
        ]);
    }

    public function test_yukseltmede_kopyalar_ve_plan_baglari_korunur_hedefsiz_kayit_yedek_basina_anahtarla_tekil(): void
    {
        $migration = $this->migration();
        // Eski şema: hedef zorunlu, olay hedefi kolonları yok
        $migration->down();
        $this->assertFalse(Schema::hasColumn('yedek_kopyalari', 'olay_hedef_anahtari'));
        YedekKopyasi::factory()->create(['uzak_kimlik' => 'k-plan', 'konum_izi' => str_repeat('a', 64)]);
        YedekKopyasi::factory()->plansiz()->create(['uzak_kimlik' => 'k-elle', 'durum' => KopyaDurumu::Basarisiz]);
        $kolonlar = ['id', 'yedek_id', 'yedek_hedefi_id', 'durum', 'uzak_kimlik', 'konum_izi'];
        $oku = fn (string $tablo, array $k): array => DB::table($tablo)->orderBy('id')->get($k)->map(fn (object $s): array => (array) $s)->all();
        $kopyalar = $oku('yedek_kopyalari', $kolonlar);
        $baglar = $oku('yedek_kopya_planlari', ['yedek_kopyasi_id', 'yedek_hedefi_id', 'yedek_plani_id']);
        $this->assertCount(1, $baglar);

        $migration->up();

        $this->assertEquals($kopyalar, $oku('yedek_kopyalari', $kolonlar));
        $this->assertEquals($baglar, $oku('yedek_kopya_planlari', ['yedek_kopyasi_id', 'yedek_hedefi_id', 'yedek_plani_id']));

        $yedek = Yedek::factory()->create();
        $this->hedefsizEkle($yedek->id, 'id:9');
        $this->hedefsizEkle($yedek->id, 'ad:NAS');
        $this->expectException(UniqueConstraintViolationException::class);
        $this->hedefsizEkle($yedek->id, 'id:9');
    }

    public function test_hedefi_tanimsiz_kopya_konumu_belirsiz_disinda_kaydedilemez(): void
    {
        $yedek = Yedek::factory()->create();
        $kopya = new YedekKopyasi(['yedek_id' => $yedek->id, 'durum' => KopyaDurumu::Tamam, 'olay_hedef_anahtari' => 'id:9']);

        try {
            $kopya->save();
            $this->fail('Hedefsiz tamam kopya kaydedildi');
        } catch (LogicException) {
            $this->assertFalse($kopya->exists);
        }

        $this->expectException(LogicException::class);
        (new YedekKopyasi(['yedek_id' => $yedek->id, 'durum' => KopyaDurumu::KonumBelirsiz]))->save();
    }

    public function test_postgresqlde_hedefsiz_kopya_veritabaninda_da_yalniz_konumu_belirsiz_olabilir(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('CHECK kısıtı yalnız PostgreSQL\'de (SQLite\'ta model zorlar).');
        }

        $yedek = Yedek::factory()->create();
        $this->hedefsizEkle($yedek->id, 'id:9');

        $this->expectException(QueryException::class);
        $this->hedefsizEkle($yedek->id, 'id:10', ['durum' => 'tamam']);
    }
}
