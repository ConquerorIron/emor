<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * e-Fatura listesinin ek kolonları (kullanıcı isteği 2026-09-24): İzibiz liste
 * yanıtında bulunan ama saklanmayan özet alanları. Hepsi isteğe bağlı; mevcut
 * satırlar bir sonraki senkronda dolar. Serbest metinler text (gerçek veride
 * 255'i aşan alanlar görüldü — EFAT-10 dersi).
 *
 * `teslim_ref`, `harici_aktarim`, `mail_durumu`: ekran karşılıkları (Referans
 * No, Dışarıya Gönderim / Transfer Durumu) kullanıcıyla netleşene kadar
 * yalnız saklanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            // Gerçek kişi taraflar (TCKN) için ad soyad
            $table->text('gonderici_ad_soyad')->nullable();
            $table->text('alici_ad_soyad')->nullable();
            // GİB etiketleri: gönderici birim (GB) / posta kutusu (PK)
            $table->text('gonderici_etiketi')->nullable();
            $table->text('alici_etiketi')->nullable();
            // Birden çok irsaliye virgülle gelebilir
            $table->text('irsaliye_no')->nullable();
            $table->text('siparis_no')->nullable();
            $table->date('siparis_tarihi')->nullable();
            // İhracat (GTB/GÇB) alanları; biçimi garanti olmadığı için metin
            $table->text('gtb_ref_no')->nullable();
            $table->text('gcb_tescil_no')->nullable();
            $table->string('gcb_tarihi', 32)->nullable();
            $table->text('portal_notu')->nullable();
            $table->text('teslim_ref')->nullable();
            $table->boolean('harici_aktarim')->nullable();
            $table->string('mail_durumu', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('efatura_faturalari', function (Blueprint $table) {
            $table->dropColumn([
                'gonderici_ad_soyad', 'alici_ad_soyad', 'gonderici_etiketi', 'alici_etiketi',
                'irsaliye_no', 'siparis_no', 'siparis_tarihi', 'gtb_ref_no', 'gcb_tescil_no',
                'gcb_tarihi', 'portal_notu', 'teslim_ref', 'harici_aktarim', 'mail_durumu',
            ]);
        });
    }
};
