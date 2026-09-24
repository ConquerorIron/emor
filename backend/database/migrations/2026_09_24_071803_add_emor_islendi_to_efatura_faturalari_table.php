<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMOR kolonu: gelen fatura ERP'ye işlenmiş mi (TOHOM_FATURA.E_FATURA_ETTN
 * eşleşmesi). null = henüz kontrol edilmedi; efatura:emor komutu tazeler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->boolean('emor_islendi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn('emor_islendi');
        });
    }
};
