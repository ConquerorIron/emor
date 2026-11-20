<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protel adresleri ekrandan tanımlanabilir (kullanıcı kararı 2026-11-20,
 * İzibiz'deki gibi). Protel'in giriş (token) sunucusu fatura sunucusundan
 * ayrıdır: `api_url` fatura, `auth_url` giriş adresidir. NULL = ortamın
 * varsayılanı (config/entegrator.php). İzibiz'de giriş ayrı sunucu değildir;
 * kolon orada hep NULL kalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entegrator_baglantilari', function (Blueprint $table) {
            $table->string('auth_url', 255)->nullable()->after('api_url');
        });
    }

    public function down(): void
    {
        Schema::table('entegrator_baglantilari', function (Blueprint $table) {
            $table->dropColumn('auth_url');
        });
    }
};
