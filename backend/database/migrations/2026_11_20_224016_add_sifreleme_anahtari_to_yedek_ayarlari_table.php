<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sunucu dışı kopyaların şifreleme anahtarı (README §4.7; BY-019): yalnız
 * AÇIK anahtar (ASCII zırhlı metin) ve onun bilgileri. Özel anahtar sunucuda
 * hiçbir zaman tutulmaz; geri yüklemede o anda elle sağlanır.
 *
 * Parmak izi ekranda gösterilir ve her şifrelemede anahtarlığa alınan
 * anahtarla karşılaştırılır (KopyaSifreleyici). v4 anahtarda 40, v5/v6'da
 * 64 onaltılık karakter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yedek_ayarlari', function (Blueprint $table) {
            $table->text('sifreleme_acik_anahtari')->nullable();
            $table->string('sifreleme_parmak_izi', 64)->nullable();
            // Anahtarın kullanıcı kimliği ("Ad <posta>"), bilgi amaçlı
            $table->string('sifreleme_anahtar_kullanicisi', 255)->nullable();
            // Anahtarın son geçerlilik anı; süresizse boş
            $table->timestampTz('sifreleme_anahtar_bitisi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('yedek_ayarlari', function (Blueprint $table) {
            $table->dropColumn(['sifreleme_acik_anahtari', 'sifreleme_parmak_izi', 'sifreleme_anahtar_kullanicisi', 'sifreleme_anahtar_bitisi']);
        });
    }
};
