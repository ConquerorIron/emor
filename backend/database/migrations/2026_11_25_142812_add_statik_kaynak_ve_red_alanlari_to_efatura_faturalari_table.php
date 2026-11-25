<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * e-Fatura ek işler (docs/efatura/Ek-Isler-Jira.md):
 *
 * - EK-05 Statik kaynaklar (Logo, e-Arşiv / e-SMM): veri senkronla değil Excel
 *   içe aktarımıyla gelir. Entegratör kimliği (`kaynak_id`) yoktur, e-Arşiv ve
 *   e-SMM'de ETTN de yoktur: ikisi boş bırakılabilir olur, boş alana uydurma
 *   değer yazılmaz. Statik satırın anahtarı `belge_anahtari`dır (Logo: küçük
 *   harfli ETTN; e-Arşiv: tür|kimlik no|belge no). Benzersizlik tam indeksle:
 *   entegratör satırlarında anahtar NULL olduğu için birbirini engellemez
 *   (PostgreSQL ve SQLite NULL'ları farklı sayar — burada istenen budur).
 *   Her satırda iki anahtardan biri dolu olmalı (PostgreSQL CHECK).
 * - EK-06 İçe aktarım partisi (`yukleme_id`): parti silinince eklediği satırlar gider.
 * - EK-04 Harici RED: yetkili kullanıcının RED'e çektiği fatura (entegratör
 *   dışı iptal — noter, iptal/itiraz portalı). Senkron ve içe aktarım bu
 *   alanlara dokunmaz (gizleme alanları gibi).
 *
 * SQLite'ta `change()` tabloyu yeniden kurar; çocuk tablosu (işlem geçmişi)
 * bu migration'dan SONRA oluşturulur ki yeniden kurulumda kayıtları etkilenmesin
 * (Dikkat_Edilecekler K6).
 */
return new class extends Migration
{
    private const KIMLIK_KISITI = 'efatura_faturalari_kimlik_check';

    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table): void {
            $table->unsignedBigInteger('kaynak_id')->nullable()->change();
            $table->string('ettn', 36)->nullable()->change();
            $table->string('belge_anahtari', 200)->nullable();
            // e-Arşiv tanımında 'fatura' | 'makbuz'; diğer kaynaklarda null
            $table->string('belge_turu', 16)->nullable();
            // e-SMM makbuzu: brüt ücret ve gelir vergisi stopajı (`tutar` = tahsilat)
            $table->decimal('brut_tutar', 20, 4)->nullable();
            $table->decimal('stopaj_tutari', 20, 4)->nullable();
            $table->foreignId('yukleme_id')->nullable()->constrained('efatura_yuklemeleri')->cascadeOnDelete();
            $table->timestampTz('harici_red_zamani')->nullable();
            // Kullanıcı silinse de RED kalır
            $table->foreignId('harici_red_eden_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('harici_red_nedeni')->nullable();

            // İçe aktarımın eşleştirme anahtarı (tanım başına tekil)
            $table->unique(['entegrator_baglanti_id', 'belge_anahtari']);
            // Parti geri alma ve "partinin satırları" sorgusu
            $table->index('yukleme_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE efatura_faturalari ADD CONSTRAINT '.self::KIMLIK_KISITI.' CHECK (kaynak_id IS NOT NULL OR belge_anahtari IS NOT NULL)');
        }
    }

    /**
     * Statik satır (kaynak_id boş) varken geri alma, boş bırakılamaz kolona
     * dönüşte durur: veri kaybettirmez.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE efatura_faturalari DROP CONSTRAINT IF EXISTS '.self::KIMLIK_KISITI);
        }

        Schema::table('efatura_faturalari', function (Blueprint $table): void {
            $table->dropUnique(['entegrator_baglanti_id', 'belge_anahtari']);
            $table->dropIndex(['yukleme_id']);
            $table->dropConstrainedForeignId('yukleme_id');
            $table->dropConstrainedForeignId('harici_red_eden_id');
            $table->dropColumn(['belge_anahtari', 'belge_turu', 'brut_tutar', 'stopaj_tutari', 'harici_red_zamani', 'harici_red_nedeni']);
        });

        Schema::table('efatura_faturalari', function (Blueprint $table): void {
            $table->unsignedBigInteger('kaynak_id')->nullable(false)->change();
            $table->string('ettn', 36)->nullable(false)->change();
        });
    }
};
