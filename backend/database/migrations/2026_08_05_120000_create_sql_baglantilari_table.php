<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sql_baglantilari', function (Blueprint $table) {
            $table->id();
            // 'test' | 'canli' — ortam başına tek tanım
            $table->string('ortam')->unique();
            $table->string('sunucu');
            $table->unsignedSmallInteger('port')->nullable();
            $table->string('veritabani');
            $table->string('kullanici_adi');
            // encrypted cast ile saklanır (MailAyari::sifre deseni)
            $table->text('sifre');
            $table->boolean('aktif')->default(false);
            $table->timestamps();
        });

        // En fazla BİR satır aktif olabilir (global Test/Canlı anahtarı) —
        // kısmi unique indeks PostgreSQL ve SQLite'ta (testler) desteklenir
        DB::statement('CREATE UNIQUE INDEX sql_baglantilari_tek_aktif ON sql_baglantilari (aktif) WHERE aktif');
    }

    public function down(): void
    {
        Schema::dropIfExists('sql_baglantilari');
    }
};
