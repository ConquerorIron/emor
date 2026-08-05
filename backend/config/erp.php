<?php

declare(strict_types=1);

// Uygulamaya özgü ayarlar. MSSQL bağlantı tanımları .env'de DEĞİL,
// veritabanında tutulur (Ayarlar → SQL Bağlantıları); burada yalnızca
// kurulum/acil durum değerleri yer alır.
return [
    // Lokal fallback admin (AdminKullaniciSeeder)
    'admin_kullanici' => env('ERP_ADMIN_KULLANICI', 'admin'),
    'admin_sifre' => env('ERP_ADMIN_SIFRE'),
];
