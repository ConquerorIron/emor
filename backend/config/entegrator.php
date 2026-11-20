<?php

declare(strict_types=1);
use App\Support\AcKapa;

// e-Belge entegratörü ayarları. Aşağıdaki adresler ortamların VARSAYILANIDIR;
// tanımda ekrandan başka adres girilebilir ama yalnız https ve sağlayıcının
// izinli alan adı (kayıtlı şifre başka sunucuya gitmesin). Hesap bilgileri
// (kullanıcı, şifre, VKN, adresler) Ayarlar → Entegratör Bağlantıları'nda.
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

        // Yukarıdakiler VARSAYILAN adreslerdir; ekrandan başka adres girilebilir
        // (kullanıcı isteği 2026-09-24) ama yalnız https ve bu alan adları (alt
        // alan adları dahil) kabul edilir: kayıtlı şifre ve token başka bir
        // sunucuya gönderilemesin. Yeni sağlayıcı alan adı gerekirse buraya eklenir.
        'izinli_alan_adlari' => ['izibiz.com.tr'],

        // Token yanıtındaki `validity` saat dilimi eki taşımaz; İstanbul saatidir
        // (2026-09-23 test hesabında JWT exp ile karşılaştırılarak doğrulandı).
        // Protel tarihleri de ekisiz İstanbul saatidir (createAt, 2026-11-19):
        // e-Fatura modülünün bütün takvim / saat çevirileri bu değeri kullanır.
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
        // Tanınmayan değer (ör. "hayir") KAPALI sayılır; boş bırakılırsa açık (AcKapa)
        'senkron_aktif' => AcKapa::cevir(env('EFATURA_SENKRON_AKTIF'), true),
        // Faz 1 ilk tarama başlangıcı (kullanıcı kararı S11)
        'ilk_tarama_tarihi' => '2026-01-01',
    ],

    // Otelin e-Fatura posta kutusu: Protel markalı Süper Entegratör REST API'si
    // (docs/protel). YALNIZ OKUMA — izinli metotlar ve gövde alanları
    // ProtelIstemcisi'nde kilitlidir. Aşağıdaki adresler ortamların
    // varsayılanıdır; tanımda ekrandan başka adres girilebilir (İzibiz gibi),
    // ama yalnız https ve izinli alan adıyla (kayıtlı şifre başka sunucuya gitmesin).
    'protel' => [
        'ortamlar' => [
            'test' => [
                'auth_url' => 'https://authrestapitest.protel.com.tr',
                'api_url' => 'https://einvoicerestapitest.protel.com.tr',
            ],
            'canli' => [
                'auth_url' => 'https://authrestapi.protel.com.tr',
                'api_url' => 'https://einvoicerestapi.protel.com.tr',
            ],
        ],

        // İstek yalnız bu alan adlarına (https) çıkar — config yanlış
        // düzenlense de şifre ve token başka sunucuya gitmesin
        'izinli_alan_adlari' => ['protel.com.tr'],

        // Modül anahtarı (PRT-03). KAPALIYKEN Protel'e hiçbir istek gitmez
        // (sınama, senkron, PDF dahil — tek giriş noktası ProtelIstemcisi).
        // Varsayılan KAPALI: tanınmayan / boş değer kapalı sayılır (AcKapa)
        'aktif' => AcKapa::cevir(env('PROTEL_AKTIF'), false),

        // Token 24 saat geçerli (JWT `exp`); bitişten bu kadar önce yenilenir
        'token_guvenlik_payi_saniye' => 300,

        'baglanti_zaman_asimi' => 5,
        // 180 günlük liste ~2,5 sn, PDF ~1,5 sn (2026-11-19 ölçümü). Liste toplu
        // iştir (zamanlayıcı / kuyruk); giriş ve PDF kullanıcı isteğinde de
        // çalışır, web işçisini uzun tutmasın (Fable incelemesi PRT-F2)
        'istek_zaman_asimi' => 60,
        'kisa_istek_zaman_asimi' => 15,

        // API'nin tek istekteki tarih aralığı sınırı (aşılırsa 400)
        'azami_gun' => 180,

        'ilk_tarama_tarihi' => '2026-01-01',
    ],
];
