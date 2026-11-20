<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Services\Yedekleme\Hedefler\HedefHatasi;

/**
 * Sunucu dışına giden kopyanın şifrelenmesi (README §4.7). Şifreleme
 * tanımlanmamışken kopya sessizce şifresiz gitmez: hata verir (BY-019 bu
 * sınıfı gpg ile doldurur).
 */
class KopyaSifreleyici
{
    /**
     * @return string Şifreli dosyanın yolu ($cikis)
     *
     * @throws HedefHatasi
     */
    public function sifrele(string $kaynak, string $cikis): string
    {
        throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Sunucu dışı hedefe kopya için şifreleme anahtarı tanımlı değil; kopya şifresiz gönderilmez.');
    }
}
