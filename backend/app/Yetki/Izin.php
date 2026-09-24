<?php

declare(strict_types=1);

namespace App\Yetki;

/**
 * İzin kataloğu (EFAT-18). İzinler KODDA tanımlıdır — rollere ekrandan
 * atanır. Her değer aynı adla bir Gate'tir (AppServiceProvider); sistem
 * yöneticisi tüm izinlere sahiptir. Ayar / kullanıcı / rol ekranları izin
 * değil `sistem-yonetimi` Gate'iyle korunur.
 */
enum Izin: string
{
    case EfaturaGoruntule = 'efatura.goruntule';
    case EfaturaPdf = 'efatura.pdf';
    case EfaturaDisariAktar = 'efatura.disari_aktar';
    case EfaturaSenkron = 'efatura.senkron';

    /**
     * @return list<string>
     */
    public static function degerler(): array
    {
        return array_map(fn (self $izin): string => $izin->value, self::cases());
    }
}
