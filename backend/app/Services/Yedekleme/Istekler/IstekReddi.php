<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use RuntimeException;

/**
 * İstek işlenmedi: geçersiz, yetkisiz ya da ön koşulu tutmuyor. Çalıştırıcı
 * bunu `basarisiz` sonuca (makine okunur `kod`) ve denetim kaydına çevirir.
 * Mesaj, istekteki ham değeri taşımaz (D2).
 */
final class IstekReddi extends RuntimeException
{
    public function __construct(public readonly string $kod, string $mesaj)
    {
        parent::__construct($mesaj);
    }
}
