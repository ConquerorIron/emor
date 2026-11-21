<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

/**
 * İşleyicinin sonucu; `sonuc/<kimlik>.json`'a yazılır (ekran yoklar).
 * `ek` türe özgü alanlardır (yedek_kimligi, geri_yukleme_kimligi,
 * hedef_veritabani …); temel alanları (kimlik, durum, kod, mesaj, zaman) ezemez.
 */
final readonly class IstekSonucu
{
    public const TAMAM = 'tamam';

    public const BASARISIZ = 'basarisiz';

    /**
     * @param  array<string, mixed>  $ek
     */
    private function __construct(
        public string $durum,
        public ?string $kod,
        public ?string $mesaj,
        public array $ek,
    ) {}

    /**
     * @param  array<string, mixed>  $ek
     */
    public static function tamam(array $ek = []): self
    {
        return new self(self::TAMAM, null, null, $ek);
    }

    /**
     * @param  array<string, mixed>  $ek
     */
    public static function basarisiz(string $kod, ?string $mesaj, array $ek = []): self
    {
        return new self(self::BASARISIZ, $kod, $mesaj, $ek);
    }

    /**
     * Türe özgü bağlam alanları eklenmiş kopya; işleyicinin kendi alanları önceliklidir.
     *
     * @param  array<string, mixed>  $baglam
     */
    public function baglamla(array $baglam): self
    {
        return new self($this->durum, $this->kod, $this->mesaj, [...$baglam, ...$this->ek]);
    }
}
