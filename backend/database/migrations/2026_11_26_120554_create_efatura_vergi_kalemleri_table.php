<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faturanın belge düzeyindeki vergi kalemleri, UBL'den (fatura başına çok satır):
 * - tur `vergi`: cac:TaxTotal/cac:TaxSubtotal (KDV her oran için ayrı satır;
 *   ÖTV, konaklama vergisi… kendi kodlarıyla)
 * - tur `tevkifat`: cac:WithholdingTaxTotal/cac:TaxSubtotal (kod 601…)
 * Tutarlar belge para biriminde. Fatura silinince kalemleri de gider.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efatura_vergi_kalemleri', function (Blueprint $table) {
            $table->id();
            // Yabancı anahtar sorgusu ve silme zinciri bu indeksi kullanır (sıra ile birlikte)
            $table->foreignId('efatura_id')->constrained('efatura_faturalari')->cascadeOnDelete();
            $table->unsignedSmallInteger('sira');
            $table->string('tur', 16);
            // TaxTypeCode: 0015 KDV, 0071 ÖTV, 601… tevkifat (UBL-TR kod listesi)
            $table->string('vergi_kodu', 16)->nullable();
            $table->text('vergi_adi')->nullable();
            $table->decimal('oran', 9, 4)->nullable();
            $table->decimal('matrah', 20, 4)->nullable();
            $table->decimal('tutar', 20, 4);
            $table->string('istisna_kodu', 16)->nullable();
            $table->text('istisna_adi')->nullable();

            $table->unique(['efatura_id', 'sira']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efatura_vergi_kalemleri');
    }
};
