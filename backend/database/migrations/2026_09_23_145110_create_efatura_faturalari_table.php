<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İzibiz'den okunan e-Fatura ÖZETLERİ (EFAT-10). S4 kararı: özetler
 * PostgreSQL'de tutulabilir ("ERP verisi bizde tutulmaz" kuralına onaylı
 * istisna). Fatura içeriği / XML / PDF tutulmaz; görüntüleme İzibiz'den anlık.
 *
 * Kapsam entegratör tanımına bağlıdır: test ve canlı hesabın faturaları
 * birbirine karışmaz. Kaynak kimliği (İzibiz `id`) ile ETTN ayrı tutulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efatura_faturalari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entegrator_baglanti_id')->constrained('entegrator_baglantilari')->restrictOnDelete();
            // 'gelen' | 'giden'
            $table->string('yon', 8);
            // İzibiz iç kimliği — PDF/HTML görüntüleme bununla yapılır
            $table->unsignedBigInteger('kaynak_id');
            $table->string('ettn', 36);
            $table->string('belge_no', 64);
            $table->date('belge_tarihi');
            // İzibiz bazen saat dilimli döner ("12:47:05+00:00") — biçim zorlanmaz
            $table->string('belge_saati', 32)->nullable();
            // İzibiz'e ulaşma/oluşturma zamanı (İstanbul saatinden UTC'ye çevrilir)
            $table->timestampTz('olusturma_zamani')->nullable();
            $table->string('fatura_tipi', 64)->nullable();
            $table->string('senaryo', 64)->nullable();
            $table->string('para_birimi', 3);
            // Parasal değerler numeric: float yuvarlaması yok
            $table->decimal('tutar', 20, 4);
            $table->decimal('vergi_tutari', 20, 4)->nullable();
            $table->unsignedInteger('satir_sayisi')->nullable();
            // VKN/TCKN metin: baştaki sıfır kaybolmaz; ihracatta yabancı kimlik
            // gelebileceği için 11 haneyle sınırlanmaz
            $table->string('gonderici_vkn', 32)->nullable();
            // Serbest metinler text: gerçek veride 255 karakteri aşan unvan /
            // GİB açıklaması görüldü (PostgreSQL'de text ile varchar aynı hızda)
            $table->text('gonderici_unvan')->nullable();
            $table->string('alici_vkn', 32)->nullable();
            $table->text('alici_unvan')->nullable();
            $table->string('durum', 64)->nullable();
            $table->text('durum_aciklamasi')->nullable();
            $table->integer('gib_durum_kodu')->nullable();
            $table->text('gib_durum_aciklamasi')->nullable();
            // İzibiz bayrakları YALNIZ okunur (ERP erpReadFlag'i kullanıyor)
            $table->boolean('erp_okundu')->nullable();
            $table->boolean('okundu')->nullable();
            $table->text('yanit_aciklamasi')->nullable();
            $table->timestampTz('ilk_gorulme');
            $table->timestampTz('son_gorulme');
            $table->timestampsTz();

            // Upsert anahtarı; baştaki tanım kolonu yabancı anahtar sorgularını da karşılar
            $table->unique(['entegrator_baglanti_id', 'yon', 'kaynak_id']);
            // Ekran listesi: tanım + yön + tarih aralığı
            $table->index(['entegrator_baglanti_id', 'yon', 'belge_tarihi']);
            // ETTN ile arama
            $table->index(['entegrator_baglanti_id', 'ettn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efatura_faturalari');
    }
};
