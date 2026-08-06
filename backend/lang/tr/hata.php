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
    'sql_baglanti_tanimsiz' => 'Bu ortam için bağlantı tanımı yapılmamış.',
    'sql_aktif_ortam_yok' => 'Aktif ortam seçilmemiş. Ayarlar → SQL Bağlantıları ekranından Test veya Canlı ortamı aktif edin.',
    'sql_baglanti_basarisiz' => 'Bağlantı kurulamadı: :detay',
    'sql_baglanti_eksik' => 'Sunucu, veritabanı ve kullanıcı adı alanları zorunludur.',

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
