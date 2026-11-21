<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Closure;
use InvalidArgumentException;

/**
 * Birden çok adımı olan etkileşimli işin TOPLAM süre bütçesi (D9). Süre
 * monoton saatle ölçülür (D1): sistem saati ayarlanınca bütçe uzayıp kısalmaz.
 * Saat testte sahte verilebilir (ns döndüren kapanış).
 */
final class SureButcesi
{
    private readonly int $sonNs;

    /** @var Closure(): int */
    private readonly Closure $saat;

    /**
     * @param  (Closure(): int)|null  $saat  Monoton saat (ns); boşsa hrtime
     */
    public function __construct(int $saniye, ?Closure $saat = null)
    {
        if ($saniye < 1) {
            throw new InvalidArgumentException('Süre bütçesi pozitif bir saniye olmalı.');
        }

        $this->saat = $saat ?? static fn (): int => hrtime(true);
        $this->sonNs = ($this->saat)() + $saniye * 1_000_000_000;
    }

    /** Kalan süre (sn); bütçe aşıldıysa 0 */
    public function kalanSaniye(): float
    {
        return max(0.0, ($this->sonNs - ($this->saat)()) / 1_000_000_000);
    }
}
