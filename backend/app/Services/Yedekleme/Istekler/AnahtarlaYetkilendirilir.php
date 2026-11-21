<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

/**
 * İşaret: bu işleyicinin isteğinde yetki kullanıcı tablosundan denetlenmez;
 * işleyici, üstüne geri yüklemenin erişim anahtarını işlem durumundan
 * doğrular (ErisimAnahtariylaYetkili; README §7.2, BY-029). Çalıştırıcı
 * (IstekCalistiricisi::yetkiDenetle) bu türlerde veritabanına bakmaz.
 */
interface AnahtarlaYetkilendirilir extends IstekIsleyicisi {}
