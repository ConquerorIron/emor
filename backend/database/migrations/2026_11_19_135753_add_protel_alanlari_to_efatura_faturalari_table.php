<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protel (otelin posta kutusu) faturalarına özgü iki durum alanı (PRT-06).
 * İkisi de yalnız GÖSTERİLİR; bu uygulama Protel'de hiçbir alanı değiştirmez.
 * İzibiz satırlarında boştur. Kimlik için yeni alan gerekmez: Protel'in iç
 * kimliği (`id`) `kaynak_id`'ye yazılır, mevcut benzersizlik geçerlidir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            // Protel `erpStatus`: New, Processing, Completed, Cancelled, Error, Booked
            $table->string('erp_durumu', 32)->nullable();
            // Protel `isPrinted`; PDF yalnız true iken açılır (PRT-09)
            $table->boolean('yazdirildi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn(['erp_durumu', 'yazdirildi']);
        });
    }
};
