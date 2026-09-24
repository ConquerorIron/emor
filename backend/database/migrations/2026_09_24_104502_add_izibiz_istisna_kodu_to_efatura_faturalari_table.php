<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vergi istisna kodunun entegratördeki (asıl) değeri — faturanın UBL'inden
 * (cbc:TaxExemptionReasonCode), toplu indirme ile fatura başına BİR kez okunur
 * (kullanıcı kararı 2026-09-24: entegratördeki bilgi esas, ERP'deki yanında
 * gösterilir). Birden çok kod virgülle birleştirilir.
 *
 * - izibiz_ubl_okundu: UBL başarıyla okundu (kod olmasa da) — tekrar istenmez
 * - izibiz_ubl_hata / izibiz_ubl_son_deneme: okunamayan fatura bir gün sonra
 *   yeniden denenir, birkaç denemeden sonra bırakılır
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->string('izibiz_istisna_kodu', 100)->nullable();
            $table->timestampTz('izibiz_ubl_okundu')->nullable();
            $table->unsignedSmallInteger('izibiz_ubl_hata')->default(0);
            $table->timestampTz('izibiz_ubl_son_deneme')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn(['izibiz_istisna_kodu', 'izibiz_ubl_okundu', 'izibiz_ubl_hata', 'izibiz_ubl_son_deneme']);
        });
    }
};
