<?php

declare(strict_types=1);

// API hata sözleşmesi mesajları (bootstrap/app.php render callback'leri +
// servis katmanı doğrulama mesajları)
return [
    'yetkisiz' => 'Oturum açmanız gerekiyor.',
    'erisim_engelli' => 'Bu işlem için yetkiniz yok.',
    'bulunamadi' => 'Kayıt bulunamadı.',
    'cok_fazla_istek' => 'Çok fazla istek gönderildi; lütfen biraz bekleyin.',
    'bilinmeyen' => 'Beklenmeyen bir hata oluştu.',

    // SQL bağlantı ayarları
    'sql_sifre_zorunlu' => 'İlk kayıtta SQL şifresi zorunludur.',
    'sql_sifre_hedef_degisti' => 'Sunucu, port veya kullanıcı adı değiştiğinde SQL şifresi yeniden girilmelidir.',
    'sql_dsn_karakter' => 'Sunucu ve veritabanı adında noktalı virgül, süslü parantez veya kontrol karakteri kullanılamaz.',
    'sql_baglanti_tanimsiz' => 'Bu ortam için bağlantı tanımı yapılmamış.',
    'sql_aktif_ortam_yok' => 'Aktif ortam seçilmemiş. Ayarlar → SQL Bağlantıları ekranından Test veya Canlı ortamı aktif edin.',
    'sql_baglanti_basarisiz' => 'Bağlantı kurulamadı: :detay',
    'sql_baglanti_eksik' => 'Sunucu, veritabanı ve kullanıcı adı alanları zorunludur.',

    // Entegratör (İzibiz) bağlantı ayarları
    'entegrator_sifre_zorunlu' => 'İlk kayıtta entegratör şifresi zorunludur.',
    'entegrator_sifre_kullanici_degisti' => 'Kullanıcı adı değiştiğinde entegratör şifresi yeniden girilmelidir.',
    'entegrator_sifre_adres_degisti' => 'API adresi değiştiğinde entegratör şifresi yeniden girilmelidir.',
    'entegrator_api_adresi_gecersiz' => 'API adresi "https://alan-adı" biçiminde olmalı (yol, sorgu veya kullanıcı bilgisi içeremez).',
    'entegrator_api_adresi_izinsiz' => 'API adresinin alan adı izinli değil. İzinli alan adları: :alanlar',
    'entegrator_kullanici_zorunlu' => 'Entegratör kullanıcı adı zorunludur.',
    'entegrator_baglanti_tanimsiz' => 'Bu ortam için entegratör bağlantısı tanımlanmamış.',
    'entegrator_eszamanli_guncelleme' => 'Tanım aynı anda başka bir oturumdan kaydedildi; sayfayı yenileyip tekrar deneyin.',
    'entegrator_vkn_gecersiz' => 'VKN 10, TCKN 11 haneli olmalı ve yalnız rakam içermelidir.',
    'entegrator_urn_gecersiz' => 'Etiket "urn:mail:" ile başlamalıdır.',
    'entegrator_aktif_yok' => 'Aktif entegratör ortamı seçilmemiş. Ayarlar → Entegratör Bağlantıları ekranından Test veya Canlı ortamı aktif edin.',
    'entegrator_erisilemedi' => 'Entegratöre ulaşılamadı. Ağ bağlantısını kontrol edip tekrar deneyin.',
    'entegrator_kimlik_hatali' => 'Entegratör kullanıcı adı veya şifresi hatalı.',
    'entegrator_yanit_gecersiz' => 'Entegratörden beklenmeyen bir yanıt geldi.',
    'entegrator_hata' => 'Entegratör isteği reddetti (kod: :kod).',

    // e-Fatura ekranları (EFAT-11)
    'efatura_aralik_cok_genis' => 'Tarih aralığı en fazla :gun gün olabilir.',
    'efatura_ilk_tarih_oncesi' => 'e-Faturalar :tarih tarihinden itibaren izlenir; başlangıç daha erken olamaz.',
    'efatura_bitis_gelecekte' => 'Bitiş tarihi bugünden sonra olamaz.',
    'efatura_excel_cok_buyuk' => 'Seçilen filtrede :adet fatura var; Excel çıktısı en fazla :sinir satır olabilir. Tarih aralığını daraltın.',
    'efatura_senkron_suruyor' => 'Bu hesap için elle başlatılan bir senkron zaten sırada ya da çalışıyor.',
    'efatura_senkron_kapali' => 'e-Fatura senkronu sunucuda kapatılmış (EFATURA_SENKRON_AKTIF).',

    // Alarm kuralları (EFAT-13)
    'alarm_saat_bicimi' => 'Saat SS:DD biçiminde olmalı (ör. 08:30).',
    'alarm_alici_gecersiz' => 'Geçerli bir e-posta adresi girin.',
    'alarm_alici_zorunlu' => 'Aktif kural için en az bir alıcı gerekir.',

    // Kullanıcı ve rol yönetimi (EFAT-18)
    'erp_kullanici_denetlenemedi' => 'ERP\'ye ulaşılamadığı için kullanıcı adının ERP\'de olup olmadığı denetlenemedi; lokal kullanıcı açılmadı.',
    'kullanici_adi_erpde_var' => 'Bu kullanıcı adı ERP\'de kayıtlı. ERP kullanıcıları kendi şifreleriyle giriş yapar; lokal kullanıcı açılamaz.',
    'kullanici_adi_bicimi' => 'Kullanıcı adı 3–64 karakter olmalı; yalnız harf, rakam, nokta, alt çizgi ve tire içerebilir.',
    'kendini_pasif_yapamaz' => 'Kendi hesabınızı pasife alamazsınız.',
    'yedek_admin_pasif_yapilamaz' => 'Yedek (lokal) yönetici hesabı pasife alınamaz: ERP erişilemezken tek giriş yoludur.',
    'erp_kullanici_bilgisi_ekrandan_degismez' => 'ERP kullanıcısının adı, e-postası ve şifresi ERP\'den gelir; bu ekrandan değiştirilemez.',

    // Mail (SMTP) ayarları
    'mail_ayari_yok' => 'Mail (SMTP) ayarı tanımlanmamış. Ayarlar → Mail (SMTP) ekranından tanımlayın.',
    'mail_sifre_eksik' => 'SMTP şifresi girilmemiş. Ayarlar → Mail (SMTP) ekranından şifreyi girin.',
    'mail_sifre_hedef_degisti' => 'Sunucu, port, kullanıcı adı veya şifreleme değiştiğinde SMTP şifresi yeniden girilmelidir.',
    'mail_gonderilemedi' => 'Mail gönderilemedi: :detay',

    // Satınalma
    'ilgili_cins_tanimsiz' => 'Bu ilgi konusu için arama kaynağı henüz tanımlanmadı.',

    // Ekran tasarım motoru
    'ekran_tanimsiz' => 'Böyle bir tasarlanabilir ekran yok.',
    'tasarim_taslak_yok' => 'Yayınlanacak taslak bulunamadı.',
    'tasarim_surum_yok' => 'Bu sürüm bulunamadı.',
    'tasarim_bolum_yok' => 'Tasarımda en az bir bölüm olmalı.',
    'tasarim_bolum_gecersiz' => 'Tanımsız bölüm.',
    'tasarim_alan_gecersiz' => 'Tanımsız alan: :alan',
    'tasarim_alan_tekrar' => 'Aynı alan birden fazla kez yerleştirilemez: :alan',
    'tasarim_genislik_gecersiz' => 'Genişlik 1–12 arasında olmalı: :alan',
    'tasarim_alan_zorunlu' => 'Bu alan tasarımdan çıkarılamaz: :alan',
];
