<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Listede görünen doğrulama düzeyi (README §4.4): özet tamam = 1. düzey
 * (boyut, SHA-256, içindekiler ve veri blokları okunuyor); sınama geçti =
 * geçici veritabanına gerçekten geri yüklendi, sayılar manifestle tuttu.
 */
enum DogrulamaDurumu: string
{
    case Dogrulanmadi = 'dogrulanmadi';
    case OzetTamam = 'ozet_tamam';
    case SinamaGecti = 'sinama_gecti';
    case Basarisiz = 'basarisiz';
}
