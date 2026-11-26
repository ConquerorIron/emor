<?php

declare(strict_types=1);

namespace App\Services\Entegrator\IceAktarim;

/**
 * Statik kaynak listesinin içe aktarım girdisi: Excel dosyası YA DA Excel'den
 * kopyalanıp yapıştırılan metin (Kesinti içe aktarımıyla aynı iki yol).
 * Yapıştırmada ekran tablosu başlıksız, seçilen belge türünün standart kolon
 * sırasıyla gönderilir; tanımın birden çok türü varsa (e-Arşiv / e-SMM) tür
 * seçilmiş gelir.
 */
final readonly class StatikListeGirdisi
{
    private function __construct(
        public ?string $yol,
        public ?string $metin,
        public ?string $belgeTuru,
    ) {}

    public static function dosya(string $yol): self
    {
        return new self($yol, null, null);
    }

    public static function metin(string $metin, ?string $belgeTuru): self
    {
        return new self(null, $metin, $belgeTuru);
    }
}
