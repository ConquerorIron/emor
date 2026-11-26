<?php

declare(strict_types=1);

// e-Fatura Excel çıktısı ve alarm mailleri (EFAT-11, EFAT-13)
return [
    'evet' => 'Evet',
    'hayir' => 'Hayır',
    'sap_islendi' => 'İşlendi',
    'sap_red' => 'Red',
    // Kopyala-yapıştırla içe aktarılan partinin adı (Yüklemeler penceresi)
    'yapistirma_partisi' => 'Kopyala-yapıştır',

    'ortam' => [
        'test' => 'Test',
        'canli' => 'Canlı',
    ],

    // Tüm Faturalar'daki Entegratör kolonu
    'saglayici' => [
        'izibiz' => 'İzibiz',
        'protel' => 'Protel',
        'logo' => 'Logo',
        'earsiv' => 'E-Arşiv',
    ],

    // e-Arşiv / e-SMM ekranında belgenin türü (EK-08)
    'belge_turu' => [
        'fatura' => 'Fatura',
        'makbuz' => 'Makbuz',
    ],

    'alan' => [
        'belge_no' => 'Fatura No',
        'belge_tarihi' => 'Fatura Tarihi',
        'gonderici_vkn' => 'Gönderici VKN/TCKN',
        'gonderici_unvan' => 'Gönderici',
        'alici_vkn' => 'Alıcı VKN/TCKN',
        'alici_unvan' => 'Alıcı',
        'fatura_tipi' => 'Fatura Tipi',
        'senaryo' => 'Senaryo',
        'para_birimi' => 'Para Birimi',
        'tutar' => 'Tutar',
        'vergi_tutari' => 'Vergi',
        'durum' => 'Durum',
        'gib_durum' => 'GİB Durumu',
        'erp_okundu' => 'ERP Okudu',
        'ettn' => 'ETTN',
        'olusturma_zamani' => 'İzibiz\'e Ulaşma',
    ],

    // eMOR: e-faturanın ERP'deki aşaması (EmorDurumu)
    'emor' => [
        'islendi' => 'İşlendi',
        'elle_islendi' => 'İşlendi (elle)',
        'havuzda' => 'Havuzda',
        'yok' => 'ERP\'de yok',
    ],

    'excel' => [
        'baslik_gelen' => 'Gelen e-Faturalar',
        'baslik_giden' => 'Giden e-Faturalar',
        'baslik_tum' => 'Tüm Faturalar',
        'faturalar' => 'Faturalar',
        'ozet' => 'Özet',
        'liste' => 'Liste',
        'ortam' => 'Ortam',
        'tarih_araligi' => 'Fatura tarihi aralığı',
        'olusturuldu' => 'Oluşturulma',
        'adet' => 'Adet',
    ],

    // Pano (EK-09)
    'pano' => [
        'firmalar' => 'Firmalar',
    ],
];
