<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use Carbon\CarbonImmutable;

/**
 * Doğrulanmış istek (IstekKutusu::oku): tür izin listesinde, parametreler
 * şemayla birebir ve türlerine uygun. Kullanıcının yetkisi ayrıca
 * IstekCalistiricisi'nde denetlenir.
 */
final readonly class Istek
{
    /**
     * @param  array<string, string|int|null>  $parametreler  IstekKutusu::TURLER[$tur] şemasında
     * @param  int|null  $kullaniciId  Boşsa sistem isteği (yalnız yayın öncesi yedek)
     */
    public function __construct(
        public string $kimlik,
        public string $tur,
        public array $parametreler,
        public ?int $kullaniciId,
        public CarbonImmutable $zaman,
    ) {}

    /** Şemada metin (ya da boş) olan parametre */
    public function metin(string $anahtar): ?string
    {
        $deger = $this->parametreler[$anahtar] ?? null;

        return is_string($deger) ? $deger : null;
    }
}
