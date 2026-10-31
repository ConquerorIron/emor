<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tutanak Kesintisi icmali (Excel: "İlave Tutanak Kesintileri"). Kolonlar
 * kullanıcının listesi (2026-10-31); Excel'deki malzeme / işçilik tutarı ve
 * durumu tutulmaz. Ortak kolonlar diğer icmallerle aynı addadır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tutanak_kesintileri', function (Blueprint $table) {
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
            $table->string('kaynak_tutanak_no', 100)->nullable();
            $table->string('kaynak_sozlesme', 100)->nullable();
            $table->string('kaynak_hakedis_no', 100)->nullable();
            $table->string('aktivite', 200)->nullable();
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
        Schema::dropIfExists('tutanak_kesintileri');
    }
};
