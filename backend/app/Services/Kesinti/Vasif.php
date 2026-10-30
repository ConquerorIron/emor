<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

/**
 * İşçinin vasfı. Tanım tablosu yok, sabit iki değer (kullanıcı kararı 2026-10-30).
 */
enum Vasif: string
{
    use ExcelEtiketli;

    case Nitelikli = 'nitelikli';
    case Niteliksiz = 'niteliksiz';

    public function etiket(): string
    {
        return match ($this) {
            self::Nitelikli => 'NİTELİKLİ',
            self::Niteliksiz => 'NİTELİKSİZ',
        };
    }
}
