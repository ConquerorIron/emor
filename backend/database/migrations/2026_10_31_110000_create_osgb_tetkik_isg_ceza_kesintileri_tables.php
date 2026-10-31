<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OSGB, Tetkik ve İSG Ceza Kesintisi icmalleri (Excel: "OSGB Kesintileri"
 * dosyasının OSGB, Sağlık Tetkikleri ve İSG Ceza sayfaları). Kolonlar
 * kullanıcının listesi (2026-10-31); ortak kolonlar diğer icmallerle aynı addadır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('osgb_kesintileri', function (Blueprint $table) {
            $this->ortakBas($table);
            // Excel'de "TOPLAM": günlük kişi sayılarının toplamı
            $table->decimal('toplam_kisi', 12, 2);
            $table->decimal('birim_fiyat', 18, 4);
            $this->ortakSon($table);
        });

        Schema::create('tetkik_kesintileri', function (Blueprint $table) {
            $this->ortakBas($table);
            $table->string('adi_soyadi', 200);
            $this->ortakSon($table);
        });

        Schema::create('isg_ceza_kesintileri', function (Blueprint $table) {
            $this->ortakBas($table);
            $this->ortakSon($table);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isg_ceza_kesintileri');
        Schema::dropIfExists('tetkik_kesintileri');
        Schema::dropIfExists('osgb_kesintileri');
    }

    /** Ortak baş kolonlar: parti, tanımlar, yansıtma, mahal, tarih, kaynak firma */
    private function ortakBas(Blueprint $table): void
    {
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
    }

    /** Ortak son kolonlar: 5 tutar, kaydeden, zaman damgaları */
    private function ortakSon(Blueprint $table): void
    {
        $table->decimal('toplam_tutar', 18, 2);
        $table->decimal('iptal_edilen_tutar', 18, 2);
        // Elle girişte hesaplanır (toplam − iptal), Excel'den olduğu gibi alınır
        $table->decimal('yansitilan_tutar', 18, 2);
        $table->decimal('kesilen_tutar', 18, 2);
        // Elle girişte hesaplanır (yansıtılan − kesilen), Excel'den olduğu gibi alınır
        $table->decimal('kalan_tutar', 18, 2);
        $table->foreignId('kaydeden_id')->nullable()->index()->constrained('users')->nullOnDelete();
        $table->timestamps();
    }
};
