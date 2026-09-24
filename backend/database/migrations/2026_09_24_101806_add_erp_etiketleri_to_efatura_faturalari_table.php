<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gönderici (GB) / alıcı (PK) etiketinin ERP'deki karşılığı. İzibiz liste
 * yanıtında alias alanları neredeyse hep boş; ERP'nin TOHOM_E_FATURA havuzunda
 * (GIB_FIRMAMIZ_POSTA_KUTUSU / GIB_MUHATAP_POSTA_KUTUSU) her kayıtta dolu.
 * İzibiz senkronu kendi (boş) değerini yazdığı için ayrı kolonda tutulur;
 * ekranda önce İzibiz'inki, yoksa ERP'ninki gösterilir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->text('erp_gonderici_etiketi')->nullable();
            $table->text('erp_alici_etiketi')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn(['erp_gonderici_etiketi', 'erp_alici_etiketi']);
        });
    }
};
