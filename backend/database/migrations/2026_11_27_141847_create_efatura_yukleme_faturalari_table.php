<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İçe aktarım partisinin dokunduğu faturalar (Codex B-4-R1, R2): ekleyen,
 * güncelleyen ya da değişmeyen olarak eşleştiren her parti için bir satır.
 * Parti geri alınırken, eklediği bir faturaya hâlâ duran başka bir parti
 * dokunduysa 409 döner. Zaman karşılaştırması aynı saniyedeki iki yüklemeyi,
 * tek "son dokunan" alanı ise aradaki partiyi (A ekler, B ve C yeniden yükler,
 * C geri alınır → B'nin bağı) kaybediyordu.
 *
 * Parti silinince bağları, fatura silinince bağları gider (cascade). Bu tablodan
 * önce yazılmış faturanın hiç bağı yoktur: dokunanı bilinmez (D6), aynı
 * kaynakta sonraki parti varsa geri alma engellenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efatura_yukleme_faturalari', function (Blueprint $table): void {
            $table->foreignId('yukleme_id')->constrained('efatura_yuklemeleri')->cascadeOnDelete();
            $table->foreignId('efatura_id')->constrained('efatura_faturalari')->cascadeOnDelete();
            $table->primary(['yukleme_id', 'efatura_id']);
            // Geri almada "bu faturalara başka hangi parti dokundu" sorusu
            $table->index('efatura_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efatura_yukleme_faturalari');
    }
};
