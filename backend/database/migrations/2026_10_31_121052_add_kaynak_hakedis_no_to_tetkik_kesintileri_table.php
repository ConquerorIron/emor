<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tetkik Kesintisi'ne Kaynak Hakediş No (kullanıcı isteği 2026-10-31; Yemek
 * icmalindekiyle aynı tanım). Boş olabilir: mevcut satırlarda yok.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tetkik_kesintileri', function (Blueprint $table) {
            $table->string('kaynak_hakedis_no', 100)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tetkik_kesintileri', function (Blueprint $table) {
            $table->dropColumn('kaynak_hakedis_no');
        });
    }
};
