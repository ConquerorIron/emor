<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entegratör ayarları ekrandan (kullanıcı isteği 2026-09-24): otomatik senkron
 * aralığı ve tek istekteki fatura sayısı. Sınırlar İzibiz belgesinden
 * (zamanlayıcı en az 15 dk, tek çağrıda en çok 100 fatura) — EntegratorBaglanti.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entegrator_baglantilari', function (Blueprint $table) {
            $table->unsignedSmallInteger('senkron_araligi_dakika')->default(15);
            $table->unsignedSmallInteger('sayfa_boyutu')->default(100);
        });
    }

    public function down(): void
    {
        Schema::table('entegrator_baglantilari', function (Blueprint $table) {
            $table->dropColumn(['senkron_araligi_dakika', 'sayfa_boyutu']);
        });
    }
};
