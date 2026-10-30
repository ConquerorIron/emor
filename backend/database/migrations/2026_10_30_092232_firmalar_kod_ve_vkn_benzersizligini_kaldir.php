<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firmanın kimliği yalnız ERP GUID'idir (kullanıcı kararı 2026-10-30): ERP'de
 * aynı firmanın birden fazla kartı aynı kodu ve VKN'yi taşıyabilir (MFK KOÇ'un
 * 4 kartı). Kodlar uygulamada Excel'le aynı olacak şekilde düzenlenir;
 * Excel eşleşmesi kodun tek firmaya düşmesini ayrıca denetler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firmalar', function (Blueprint $table) {
            $table->dropUnique(['kod']);
            $table->dropUnique(['vergi_kimlik_no']);
        });
    }

    /** Tekrarlı kod/VKN varsa geri alınamaz (benzersiz indeks kurulamaz). */
    public function down(): void
    {
        Schema::table('firmalar', function (Blueprint $table) {
            $table->unique('kod');
            $table->unique('vergi_kimlik_no');
        });
    }
};
