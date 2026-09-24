<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * e-Belge entegratörü (İzibiz) bağlantı tanımları — sql_baglantilari kalıbı.
 * Tek şirket / tek hesap (EFAT-15, S9): sağlayıcı + ortam başına tek tanım.
 * API adresi SAKLANMAZ; sağlayıcı + ortamdan config/entegrator.php ile
 * türetilir — kayıtlı şifre serbest bir adrese gönderilemesin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entegrator_baglantilari', function (Blueprint $table) {
            $table->id();
            $table->string('saglayici', 32);
            // 'test' | 'canli'
            $table->string('ortam', 16);
            $table->string('kullanici_adi');
            // encrypted cast ile saklanır; şifreli metin uzunluğu değişken
            $table->text('sifre');
            // VKN (10) / TCKN (11) — metin: baştaki sıfır kaybolmasın
            $table->string('vkn', 11);
            $table->string('posta_kutusu')->nullable();
            $table->string('gonderici_birim')->nullable();
            $table->boolean('aktif')->default(false);
            // Kullanıcı adı/şifre her değiştiğinde artar; token önbellek anahtarına
            // girer, böylece eski kimlikle alınmış token bir daha kullanılmaz
            $table->unsignedInteger('kimlik_surumu')->default(1);
            $table->timestamps();

            $table->unique(['saglayici', 'ortam']);
        });

        // Sağlayıcı başına en fazla BİR aktif ortam — kısmi unique indeks
        // PostgreSQL ve SQLite'ta (testler) desteklenir
        DB::statement('CREATE UNIQUE INDEX entegrator_baglantilari_tek_aktif ON entegrator_baglantilari (saglayici) WHERE aktif');
    }

    public function down(): void
    {
        Schema::dropIfExists('entegrator_baglantilari');
    }
};
