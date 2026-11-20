<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Üstüne geri yüklemenin aldığı "geri yükleme öncesi" yedek (BY-010, BY-028).
 * Saklama kuralı: o yedek, ilgili `erp_onceki_*` veritabanı silinene kadar
 * tutulur (README §4.3). Kopya türü geri yüklemede boş.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geri_yuklemeler', function (Blueprint $table) {
            $table->foreignId('oncesi_yedek_id')->nullable()->after('yedek_id')->constrained('yedekler')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('geri_yuklemeler', function (Blueprint $table) {
            $table->dropConstrainedForeignId('oncesi_yedek_id');
        });
    }
};
