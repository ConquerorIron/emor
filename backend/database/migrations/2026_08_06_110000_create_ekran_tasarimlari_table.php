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
        Schema::create('ekran_tasarimlari', function (Blueprint $table) {
            $table->id();
            // Hangi ekran (ör. 'satinalma.talep') — katalog anahtarı
            $table->string('ekran_anahtari');
            $table->unsignedInteger('surum');
            // 'taslak' (üzerinde çalışılıyor) | 'yayinda' (canlı) | 'arsiv' (eski)
            $table->string('durum');
            // Bölümler + alanlar + kurallar; sürükle-bırakla tek parça kaydedilir
            $table->jsonb('duzen');
            $table->string('notlar')->nullable();
            $table->foreignId('olusturan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('yayinlayan_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('yayin_zamani')->nullable();
            $table->timestamps();

            $table->unique(['ekran_anahtari', 'surum']);
        });

        // Ekran başına EN FAZLA bir yayında + bir taslak sürüm (SQL bağlantılarındaki
        // "tek aktif ortam" deseni) — kısmi unique indeksle veritabanı garanti eder
        DB::statement(
            "CREATE UNIQUE INDEX ekran_tasarimlari_tek_yayin
             ON ekran_tasarimlari (ekran_anahtari) WHERE durum = 'yayinda'",
        );
        DB::statement(
            "CREATE UNIQUE INDEX ekran_tasarimlari_tek_taslak
             ON ekran_tasarimlari (ekran_anahtari) WHERE durum = 'taslak'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('ekran_tasarimlari');
    }
};
