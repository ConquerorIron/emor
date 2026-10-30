<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesinti Yönetimi: firmalar (taşeronlar). Elle açılır ya da canlı ERP'den
 * (WEB_ERP_TASERON_LISTESI) aktarılır; ERP firmasıyla bağ GUID ya da VKN ile.
 * Sınırlar canlı ERP verisine göre (2026-10-30: unvan en çok 200, vergi
 * dairesi 60, e-posta 35, GSM 20 karakter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firmalar', function (Blueprint $table) {
            $table->id();
            // Türkçe kurallı büyük harfle saklanır
            $table->string('kod', 50)->unique();
            $table->string('ad', 200);
            // Biçim zorunlu değil: ERP'de yabancı firma vergi numarası da var
            $table->string('vergi_kimlik_no', 20)->nullable()->unique();
            $table->string('vergi_dairesi', 100)->nullable();
            $table->string('eposta', 255)->nullable();
            $table->string('gsm_no', 30)->nullable();
            // TOHOM_PARTI_YAMASI.GUID; elle açılan firmada boş
            $table->uuid('erp_guid')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firmalar');
    }
};
