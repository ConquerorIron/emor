<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tüm Faturalar'dan "Protel Okudu" ve "Protel" kolonları kaldırıldı (kullanıcı
 * isteği 2026-11-20). Hiç doldurulmadılar (kaynak uç yoktu); SAP kolonu kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn(['protel_okundu', 'protel_islendi']);
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->boolean('protel_okundu')->nullable();
            $table->boolean('protel_islendi')->nullable();
        });
    }
};
