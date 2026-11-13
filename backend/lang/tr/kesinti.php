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

    // Firma Özeti → Excel'e Aktar: ekrandaki tablo
    'firma_ozeti' => [
        'sayfa' => 'Firma Özeti',
        'baslik' => 'Firma Özeti — :tarih itibarıyla kalan kesintiler (:para)',
        'toplam' => 'TOPLAM',
        'kolon' => [
            'taseron' => 'Taşeron',
            'taseron_ad' => 'Taşeron Adı',
            'proje' => 'Proje',
            'toplam_kalan' => 'Toplam Kalan',
        ],
    ],

    // Firma Özeti → hücre → kesinti ekstresi (PDF)
    'ekstre' => [
        // Başlık türü söyler (kullanıcı isteği 2026-11-13): "KAMP KESİNTİ EKSTRESİ"
        'baslik_tur' => ':tur KESİNTİ EKSTRESİ',
        'baslik_kesintisi' => ':tur EKSTRESİ',
        'baslik_tumu' => 'TÜM KESİNTİLER EKSTRESİ',
        'proje_kolonu' => 'PROJE',
        'sirket' => 'TERSANE İSTANBUL',
        'duzenlenme' => 'Düzenlenme: :tarih',
        'taseron' => 'Taşeron',
        'vkn' => 'VKN',
        'vergi_dairesi' => 'Vergi Dairesi',
        'proje' => 'Proje',
        'kesinti_turu' => 'Kesinti Türü',
        'tum_turler' => 'Tüm kesinti türleri',
        'tarih' => 'Tarih',
        'itibariyla' => ':tarih itibarıyla',
        'para_birimi' => 'Para Birimi',
        'isveren' => 'İşveren',
        'aciklama' => 'Taşerona yansıtılan kesintiler. Kalan: henüz kesilmemiş tutar (Toplam = İptal Edilen + Kesilen + Kalan).',
        'ozet' => 'ÖZET',
        'kayit' => 'Kayıt',
        'toplam' => 'TOPLAM',
        'tutar' => [
            'toplam_tutar' => 'Toplam',
            'iptal_edilen_tutar' => 'İptal Edilen',
            'yansitilan_tutar' => 'Yansıtılan',
            'kesilen_tutar' => 'Kesilen',
            'kalan_tutar' => 'Kalan',
        ],
        'sira_no' => 'SIRA',
        'bolum' => ':tur  ·  :adet kayıt',
        'devam' => '(devam)',
        'sayfa' => 'Sayfa :no / :toplam',
        'alt_bilgi' => 'eMOR · :baslik · :firma · :proje · :tarih itibarıyla',
        'kayit_yok' => 'Bu seçimde kesinti satırı yok.',
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
