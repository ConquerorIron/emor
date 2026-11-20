<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Protel'in `isErpRecieved` bayrağı (otelin ERP'si faturayı aldı mı) `erp_okundu`
 * kolonuna yazılıyordu; o kolon eMOR'un okumasıdır (İzibiz `erpReadFlag`). Tüm
 * Faturalar'da Protel faturası "eMOR Okudu: Evet" görünüyordu (kullanıcı
 * bildirimi 2026-11-20). Protel bayrağı kendi kolonuna taşınır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->boolean('otel_erp_okundu')->nullable();
        });

        // SET'in sağ tarafı eski değerleri okur (PostgreSQL ve SQLite)
        DB::table('efatura_faturalari')
            ->whereIn('entegrator_baglanti_id', DB::table('entegrator_baglantilari')->where('saglayici', 'protel')->select('id'))
            ->update(['otel_erp_okundu' => DB::raw('erp_okundu'), 'erp_okundu' => null]);
    }

    public function down(): void
    {
        DB::table('efatura_faturalari')
            ->whereIn('entegrator_baglanti_id', DB::table('entegrator_baglantilari')->where('saglayici', 'protel')->select('id'))
            ->update(['erp_okundu' => DB::raw('otel_erp_okundu')]);

        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn('otel_erp_okundu');
        });
    }
};
