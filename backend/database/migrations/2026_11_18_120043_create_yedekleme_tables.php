<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yedekleme modülü veri modeli (BY-004; tasarım docs/yedekleme/README.md §5.4).
 *
 * Doğruluk kaynağı yedek klasöründeki dosyalardır (manifest + olay günlüğü);
 * `yedekler` ve `yedek_kopyalari` onların DİZİNİDİR, klasör taranarak yeniden
 * kurulabilir (README §5.2, §7.3). Durum / tür kolonları metindir; geçerli
 * değerler App\Services\Yedekleme altındaki enum'lardadır.
 *
 * Silme kuralları:
 * - Kullanılan hedef silinmez: plan bağı ya da kopya varsa RESTRICT
 *   (KayitSilme → 409 KAYIT_KULLANILIYOR).
 * - Kopyası olan plan silinmez (RESTRICT); kopyasız planın hedef bağları
 *   planla birlikte gider (CASCADE). Kullanılmayan plan pasife alınır.
 * - Yedek kaydı silinmez, durumu `silindi` olur (geçmiş kaybolmaz); kopyalar
 *   yedekle gider, geri yükleme geçmişi yedeği tutar (RESTRICT).
 * - Kullanıcı silinirse kayıtlar kalır, kişi alanı boşalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('yedek_hedefleri', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 100)->unique();
            // HedefTuru: yerel | ftp | ftps | sftp | google_drive | nas | s3
            $table->string('tur', 16);
            $table->boolean('aktif')->default(true);
            // Sır olmayan bağlantı bilgileri (sunucu, port, klasör, kullanıcı adı, bağlı hesap…)
            $table->jsonb('baglanti');
            // Parola, yenileme anahtarı, gizli anahtar — encrypted:array (şifreli metin)
            $table->text('gizli_bilgiler')->nullable();
            $table->timestampsTz();
        });

        Schema::create('yedek_planlari', function (Blueprint $table) {
            $table->id();
            $table->string('ad', 100)->unique();
            $table->boolean('aktif')->default(true);
            // SiklikTuru: saatlik (N saatte bir) | gunluk | n_gunluk | haftalik | aylik
            $table->string('siklik_turu', 16);
            // İstanbul saati, SS:DD (saatlikte ilk çalışmanın saati)
            $table->string('saat', 5);
            // Türe özgü: aralik, gunler (1–7), ayin_gunu, saat_baslangic / saat_bitis
            $table->jsonb('siklik');
            $table->timestampTz('son_calisma')->nullable();
            $table->timestampTz('sonraki_calisma')->nullable();
            $table->timestampsTz();
        });

        Schema::create('yedek_plan_hedefleri', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yedek_plani_id')->constrained('yedek_planlari')->cascadeOnDelete();
            $table->foreignId('yedek_hedefi_id')->constrained('yedek_hedefleri')->restrictOnDelete();
            // Saklama kuralı bu plan + hedef için: son N yedek ve / veya N gün (README §4.3)
            $table->unsignedSmallInteger('sakla_son_adet')->nullable();
            $table->unsignedSmallInteger('sakla_gun')->nullable();
            $table->timestampsTz();

            // Plan başına bir hedef bir kez; plan kolonundaki aramaları da karşılar
            $table->unique(['yedek_plani_id', 'yedek_hedefi_id']);
            // Hedef silinirken RESTRICT denetimi
            $table->index('yedek_hedefi_id');
        });

        Schema::create('yedek_ayarlari', function (Blueprint $table) {
            $table->id();
            // Tek satır: 'varsayilan' (MailAyari kalıbı)
            $table->string('anahtar', 32)->unique();
            // Zamanlanmamış yedeklerin saklaması (README §4.3 önerileri)
            $table->unsignedSmallInteger('yayin_oncesi_son_adet')->default(20);
            $table->unsignedSmallInteger('yayin_oncesi_gun')->default(30);
            $table->unsignedSmallInteger('elle_gun')->default(30);
            $table->unsignedSmallInteger('geri_yukleme_oncesi_gun')->default(30);
            // Süre dolsa da her hedefte korunacak en az başarılı yedek
            $table->unsignedSmallInteger('enaz_adet')->default(3);
            // Boş disk bu yüzdenin altına inince uyarı
            $table->unsignedSmallInteger('enaz_bos_alan_yuzde')->default(15);
            $table->jsonb('bildirim_alicilari');
            $table->timestampsTz();
        });

        Schema::create('yedekler', function (Blueprint $table) {
            $table->id();
            // Manifestteki kimlik: dosya ile dizin kaydını eşleştirir
            $table->uuid('kimlik')->unique();
            $table->string('dosya_adi', 200)->unique();
            // YedekKaynagi: elle | plan | yayin_oncesi | geri_yukleme_oncesi
            $table->string('kaynak', 24);
            // YedekDurumu: kuyrukta | calisiyor | tamam | basarisiz | silindi
            $table->string('durum', 16);
            // DogrulamaDurumu: dogrulanmadi | ozet_tamam | sinama_gecti | basarisiz
            $table->string('dogrulama_durumu', 16)->default('dogrulanmadi');
            // Manifest imzası doğrulandı mı; imzasız yedek geri yüklenemez (README §4.5)
            $table->boolean('imzali')->default(false);
            $table->unsignedBigInteger('boyut')->nullable();
            $table->char('sha256', 64)->nullable();
            // Verinin zamanı: yedeğin aldığı anlık görüntü
            $table->timestampTz('alinma_zamani')->nullable();
            $table->timestampTz('bitti')->nullable();
            $table->boolean('sabit')->default(false);
            $table->text('aciklama')->nullable();
            $table->foreignId('alan_kullanici_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hata_kodu', 48)->nullable();
            $table->text('hata_mesaji')->nullable();
            $table->timestampTz('silinme_zamani')->nullable();
            $table->timestampsTz();

            // Liste ve saklama: en yeniden eskiye
            $table->index('alinma_zamani');
            $table->index('alan_kullanici_id');
        });

        Schema::create('yedek_kopyalari', function (Blueprint $table) {
            $table->id();
            $table->foreignId('yedek_id')->constrained('yedekler')->cascadeOnDelete();
            $table->foreignId('yedek_hedefi_id')->constrained('yedek_hedefleri')->restrictOnDelete();
            // Kopyayı isteyen plan; saklama bu planın bu hedefteki kuralıyla (README §4.3)
            $table->foreignId('yedek_plani_id')->nullable()->constrained('yedek_planlari')->restrictOnDelete();
            // KopyaDurumu: bekliyor | kopyalaniyor | tamam | basarisiz | silindi
            $table->string('durum', 16);
            // Hedefteki yol ya da dosya kimliği (ör. Drive dosya kimliği)
            $table->string('uzak_kimlik', 500)->nullable();
            $table->boolean('sifreli')->default(false);
            // Şifreli kopyanın özeti: uzak kopya yalnız bununla doğrulanır (README §4.6)
            $table->char('sifreli_sha256', 64)->nullable();
            $table->unsignedSmallInteger('deneme')->default(0);
            $table->string('hata_kodu', 48)->nullable();
            $table->text('hata_mesaji')->nullable();
            $table->timestampTz('tamamlanma_zamani')->nullable();
            $table->timestampTz('silinme_zamani')->nullable();
            $table->timestampsTz();

            $table->unique(['yedek_id', 'yedek_hedefi_id', 'yedek_plani_id']);
            // Saklama: bir planın bir hedefteki kopyaları; plan silinirken RESTRICT denetimi
            $table->index(['yedek_plani_id', 'yedek_hedefi_id', 'durum']);
            // Hedef silinirken RESTRICT denetimi
            $table->index('yedek_hedefi_id');
        });

        Schema::create('geri_yuklemeler', function (Blueprint $table) {
            $table->id();
            // İşlem kimliği: işlem durumu dosyasıyla eşleşir (README §6.4)
            $table->uuid('kimlik')->unique();
            $table->foreignId('yedek_id')->constrained('yedekler')->restrictOnDelete();
            // GeriYuklemeTuru: kopya | ustune
            $table->string('tur', 12);
            // PostgreSQL ad sınırı 63
            $table->string('hedef_veritabani', 63);
            // Üstüne geri yüklemede eski veritabanının yeni adı (erp_onceki_*)
            $table->string('onceki_veritabani', 63)->nullable();
            $table->foreignId('isteyen_kullanici_id')->nullable()->constrained('users')->nullOnDelete();
            // GeriYuklemeDurumu: kuyrukta | calisiyor | onay_bekliyor | tamam | basarisiz | geri_alindi
            $table->string('durum', 16);
            $table->jsonb('adimlar')->nullable();
            $table->string('hata_kodu', 48)->nullable();
            $table->text('hata_mesaji')->nullable();
            $table->timestampTz('basladi')->nullable();
            $table->timestampTz('bitti')->nullable();
            // Geri yüklenen kopya (ya da erp_onceki_*) veritabanının silindiği an
            $table->timestampTz('veritabani_silinme_zamani')->nullable();
            $table->timestampsTz();

            $table->index('yedek_id');
            $table->index('isteyen_kullanici_id');
        });

        // Denetim kaydı: salt eklemeli (güncellenmez). Ayrıca günlük dosyasına da
        // yazılır; veritabanı geri yüklenince buradaki kayıtlar da eskir (README §4.9, §7.3).
        Schema::create('yedek_islem_kayitlari', function (Blueprint $table) {
            $table->id();
            // Boşsa sistem (zamanlayıcı, çalıştırıcı)
            $table->foreignId('kullanici_id')->nullable()->constrained('users')->nullOnDelete();
            // Ör. yedek_al, yedek_sil, sabitle, plan_kaydet, geri_yukle_kopya
            $table->string('islem', 48);
            $table->foreignId('yedek_id')->nullable()->constrained('yedekler')->nullOnDelete();
            $table->foreignId('geri_yukleme_id')->nullable()->constrained('geri_yuklemeler')->nullOnDelete();
            // basarili | basarisiz
            $table->string('sonuc', 12);
            $table->unsignedInteger('sure_ms')->nullable();
            $table->jsonb('ayrinti')->nullable();
            $table->text('hata_mesaji')->nullable();
            $table->timestampTz('created_at');

            // İşlem Günlüğü ekranı: tarihe göre süzgeç
            $table->index('created_at');
            $table->index('kullanici_id');
            $table->index('yedek_id');
            $table->index('geri_yukleme_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('yedek_islem_kayitlari');
        Schema::dropIfExists('geri_yuklemeler');
        Schema::dropIfExists('yedek_kopyalari');
        Schema::dropIfExists('yedekler');
        Schema::dropIfExists('yedek_ayarlari');
        Schema::dropIfExists('yedek_plan_hedefleri');
        Schema::dropIfExists('yedek_planlari');
        Schema::dropIfExists('yedek_hedefleri');
    }
};
