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

    // Kesinti Özeti → Excel'e Aktar: ekrandaki rapor (kullanıcının örnek Excel'indeki adlar)
    'ozet' => [
        'sayfa' => 'Kesinti Özeti',
        'sirket' => 'TERSANE İSTANBUL',
        'baslik' => ':donem İTİBARIYLA KESİNTİ LİSTESİ',
        'ay_sonu' => ':ay SONU',
        'butce' => 'BÜTÇE',
        'isveren' => 'İŞVEREN',
        'ozet' => '1. ÖZET',
        'genel' => 'GENEL',
        'hab' => 'HAB',
        'taseron' => 'TAŞERON',
        'dagilim' => '2. KESİNTİ DAĞILIMI',
        'proje_bazinda' => '2.1  ZONE BAZINDA',
        'tur_bazinda' => '2.2  KESİNTİ TÜRÜ BAZINDA',
        'taseron_durumu' => '3. TAŞERON KESİNTİ DURUMU',
        'en_yuksek' => '3.1  EN YÜKSEK KESİNTİLİ :adet TAŞERON',
        'toplam' => 'TOPLAM',
        'kolon' => [
            'proje' => 'ZONE',
            'kesinti_turu' => 'KESİNTİ TÜRÜ',
            'taseron_firma' => 'TAŞERON FİRMA',
            'toplam' => 'TOPLAM KESİNTİ',
            'hab' => 'HAB ÜSTLENDİĞİ',
            'taseron' => 'TAŞERON PAYI',
            'iptal' => 'İPTAL EDİLEN',
            'kesilen' => 'KESİLEN',
            'kalan' => 'KALAN',
        ],
        'oran' => [
            'tamami' => '%100',
            'toplamin' => 'TOPLAMIN %:oran',
            'taseron_payinin' => 'TAŞERON PAYININ %:oran',
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
