<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `deploy.sh`'ın eski imzasız yedeklerinden sonradan içeri alınanlar
 * (BY-013; manifestte `ice_alindi`). Listede ayrı işaretlenir; anlık
 * görüntü bilgisi (migration listesi) olmadığı için üstüne geri yüklenemez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yedekler', function (Blueprint $table) {
            $table->boolean('ice_alindi')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('yedekler', function (Blueprint $table) {
            $table->dropColumn('ice_alindi');
        });
    }
};
