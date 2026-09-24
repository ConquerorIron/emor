<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rol tabanlı yetki (EFAT-18). İzin kataloğu kodda (App\Yetki\Izin);
 * roller ve rol–izin / kullanıcı–rol eşlemeleri burada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roller', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 64)->unique();
            $table->string('aciklama')->nullable();
            $table->timestamps();
        });

        Schema::create('rol_izinleri', function (Blueprint $table) {
            $table->foreignId('rol_id')->constrained('roller')->cascadeOnDelete();
            // App\Yetki\Izin değeri (ör. efatura.goruntule)
            $table->string('izin', 64);

            $table->primary(['rol_id', 'izin']);
        });

        Schema::create('kullanici_rolleri', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roller')->cascadeOnDelete();

            // Birincil anahtar user_id ile başlar; rol tarafı sorguları için ayrı indeks
            $table->primary(['user_id', 'rol_id']);
            $table->index('rol_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kullanici_rolleri');
        Schema::dropIfExists('rol_izinleri');
        Schema::dropIfExists('roller');
    }
};
