<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Farklı ada ve sınama geri yüklemesinin adımları: işlem durumu dosyasındaki
 * `adim` ve `geri_yuklemeler.adimlar[].adim`. Serbest metin değil (Fable F17);
 * bitiş adımlarının değerleri KullanimDenetimi'nin "bitmiş" listesindedir.
 */
enum GeriYuklemeAdimi: string
{
    case Basladi = 'basladi';
    case Hazirlik = 'hazirlik';
    case Veritabani = 'veritabani';
    case PgRestore = 'pg_restore';
    case Karsilastirma = 'karsilastirma';
    case Silme = 'silme';
    case Bitti = 'bitti';
    case Basarisiz = 'basarisiz';
}
