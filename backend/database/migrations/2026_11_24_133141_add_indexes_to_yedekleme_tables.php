<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gerçek sorgular için iki indeks (YF-54):
 *
 * - `geri_yuklemeler.oncesi_yedek_id`: sonradan eklenen yabancı anahtar
 *   indekssizdi (BY-004'teki "indekssiz yabancı anahtar yok" kuralı); saklama
 *   her geri yükleme öncesi yedek için bu kolonla arar.
 * - `yedek_islem_kayitlari (islem, created_at)`: özet şeridi
 *   `islem = … AND sonuc = …` için `max(created_at)`, İşlem Günlüğü süzgeci
 *   işleme göre okur; tablo yalnız büyür (salt eklemeli).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geri_yuklemeler', function (Blueprint $table) {
            $table->index('oncesi_yedek_id');
        });

        Schema::table('yedek_islem_kayitlari', function (Blueprint $table) {
            $table->index(['islem', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('yedek_islem_kayitlari', function (Blueprint $table) {
            $table->dropIndex(['islem', 'created_at']);
        });

        Schema::table('geri_yuklemeler', function (Blueprint $table) {
            $table->dropIndex(['oncesi_yedek_id']);
        });
    }
};
