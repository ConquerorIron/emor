<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Üç ayrı yaşam sinyali (README §4.8; BY-012): yerel yedek, sunucu dışı
 * kopya, sınama geri yüklemesi. Başarıdan sonra uygulama dışındaki
 * izleyicinin adresine istek gider; adres boşsa atlanır. İstek veri taşımaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yedek_ayarlari', function (Blueprint $table) {
            $table->string('sinyal_yerel_adresi', 500)->nullable();
            $table->string('sinyal_uzak_adresi', 500)->nullable();
            $table->string('sinyal_sinama_adresi', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('yedek_ayarlari', function (Blueprint $table) {
            $table->dropColumn(['sinyal_yerel_adresi', 'sinyal_uzak_adresi', 'sinyal_sinama_adresi']);
        });
    }
};
