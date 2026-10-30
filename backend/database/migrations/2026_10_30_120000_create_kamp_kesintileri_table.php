<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kamp (oda) Kesintileri icmali (Excel: "Ortak - Kamp Kesintileri" dosyasının
 * "Ortak - Kamp" sayfası). Günlük (1–31) kolonlar ve açıklama tutulmaz
 * (kullanıcı kararı 2026-10-30); ortak kolonlar diğer icmallerle aynı addadır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kamp_kesintileri', function (Blueprint $table) {
            $table->id();
            // Elle girilen satırda boş
            $table->foreignId('yukleme_id')->nullable()->index()->constrained('kesinti_yuklemeleri')->cascadeOnDelete();
            $table->foreignId('kesinti_turu_id')->index()->constrained('kesinti_turleri')->restrictOnDelete();
            // Taşeron (kesinti yapılacak firma)
            $table->foreignId('firma_id')->index()->constrained('firmalar')->restrictOnDelete();
            $table->enum('yansitma_turu', ['hab', 'taseron']);
            $table->foreignId('proje_id')->index()->constrained('projeler')->restrictOnDelete();
            $table->foreignId('para_birimi_id')->index()->constrained('para_birimleri')->restrictOnDelete();
            $table->string('mahal', 200)->nullable();
            $table->date('tarih')->index();
            $table->string('kaynak_firma', 200)->nullable();
            $table->string('oda_tipi', 100);
            // Excel'deki "TOPLAM": günlük oda sayılarının toplamı
            $table->decimal('toplam_oda', 12, 2);
            $table->decimal('birim_fiyat', 18, 4);
            $table->decimal('toplam_tutar', 18, 2);
            $table->decimal('iptal_edilen_tutar', 18, 2);
            // Elle girişte hesaplanır (toplam − iptal), Excel'den olduğu gibi alınır
            $table->decimal('yansitilan_tutar', 18, 2);
            $table->decimal('kesilen_tutar', 18, 2);
            // Elle girişte hesaplanır (yansıtılan − kesilen), Excel'den olduğu gibi alınır
            $table->decimal('kalan_tutar', 18, 2);
            $table->foreignId('kaydeden_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kamp_kesintileri');
    }
};
