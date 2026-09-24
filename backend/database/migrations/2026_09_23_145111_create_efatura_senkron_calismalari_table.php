<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * e-Fatura senkron çalışma günlüğü (EFAT-10): her okuma aralığı için kapsam,
 * adetler, tamlık ve hata. Ekranlarda "son başarılı senkron" ve güncellik
 * uyarısı buradan okunur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efatura_senkron_calismalari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entegrator_baglanti_id')->constrained('entegrator_baglantilari')->restrictOnDelete();
            $table->string('yon', 8);
            // DOCUMENT | DELIVERY
            $table->string('tarih_turu', 8);
            $table->date('baslangic');
            $table->date('bitis');
            // 'zamanlanmis' | 'manuel' | 'ilk_tarama'
            $table->string('tetikleyen', 16);
            $table->foreignId('kullanici_id')->nullable()->constrained('users')->nullOnDelete();
            // 'calisiyor' | 'tam' | 'eksik' | 'basarisiz'
            $table->string('durum', 12);
            $table->unsignedInteger('beklenen_adet')->nullable();
            $table->unsignedInteger('okunan_adet')->nullable();
            $table->unsignedInteger('yeni_adet')->nullable();
            $table->unsignedInteger('guncellenen_adet')->nullable();
            $table->unsignedInteger('hatali_adet')->nullable();
            $table->string('eksik_nedeni', 32)->nullable();
            $table->string('hata_kodu', 48)->nullable();
            $table->text('hata_mesaji')->nullable();
            $table->timestampTz('basladi');
            $table->timestampTz('bitti')->nullable();
            $table->timestampsTz();

            // "Son çalışma / son başarılı çalışma" sorguları
            $table->index(['entegrator_baglanti_id', 'yon', 'basladi']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efatura_senkron_calismalari');
    }
};
