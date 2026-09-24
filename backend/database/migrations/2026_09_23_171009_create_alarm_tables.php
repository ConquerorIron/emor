<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * e-Fatura alarmları (EFAT-13).
 *
 * - alarm_kurallari: tür başına tek kural (senkron arızası, günlük özet,
 *   ERP okumadı); alıcılar ve eşikler ekrandan.
 * - alarm_olaylari: açık/çözüldü olaylar. Aynı kural + hesap + anahtar için
 *   en fazla bir AÇIK olay (kısmi unique indeks) — eşzamanlı değerlendirme
 *   tekrar bildirim üretemez; çözülen olay yeniden açılınca yeni satır olur.
 * - alarm_bildirimleri: gönderim kaydı. `anahtar` tekildir (aynı olay/gün
 *   için ikinci bildirim oluşmaz); durum bekliyor/gonderildi/basarisiz/atlandi.
 *   İçerik (mail gövdesi) değil yalnız özet sayılar tutulur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alarm_kurallari', function (Blueprint $table) {
            $table->id();
            // 'senkron_arizasi' | 'gunluk_ozet' | 'erp_okumadi'
            $table->string('tur', 32)->unique();
            $table->boolean('aktif')->default(false);
            $table->jsonb('alicilar');
            $table->jsonb('parametreler');
            $table->timestampsTz();
        });

        Schema::create('alarm_olaylari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alarm_kurali_id')->constrained('alarm_kurallari')->cascadeOnDelete();
            $table->foreignId('entegrator_baglanti_id')->constrained('entegrator_baglantilari')->restrictOnDelete();
            // Olayın kimliği: ör. 'senkron:gelen', 'fatura:123'
            $table->string('anahtar', 128);
            // 'acik' | 'cozuldu'
            $table->string('durum', 12);
            $table->timestampTz('acildi');
            $table->timestampTz('cozuldu')->nullable();
            $table->jsonb('ayrinti')->nullable();
            $table->timestampsTz();

            // Açık olayların değerlendirmede toplu okunması
            $table->index(['alarm_kurali_id', 'entegrator_baglanti_id', 'durum']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX alarm_olaylari_tek_acik
             ON alarm_olaylari (alarm_kurali_id, entegrator_baglanti_id, anahtar) WHERE durum = 'acik'"
        );

        Schema::create('alarm_bildirimleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alarm_kurali_id')->constrained('alarm_kurallari')->cascadeOnDelete();
            $table->foreignId('entegrator_baglanti_id')->constrained('entegrator_baglantilari')->restrictOnDelete();
            $table->foreignId('alarm_olayi_id')->nullable()->constrained('alarm_olaylari')->nullOnDelete();
            // 'acildi' | 'cozuldu' | 'ozet'
            $table->string('tur', 12);
            // Tekrar bildirimi önleyen anahtar: ör. 'olay:5:acildi', 'ozet:1:2026-09-22'
            $table->string('anahtar', 160)->unique();
            // 'bekliyor' | 'gonderildi' | 'basarisiz' | 'atlandi'
            $table->string('durum', 12);
            $table->jsonb('ayrinti');
            $table->unsignedSmallInteger('deneme')->default(0);
            $table->string('hata_kodu', 48)->nullable();
            $table->text('hata_mesaji')->nullable();
            $table->timestampTz('gonderildi')->nullable();
            $table->timestampsTz();

            // Ekrandaki "son bildirimler" listesi
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarm_bildirimleri');
        Schema::dropIfExists('alarm_olaylari');
        Schema::dropIfExists('alarm_kurallari');
    }
};
