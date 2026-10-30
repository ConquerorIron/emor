<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesinti türünün bağlı olduğu icmal ekranı (kullanıcı kararı 2026-10-30):
 * bir ekrana yalnız ona bağlı türlerle satır girilir / aktarılır — Kamp
 * Excel'i yanlışlıkla İşgücü ekranına yapıştırılamaz. Bir ekrana birden
 * fazla tür bağlanabilir; bağsız tür hiçbir ekranda kullanılamaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kesinti_turleri', function (Blueprint $table) {
            // KesintiYuklemesi::ICMAL_* değerleri (ör. 'isgucu')
            $table->string('icmal', 30)->nullable()->index()->after('aciklama');
        });
    }

    public function down(): void
    {
        Schema::table('kesinti_turleri', function (Blueprint $table) {
            $table->dropColumn('icmal');
        });
    }
};
