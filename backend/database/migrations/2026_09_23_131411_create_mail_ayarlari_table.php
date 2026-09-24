<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uygulamanın giden mail (SMTP) tanımı — tek satır (`anahtar` = 'varsayilan').
 * Şifre encrypted cast ile saklanır; seeder şifresiz açar, ekrandan girilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_ayarlari', function (Blueprint $table) {
            $table->id();
            // Tek tanım garantisi
            $table->string('anahtar', 32)->unique();
            $table->string('sunucu');
            $table->unsignedSmallInteger('port');
            // 'tls' (STARTTLS zorunlu) | 'ssl' (SMTPS) | 'yok'
            $table->string('sifreleme', 8);
            $table->string('kullanici_adi')->nullable();
            $table->text('sifre')->nullable();
            $table->string('gonderen_adres');
            $table->string('gonderen_ad');
            // Doluysa TÜM mailler yalnız bu adrese gider (test dönemi güvenliği)
            $table->string('yonlendirme_adresi')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_ayarlari');
    }
};
