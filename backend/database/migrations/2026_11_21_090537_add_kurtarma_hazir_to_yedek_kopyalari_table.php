<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Şifreli (sunucu dışı) kopyanın KURTARMAYA HAZIR olup olmadığı (BY-010-R2,
 * BY-024-R3). `durum = tamam` yalnız aktarımın doğrulandığını söyler; boş
 * sunucuda geri yükleme için o hedefte yedeğin imzalı manifestini taşıyan
 * tamamlanmış bir kurtarma paketi de gerekir. Paket doğrulanınca, manifest
 * arşivine giren yedeklerin o hedefteki tamam kopyaları işaretlenir
 * (KurtarmaPaketi). Var olan satırlar `false` başlar: bir sonraki başarılı
 * paket onları da hazır yapar; o zamana kadar yerel arşiv silinmez (güvenli yön).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yedek_kopyalari', function (Blueprint $table) {
            $table->boolean('kurtarma_hazir')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('yedek_kopyalari', function (Blueprint $table) {
            $table->dropColumn('kurtarma_hazir');
        });
    }
};
