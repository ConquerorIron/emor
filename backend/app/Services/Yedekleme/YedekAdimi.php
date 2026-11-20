<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Yedek alma işleminin işlem durumu dosyasındaki adımları (IslemKaydi.adim).
 * Serbest metin değil: adımlar tek yerde tanımlıdır (Fable F17).
 */
enum YedekAdimi: string
{
    case Basladi = 'basladi';
    case PgDump = 'pg_dump';
    case Dogrulama = 'dogrulama';
    case Manifest = 'manifest';
    case Bitti = 'bitti';
    case Basarisiz = 'basarisiz';
}
