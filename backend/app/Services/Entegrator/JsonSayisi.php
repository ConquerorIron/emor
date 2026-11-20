<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

/**
 * JSON'da tırnaksız gelen ondalıklı / üslü sayının (ya da PHP tam sayısına
 * sığmayan tam sayının) yanıttaki metni (PRT-R8, PRT-R12). Float'a hiç
 * çevrilmez. Ayrı tür olması, tırnaklı metinle (`"0"`) karıştırılmasını
 * önler: metin bekleyen alan bunu metin saymaz, sayı bekleyen alan metni
 * sayı saymaz.
 */
final readonly class JsonSayisi
{
    public function __construct(
        /** JSON belirteci olduğu gibi: `1786.0`, `-1.5e3`, `12345678901234567890` */
        public string $metin,
    ) {}
}
