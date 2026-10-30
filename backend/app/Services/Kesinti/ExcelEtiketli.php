<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

/**
 * Sabit seçenek listeleri (enum) için Excel yazımından eşleme. Büyük/küçük
 * harf, boşluk ve noktalama farkı gözetilmez ("Normal Mesai" = "NORMAL MESAİ").
 */
trait ExcelEtiketli
{
    /** Excel'deki ve ekrandaki yazım */
    abstract public function etiket(): string;

    public static function excelden(string $metin): ?self
    {
        $aranan = IceAktarimTablosu::anahtar($metin);

        foreach (self::cases() as $secenek) {
            if ($aranan === IceAktarimTablosu::anahtar($secenek->etiket()) || $aranan === IceAktarimTablosu::anahtar($secenek->value)) {
                return $secenek;
            }
        }

        return null;
    }
}
