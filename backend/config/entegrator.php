<?php

declare(strict_types=1);

// e-Belge entegratörü uç adresleri. Kullanıcıdan ALINMAZ ve veritabanında
// tutulmaz: kayıtlı şifre yalnız buradaki sabit adreslere gönderilir.
// Hesap bilgileri (kullanıcı, şifre, VKN) Ayarlar → Entegratör Bağlantıları'nda.
return [
    'izibiz' => [
        'ortamlar' => [
            'test' => [
                'api_url' => 'https://apitest.izibiz.com.tr',
                'portal_url' => 'https://portaltest.izibiz.com.tr',
            ],
            'canli' => [
                'api_url' => 'https://api.izibiz.com.tr',
                'portal_url' => 'https://portal.izibiz.com.tr',
            ],
        ],

        // Token yanıtındaki `validity` saat dilimi eki taşımaz; İstanbul saatidir
        // (2026-09-23 test hesabında JWT exp ile karşılaştırılarak doğrulandı)
        'saat_dilimi' => 'Europe/Istanbul',

        // Token bitişinden bu kadar önce yenilenir (saat farkı ve istek süresi payı)
        'token_guvenlik_payi_saniye' => 300,

        'baglanti_zaman_asimi' => 5,
        'istek_zaman_asimi' => 20,

        // e-Fatura listeleme (EFAT-07). Doküman azami sayfa boyutunu 100 diyor;
        // test hesabı 500'ü de kabul etti ama belgelenmemiş davranışa güvenilmez.
        'sayfa_boyutu' => 100,
        // Tek okumada izlenecek en çok sayfa; aşılırsa okuma "eksik" döner
        // (çağıran aralığı böler). 500 × 100 = 50.000 kayıt.
        'azami_sayfa' => 500,
        // Tek okumada izin verilen en geniş tarih aralığı (gün)
        'azami_gun' => 366,

        // Zamanlanmış e-Fatura senkronu (EFAT-10). Acil durumda .env ile kapatılır.
        'senkron_aktif' => (bool) env('EFATURA_SENKRON_AKTIF', true),
        // Faz 1 ilk tarama başlangıcı (kullanıcı kararı S11)
        'ilk_tarama_tarihi' => '2026-01-01',
    ],
];
