<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Plan sıklığı (README §4.2). Türe özgü değerler planın `siklik` alanında:
 * saatlik → aralik (N saat), isteğe bağlı gunler + saat_baslangic/saat_bitis;
 * n_gunluk → aralik (N gün); haftalik → gunler (1 = Pazartesi … 7 = Pazar);
 * aylik → ayin_gunu. Saatler İstanbul saatiyle.
 */
enum SiklikTuru: string
{
    case Saatlik = 'saatlik';
    case Gunluk = 'gunluk';
    case NGunluk = 'n_gunluk';
    case Haftalik = 'haftalik';
    case Aylik = 'aylik';
}
