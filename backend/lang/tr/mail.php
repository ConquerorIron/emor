<?php

declare(strict_types=1);

// Uygulamanın gönderdiği maillerin metinleri
return [
    'test_konu' => 'eMOR ERP — SMTP test maili',
    'test_baslik' => 'SMTP ayarları çalışıyor',
    'test_govde' => 'Bu mail, :kullanici tarafından Ayarlar → Mail (SMTP) ekranından gönderilen test mailidir.',
    'test_not' => 'Bu maili aldıysanız uygulama mail gönderebiliyor demektir; yanıtlamanız gerekmez.',

    // e-Fatura alarmları (EFAT-13)
    'alarm' => [
        'yon_gelen' => 'Gelen',
        'yon_giden' => 'Giden',
        'yok' => 'yok',
        'test_ortami' => 'Bu bildirim entegratörün TEST hesabından üretildi.',
        'ekrani_ac' => 'Ekranı aç',
        'alt_not' => 'Bu mail Ayarlar → Alarm Kuralları ekranındaki kurala göre gönderildi. Ayrıntılar yalnız uygulama ekranında, yetkili kullanıcılara gösterilir.',

        'senkron_acildi_baslik' => ':yon e-Fatura senkronu çalışmıyor',
        'senkron_cozuldu_baslik' => ':yon e-Fatura senkronu düzeldi',
        'senkron_ardisik' => 'Art arda başarısız ya da eksik biten çalışma: :sayi',
        'senkron_veri_zamani' => 'Son başarılı güncelleme: :zaman',
        'senkron_son_hata' => 'Son hata: :kod',
        'senkron_etki' => 'Senkron düzelene kadar listeler ve diğer alarmlar güncel olmayan veriye dayanır.',
        'senkron_cozuldu' => 'Senkron yeniden başarılı çalıştı. Son başarılı güncelleme: :zaman',

        'ozet_baslik' => ':gun tarihinde gelen yeni e-Faturalar',
        'ozet_yon' => ':yon: :adet fatura — :tutarlar',
        'ozet_guncel_degil' => ':yon faturaların senkronu güncel değil; sayılar eksik olabilir.',
        'ozet_not' => 'Sayılar, faturanın uygulamada ilk görüldüğü güne göredir.',
        'ozet_ilk_tarama' => 'Bu gün ilk tarama yapıldı: sayılar o güne kadarki faturaların toplu yüklemesini içerir, yalnız o gün gelenleri değil.',

        'erp_baslik' => ':adet gelen e-Fatura ERP\'ye alınmadı',
        'erp_yeni' => 'İzibiz\'e ulaşalı :gun günden fazla olduğu hâlde ERP\'nin okumadığı :adet yeni fatura var.',
        'erp_acik' => 'Hâlâ okunmamış toplam fatura: :adet',
        'erp_not' => 'Liste ekranında "ERP Okudu = Hayır" filtresiyle görülebilir. Bu uygulama ERP okundu bayrağını değiştirmez.',
    ],
];
