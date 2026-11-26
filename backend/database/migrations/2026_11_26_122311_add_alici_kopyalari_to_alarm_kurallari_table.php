<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alarm kurallarına kopya (CC) ve gizli kopya (BCC) alıcıları (müşteri
 * isteği 2026-11-26). `alicilar` To olarak kalır; mevcut satırlar boş
 * listeyle gelir (varsayılan), davranış değişmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alarm_kurallari', function (Blueprint $table) {
            $table->jsonb('alicilar_cc')->default('[]');
            $table->jsonb('alicilar_bcc')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('alarm_kurallari', function (Blueprint $table) {
            $table->dropColumn(['alicilar_cc', 'alicilar_bcc']);
        });
    }
};
