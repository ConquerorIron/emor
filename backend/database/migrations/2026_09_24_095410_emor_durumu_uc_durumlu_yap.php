<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * eMOR üç durumlu (kullanıcı kararı 2026-09-24): ERP gelen faturayı önce
 * TOHOM_E_FATURA havuzuna alır, muhasebe kabulü TOHOM_FATURA'dadır.
 * `emor_durumu`: islendi | havuzda | yok | null (henüz kontrol edilmedi).
 * Eski `emor_islendi` bayrağı taşınır: true → islendi, false → yok (havuz
 * bilgisi ilk efatura:emor çalışmasında gelir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->string('emor_durumu', 16)->nullable();
        });

        DB::table('efatura_faturalari')->where('emor_islendi', true)->update(['emor_durumu' => 'islendi']);
        DB::table('efatura_faturalari')->where('emor_islendi', false)->update(['emor_durumu' => 'yok']);

        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn('emor_islendi');
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->boolean('emor_islendi')->nullable();
        });

        DB::table('efatura_faturalari')->where('emor_durumu', 'islendi')->update(['emor_islendi' => true]);
        DB::table('efatura_faturalari')->whereIn('emor_durumu', ['havuzda', 'yok'])->update(['emor_islendi' => false]);

        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn('emor_durumu');
        });
    }
};
