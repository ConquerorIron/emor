<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesinti Yönetimi: projeler. Elle açılır ya da canlı ERP'den aktarılır;
 * ERP projesiyle bağ, ERP'nin değişmeyen GUID'i üzerindendir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projeler', function (Blueprint $table) {
            $table->id();
            // Türkçe kurallı büyük harfle saklanır: "z10" ile "Z10" aynı koddur
            $table->string('kod', 50)->unique();
            $table->string('ad', 200);
            // TOHOM_PARTI_YAMASI.GUID; elle açılan projede boş
            $table->uuid('erp_guid')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projeler');
    }
};
