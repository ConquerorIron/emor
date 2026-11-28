<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan yedeğinin alındığı anda hangi planın hangi hedefe kopyalanacağı
 * (Codex G1-6-R2): `[{plan: id, hedef: id}, …]`. Eksik kopya kaydı sonradan
 * tamamlanırken (PlanliYedek) yedeğin o hedefe gerçekten gidecek olup olmadığı
 * buradan okunur. Bağın eklenme zamanı (`yedek_plan_hedefleri.created_at`)
 * yedeğin alınma anıyla aynı saniyede olabildiği için kanıt değildir.
 *
 * Yedek kaydı açılırken, arşivden ÖNCE yazılır (G1-6-R3): süreç arşivden sonra
 * ölse de bilinir. Eski yedeklerde boş kalır; o durumda yalnız yedekten KESİN
 * önce eklenmiş bağlar sayılır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yedekler', function (Blueprint $table): void {
            $table->json('plan_hedefleri')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('yedekler', function (Blueprint $table): void {
            $table->dropColumn('plan_hedefleri');
        });
    }
};
