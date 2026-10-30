<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesinti icmallerine yapılan her içe aktarım bir "parti"dir (kullanıcı kararı
 * 2026-10-30): kim, ne zaman, hangi dosyadan, kaç satır. Yanlış yüklenen parti
 * satırlarıyla birlikte tek seferde geri alınır (icmal tablolarında cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kesinti_yuklemeleri', function (Blueprint $table) {
            $table->id();
            // Hangi icmal ekranına yüklendi (ör. 'isgucu')
            $table->string('icmal', 30);
            // 'excel' (dosya) ya da 'yapistirma' (Excel'den kopyala-yapıştır)
            $table->string('kaynak', 20);
            $table->string('dosya_adi', 255)->nullable();
            $table->unsignedInteger('satir_sayisi');
            $table->foreignId('yukleyen_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['icmal', 'created_at']);
            $table->index('yukleyen_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kesinti_yuklemeleri');
    }
};
