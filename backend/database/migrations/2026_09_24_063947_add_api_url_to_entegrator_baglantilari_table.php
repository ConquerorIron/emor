<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entegratör API adresi ekrandan tanımlanabilir (kullanıcı isteği 2026-09-24).
 * NULL = ortamın varsayılan adresi (config/entegrator.php). Adres yalnız
 * https ve izinli alan adıyla kabul edilir; değişince kayıtlı şifre yeniden
 * istenir (kayıtlı şifre başka bir sunucuya gönderilemesin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entegrator_baglantilari', function (Blueprint $table) {
            $table->string('api_url', 255)->nullable()->after('ortam');
        });
    }

    public function down(): void
    {
        Schema::table('entegrator_baglantilari', function (Blueprint $table) {
            $table->dropColumn('api_url');
        });
    }
};
