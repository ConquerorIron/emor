<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hedefi tanımsız kopya (BY-028-R9). Geri yüklenen eski veritabanında,
 * yedekten sonra eklenmiş bir hedefin tanımı yoktur; imzalı olay günlüğü o
 * hedefteki fiziksel kopyayı yine anar. Dizin kurulurken böyle bir kopya
 * atlanmaz: hedefsiz (`yedek_hedefi_id` boş), "konumu doğrulanamadı"
 * (`konum_belirsiz`) kayıt olarak tutulur — yedeği katalogda tutar, hiçbir
 * işlemin (geri yükleme, silme, saklama, kurtarma hazırlığı) kaynağı olmaz.
 *
 * - `olay_hedef_anahtari`: olaydaki hedef anahtarı (GuncelDurum::hedefAnahtari,
 *   "id:<n>" ya da "ad:<ad>"); hedefsiz kayıt yedek başına bu anahtarla tekildir
 *   (G7: `yedek_hedefi_id` boşken (yedek, hedef) benzersizliği tekrarı engellemez).
 * - `olay_hedef_ad`: olaydaki hedef adı, yalnız gösterim için.
 * - PostgreSQL'de CHECK: hedefsiz kayıt yalnız `konum_belirsiz` ve anahtarlı
 *   olabilir (model de aynı kuralı zorlar; SQLite'ta CHECK eklenemez — K3).
 *
 * Var olan satırlar değişmez (hepsinin hedefi var).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->planBaglariniKoruyarak(function (): void {
            Schema::table('yedek_kopyalari', function (Blueprint $table) {
                // Önceki öznitelikler korunur: foreignId = unsignedBigInteger; yabancı anahtar (RESTRICT) yerinde kalır
                $table->unsignedBigInteger('yedek_hedefi_id')->nullable()->change();
                $table->string('olay_hedef_anahtari', 110)->nullable();
                $table->string('olay_hedef_ad', 100)->nullable();
            });
        });

        DB::statement('CREATE UNIQUE INDEX yedek_kopyalari_hedefsiz_tekil ON yedek_kopyalari (yedek_id, olay_hedef_anahtari) WHERE yedek_hedefi_id IS NULL');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE yedek_kopyalari ADD CONSTRAINT yedek_kopyalari_hedefsiz_kullanilamaz CHECK (yedek_hedefi_id IS NOT NULL OR (durum = 'konum_belirsiz' AND olay_hedef_anahtari IS NOT NULL))");
        }
    }

    /**
     * Geri alma hedefsiz kayıtları siler (eski kod onları tanımaz; olay
     * günlüğünden türetildikleri için yeniden tarama eski davranışa döner:
     * kopya atlanır, sayaçta görünür).
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE yedek_kopyalari DROP CONSTRAINT IF EXISTS yedek_kopyalari_hedefsiz_kullanilamaz');
        }
        DB::statement('DROP INDEX IF EXISTS yedek_kopyalari_hedefsiz_tekil');
        DB::table('yedek_kopyalari')->whereNull('yedek_hedefi_id')->delete();

        Schema::table('yedek_kopyalari', function (Blueprint $table) {
            $table->dropColumn(['olay_hedef_anahtari', 'olay_hedef_ad']);
        });
        $this->planBaglariniKoruyarak(function (): void {
            Schema::table('yedek_kopyalari', function (Blueprint $table) {
                $table->unsignedBigInteger('yedek_hedefi_id')->nullable(false)->change();
            });
        });
    }

    /**
     * SQLite kolonu tabloyu yeniden kurarak değiştirir; işlem içinde yabancı
     * anahtar denetimi kapatılamadığı için eski tablo düşerken
     * `yedek_kopya_planlari` satırları CASCADE ile silinir. Bağlar önce
     * okunur, sonra aynı kimliklerle geri yazılır. PostgreSQL kolonu yerinde
     * değiştirir, bağlara dokunulmaz.
     */
    private function planBaglariniKoruyarak(Closure $degisiklik): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $degisiklik();

            return;
        }

        $baglar = DB::table('yedek_kopya_planlari')->orderBy('id')->get()->map(fn (object $s): array => (array) $s)->all();
        $degisiklik();
        DB::table('yedek_kopya_planlari')->delete();
        foreach (array_chunk($baglar, 500) as $parca) {
            DB::table('yedek_kopya_planlari')->insert($parca);
        }
    }
};
