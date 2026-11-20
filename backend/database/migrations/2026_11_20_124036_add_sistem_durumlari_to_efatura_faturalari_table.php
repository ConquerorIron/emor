<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tüm Faturalar'ın "hangi sisteme girildi" kolonları (kullanıcı isteği
 * 2026-11-20): Protel Okudu, Protel'de işlendi, SAP'de işlendi. Kaynak uçlar
 * (Protel ve SAP ekipleri) henüz yok; NULL = bilinmiyor. Ekranda bu alanlara
 * göre sıralanır, boşlar en sonda. Senkron bu alanlara yazmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->boolean('protel_okundu')->nullable();
            $table->boolean('protel_islendi')->nullable();
            $table->boolean('sap_islendi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn(['protel_okundu', 'protel_islendi', 'sap_islendi']);
        });
    }
};
