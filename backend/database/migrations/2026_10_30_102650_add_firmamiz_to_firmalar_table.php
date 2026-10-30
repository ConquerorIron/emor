<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Firmamız" işareti (kullanıcı isteği 2026-10-30): firmamız (HAB) ERP'nin
 * taşeron listesinde olmadığından elle açılır ve işaretlenir. Yalnız tek
 * firma işaretli olabilir — kısmi benzersiz indeks garanti eder
 * (sql_baglantilari'ndaki tek aktif ortam deseni).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firmalar', function (Blueprint $table) {
            $table->boolean('firmamiz')->default(false);
        });

        DB::statement('CREATE UNIQUE INDEX firmalar_tek_firmamiz ON firmalar (firmamiz) WHERE firmamiz');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS firmalar_tek_firmamiz');

        Schema::table('firmalar', function (Blueprint $table) {
            $table->dropColumn('firmamiz');
        });
    }
};
