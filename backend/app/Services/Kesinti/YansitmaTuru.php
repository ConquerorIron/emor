<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

/**
 * Yansıtma türü: maliyet firmamıza (HAB) mı taşerona mı yansıtılıyor. Sabit
 * iki değer (kullanıcı kararı 2026-10-30; Excel'ler buna göre düzeltilecek).
 */
enum YansitmaTuru: string
{
    use ExcelEtiketli;

    case Hab = 'hab';
    case Taseron = 'taseron';

    public function etiket(): string
    {
        return match ($this) {
            self::Hab => 'HAB',
            self::Taseron => 'TAŞERON',
        };
    }
}
