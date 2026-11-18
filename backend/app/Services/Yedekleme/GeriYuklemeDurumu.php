<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Geri yükleme işleminin durumu (README §6.4). Asıl durum işlem durumu
 * DOSYASINDADIR (veritabanı geri yüklenirken değişir); bu kayıt geçmiştir.
 * onay_bekliyor: üstüne geri yüklemede yer değiştirme bitti, yönetici onayı
 * bekleniyor. geri_alindi: yönetici onaylamadı, adlar eski hâline çevrildi.
 */
enum GeriYuklemeDurumu: string
{
    case Kuyrukta = 'kuyrukta';
    case Calisiyor = 'calisiyor';
    case OnayBekliyor = 'onay_bekliyor';
    case Tamam = 'tamam';
    case Basarisiz = 'basarisiz';
    case GeriAlindi = 'geri_alindi';
}
