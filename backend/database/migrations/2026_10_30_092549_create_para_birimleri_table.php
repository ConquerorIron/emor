<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kesinti Yönetimi: para birimleri. Excel'deki "PARA BİRİMİ" (TL, USD, EUR)
 * koduyla eşleşir; ekrandan yeni para birimi eklenebilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('para_birimleri', function (Blueprint $table) {
            $table->id();
            // Türkçe kurallı büyük harfle saklanır
            $table->string('kod', 10)->unique();
            $table->string('ad', 50);
            $table->timestamps();
        });

        // Kesinti Excel'lerinde geçen para birimleri
        $simdi = now();
        DB::table('para_birimleri')->insert([
            ['kod' => 'TL', 'ad' => 'Türk Lirası', 'created_at' => $simdi, 'updated_at' => $simdi],
            ['kod' => 'USD', 'ad' => 'ABD Doları', 'created_at' => $simdi, 'updated_at' => $simdi],
            ['kod' => 'EUR', 'ad' => 'Euro', 'created_at' => $simdi, 'updated_at' => $simdi],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('para_birimleri');
    }
};
