<?php

declare(strict_types=1);

return [
    // Excel'e Aktar: icmal ekranındaki sekmeler, sekme başına bir Excel sayfası
    'excel' => [
        'data' => 'Data',
        'toplam' => 'TOPLAM',
        'satir_sayisi' => 'SATIR SAYISI',
        // Data sayfasının en solundaki satır numarası ve altındaki kayıt sayısı
        'sira_no' => 'SIRA NO',
        'toplam_kayit' => 'Toplam Kayıt: :adet',
        'pivot' => [
            'taseron' => 'Taşeron',
            'yansitma_turu' => 'Yansıtma Türü',
            'proje' => 'Proje',
            'vasif' => 'Vasfı',
            'mesai_turu' => 'Mesai Türü',
            'makina' => 'Makina',
            'disiplin' => 'Disiplin',
            'tarih' => 'Tarih',
        ],
    ],

    // Firmalar → Excel'e Aktar: ekrandaki kolon adlarıyla, sırasıyla
    'firmalar_excel' => [
        'baslik' => 'Firmalar',
        'kolonlar' => [
            'kod' => 'Firma Kodu',
            'ad' => 'Firma Adı',
            'vergi_kimlik_no' => 'Vergi Kimlik No',
            'vergi_dairesi' => 'Vergi Dairesi',
            'eposta' => 'E-posta',
            'gsm_no' => 'GSM',
            'kaynak' => 'Kaynak',
            'firmamiz' => 'Firmamız',
            'erp_guid' => 'ERP GUID',
        ],
        'erp' => 'ERP',
        'elle' => 'Elle',
        'evet' => 'Evet',
    ],
];
