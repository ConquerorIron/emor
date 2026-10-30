<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

/**
 * Mesai türü. Sabit iki değer (kullanıcı kararı 2026-10-30); Excel verisinde
 * toplam tutar normal mesaide yevmiye × birim fiyat, fazla mesaide saat ×
 * birim fiyattır.
 */
enum MesaiTuru: string
{
    use ExcelEtiketli;

    case Normal = 'normal';
    case Fazla = 'fazla';

    public function etiket(): string
    {
        return match ($this) {
            self::Normal => 'NORMAL MESAİ',
            self::Fazla => 'FAZLA MESAİ',
        };
    }
}
