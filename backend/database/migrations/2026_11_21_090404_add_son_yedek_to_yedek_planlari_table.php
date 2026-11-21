<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planın son çalışması planla İLİŞKİLİ tutulur (BY-017-R3): o çalışmanın
 * yedeği ve o an kopyalanacak hedefleri. "Son sonuç" bunlardan hesaplanır;
 * zamandan (son çalışmadan önceki en yeni plan yedeği) ya da planın bugünkü
 * hedef listesinden türetilmez — aynı saniyede biten / başlayan ayrı
 * çalışmalar ve sonradan düzenlenen hedef listesi eski sonucu değiştirmez.
 *
 * Yedek kaydı silinirse bağ boşalır (sonuç "bilinmiyor"). Var olan planlarda
 * iki kolon boş başlar; sonuç bir sonraki çalışmaya kadar bilinmiyor görünür.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('yedek_planlari', function (Blueprint $table) {
            $table->foreignId('son_yedek_id')->nullable()->constrained('yedekler')->nullOnDelete();
            // Son çalışmada bu planın kopyalanacak hedefleri (etkin + desteklenen), hedef kimlikleri listesi
            $table->jsonb('son_hedef_idleri')->nullable();

            // Yedek silinirken SET NULL denetimi
            $table->index('son_yedek_id');
        });
    }

    public function down(): void
    {
        Schema::table('yedek_planlari', function (Blueprint $table) {
            $table->dropIndex(['son_yedek_id']);
            $table->dropConstrainedForeignId('son_yedek_id');
            $table->dropColumn('son_hedef_idleri');
        });
    }
};
