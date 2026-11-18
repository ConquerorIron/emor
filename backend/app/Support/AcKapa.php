<?php

declare(strict_types=1);

namespace App\Support;

/**
 * `.env`'deki aç/kapa değerini yorumlar (config dosyaları kullanır; env()
 * çağrısı config dosyasında kalır).
 *
 * - Tanımsız ya da boş → varsayılan (Dikkat_Edilecekler G6).
 * - true / 1 / on / yes → açık; false / 0 / off / no → kapalı (büyük-küçük
 *   harf ve baştaki / sondaki boşluk önemsiz).
 * - Tanınmayan değer KAPALI: `(bool) 'off'` gibi "kapattım" sanılan modülü
 *   açık bırakmaz (G9).
 */
final class AcKapa
{
    public static function cevir(mixed $deger, bool $varsayilan): bool
    {
        if ($deger === null || $deger === '') {
            return $varsayilan;
        }

        return filter_var($deger, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }
}
