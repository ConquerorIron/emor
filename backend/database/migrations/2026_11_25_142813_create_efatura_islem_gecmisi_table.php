<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * e-Fatura işlem geçmişi (EK-03): ekrandan yapılan her elle işlem (RED'e
 * çekme / geri alma, İşlendi, gizleme, içe aktarım partisi) kullanıcı bazında.
 * Salt eklemelidir (model güncelleme ve silmeyi reddeder).
 *
 * Fatura ya da parti silinse de kayıt okunur kalır: belgenin kimliği, sağlayıcı
 * ve kullanıcının o andaki adı kopya olarak yazılır, bağlar boşalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('efatura_islem_gecmisi', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fatura_id')->nullable()->constrained('efatura_faturalari')->nullOnDelete();
            $table->foreignId('yukleme_id')->nullable()->constrained('efatura_yuklemeleri')->nullOnDelete();
            $table->string('saglayici', 32);
            $table->string('belge_no', 64)->nullable();
            // ETTN ya da statik kaynağın belge anahtarı
            $table->string('belge_kimligi', 200)->nullable();
            // App\Services\Entegrator\EFaturaIslemTuru
            $table->string('islem', 32);
            $table->string('onceki')->nullable();
            $table->string('yeni')->nullable();
            $table->text('neden')->nullable();
            // Parti sayıları, dosya adı gibi işleme özgü ek bilgi
            $table->json('ayrinti')->nullable();
            $table->foreignId('kullanici_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kullanici_adi');
            $table->timestampTz('created_at');

            // Satırdaki "Geçmiş" penceresi
            $table->index(['fatura_id', 'created_at']);
            // İşlem Geçmişi ekranı: tarih aralığı, kullanıcıya göre
            $table->index('created_at');
            $table->index(['kullanici_id', 'created_at']);
            $table->index('yukleme_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('efatura_islem_gecmisi');
    }
};
