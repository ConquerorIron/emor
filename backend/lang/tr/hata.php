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
];
