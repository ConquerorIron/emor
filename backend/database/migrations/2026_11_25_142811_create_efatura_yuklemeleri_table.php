<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Statik kaynakların (Logo, e-Arşiv / e-SMM) içe aktarım partileri (EK-06).
 * Muhasebenin paylaştığı Excel listesi bir parti olarak yazılır; parti geri
 * alınınca yalnız EKLEDİĞİ faturalar silinir (efatura_faturalari.yukleme_id,
 * cascade). Güncellediği satırlar eski hâline dönmez.
 *
 * Fatura tablosu bu tabloya bağlandığı için önce oluşturulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efatura_yuklemeleri', function (Blueprint $table): void {
            $table->id();
            // Statik kaynak tanımı (Logo / e-Arşiv); tanım silinemez
            $table->foreignId('entegrator_baglanti_id')->constrained('entegrator_baglantilari')->restrictOnDelete();
            // e-Arşiv tanımında dosyanın türü: 'fatura' | 'makbuz' (Logo'da null)
            $table->string('belge_turu', 16)->nullable();
            $table->string('dosya_adi');
            // Kullanıcı silinse de partinin kimin olduğu okunur kalır
            $table->foreignId('kullanici_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kullanici_adi');
            $table->unsignedInteger('yeni');
            $table->unsignedInteger('guncellenen');
            $table->unsignedInteger('degismeyen');
            $table->timestampsTz();

            // "Yüklemeler" penceresi: tanımın partileri, en yeni üstte
            $table->index(['entegrator_baglanti_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efatura_yuklemeleri');
    }
};
