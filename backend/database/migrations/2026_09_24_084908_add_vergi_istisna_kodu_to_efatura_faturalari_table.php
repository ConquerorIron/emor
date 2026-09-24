<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gelen faturanın vergi (KDV) istisna kodu. İzibiz'in liste/detay yanıtında
 * yok (yalnız fatura XML'inde); ERP'nin TOHOM_E_FATURA.VERGI_ISTISNA_KODU
 * alanından ETTN ile okunur (efatura:emor ile birlikte tazelenir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->string('vergi_istisna_kodu', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn('vergi_istisna_kodu');
        });
    }
};
