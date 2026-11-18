<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * İşlem durumu dosyasındaki işlemin türü (BY-005). Yalnız üstüne geri yükleme
 * uygulamayı kapatır (bakım modu, kuyruk durur); "açık geri yükleme" sorgusu
 * yalnız ona bakar (README §6.4).
 */
enum IslemTuru: string
{
    case Yedek = 'yedek';
    case SinamaGeriYuklemesi = 'sinama_geri_yuklemesi';
    case GeriYuklemeKopya = 'geri_yukleme_kopya';
    case GeriYuklemeUstune = 'geri_yukleme_ustune';
}
