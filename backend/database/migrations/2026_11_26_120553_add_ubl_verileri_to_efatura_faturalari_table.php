<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faturanın UBL'inden okunan rapor verileri (kullanıcı kararı 2026-11-26: XML
 * saklanmaz, içindeki veriler tutulur). Tutarlar belge para biriminde:
 * - satir_toplami: LegalMonetaryTotal/LineExtensionAmount (mal/hizmet toplamı)
 * - indirim_tutari: AllowanceTotalAmount
 * - vergi_haric_tutar: TaxExclusiveAmount (KDV matrahı toplamı)
 * - vergi_dahil_tutar: TaxInclusiveAmount
 * - kur: PricingExchangeRate/CalculationRate (yalnız dövizli faturada)
 * Oran bazında vergiler `efatura_vergi_kalemleri`'nde.
 *
 * `izibiz_ubl_*` okuma durumu alanları sağlayıcıdan bağımsız `ubl_*` olur
 * (Protel de aynı okumayı yapar). Önce yalnız istisna kodu için okunmuş
 * faturalar yeni alanlar için yeniden okunsun diye durumları sıfırlanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->renameColumn('izibiz_ubl_okundu', 'ubl_okundu');
        });
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->renameColumn('izibiz_ubl_hata', 'ubl_hata');
        });
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->renameColumn('izibiz_ubl_son_deneme', 'ubl_son_deneme');
        });

        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->decimal('satir_toplami', 20, 4)->nullable();
            $table->decimal('indirim_tutari', 20, 4)->nullable();
            $table->decimal('vergi_haric_tutar', 20, 4)->nullable();
            $table->decimal('vergi_dahil_tutar', 20, 4)->nullable();
            $table->decimal('kur', 20, 6)->nullable();
            $table->text('satici_vergi_dairesi')->nullable();
        });

        DB::table('efatura_faturalari')
            ->whereNotNull('ubl_okundu')
            ->orWhere('ubl_hata', '>', 0)
            ->update(['ubl_okundu' => null, 'ubl_hata' => 0, 'ubl_son_deneme' => null]);
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn(['satir_toplami', 'indirim_tutari', 'vergi_haric_tutar', 'vergi_dahil_tutar', 'kur', 'satici_vergi_dairesi']);
        });
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->renameColumn('ubl_okundu', 'izibiz_ubl_okundu');
        });
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->renameColumn('ubl_hata', 'izibiz_ubl_hata');
        });
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->renameColumn('ubl_son_deneme', 'izibiz_ubl_son_deneme');
        });
    }
};
