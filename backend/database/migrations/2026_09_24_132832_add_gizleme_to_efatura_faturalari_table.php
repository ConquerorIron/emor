<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gizleme (kullanıcı isteği 2026-09-24): aynı VKN'yi paylaşan şirketlerden
 * birine kesilip yanlış posta kutusuna düşen fatura silinmez, listede
 * gizlenir. Yalnız bu uygulamanın alanları; senkron (upsert) dokunmaz.
 * Nullable kolon eklemek tabloyu yeniden yazmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table): void {
            // null = listede görünür
            $table->timestampTz('gizlenme_zamani')->nullable();
            // Kullanıcı silinse de gizleme kalır; bu kolonla arama yapılmadığı için indeks yok
            $table->foreignId('gizleyen_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gizleyen_id');
            $table->dropColumn('gizlenme_zamani');
        });
    }
};
