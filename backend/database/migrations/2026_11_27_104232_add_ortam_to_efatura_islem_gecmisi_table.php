<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İşlem geçmişi kaydının ortamı (inceleme A-9, kullanıcı kararı 2026-11-27):
 * test ve canlı tanımda yapılan işlemler aynı ekranda ayırt edilsin. Kayıt
 * sağlayıcıyla birlikte tanımın o andaki ortamını kopya taşır (G21). Eski
 * kayıtlar boş kalır (salt eklemeli tablo; hangi ortamda yapıldıkları bilinmez).
 * Süzme yok: ekran iki ortamı da gösterir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_islem_gecmisi', function (Blueprint $table): void {
            // entegrator_baglantilari.ortam ile aynı tür
            $table->string('ortam', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_islem_gecmisi', function (Blueprint $table): void {
            $table->dropColumn('ortam');
        });
    }
};
