<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Metin yardımcıları. Frontend eşleniği utils/metin.ts (trBuyukHarf).
 */
final class Metin
{
    /**
     * Türkçe kurallı büyük harf: mb_strtoupper 'i'yi 'I' yapar (nokta düşer);
     * önce i→İ ve ı→I eşlenir, kalan harfleri (ç, ş, ö, ü, ğ dahil)
     * mb_strtoupper doğru çevirir. Frontend eşleniği toLocaleUpperCase('tr-TR').
     */
    public static function buyukHarf(string $metin): string
    {
        return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], $metin), 'UTF-8');
    }
}
