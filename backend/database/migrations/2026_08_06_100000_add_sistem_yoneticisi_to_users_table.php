<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // ERP TOHOM_KULLANICI.SISTEM_YONETICISI yansıması — her girişte
            // tazelenir. Ekran tasarımı gibi yönetim ekranlarını bu bayrak açar.
            $table->boolean('sistem_yoneticisi')->default(false)->after('erp_kullanici_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sistem_yoneticisi');
        });
    }
};
