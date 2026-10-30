<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesinti Yönetimi modülü: taşeron kesinti türleri. Bu modülün verisi ERP'de
 * değil, uygulamanın kendi PostgreSQL'indedir (kullanıcı kararı 2026-10-30).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kesinti_turleri', function (Blueprint $table) {
            $table->id();
            $table->string('kod', 20)->unique();
            $table->string('ad', 100);
            // Yüzde olarak (20.00 = %20); parasal hesapta float kullanılmaz
            $table->decimal('kdv_orani', 5, 2);
            $table->string('aciklama', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kesinti_turleri');
    }
};
