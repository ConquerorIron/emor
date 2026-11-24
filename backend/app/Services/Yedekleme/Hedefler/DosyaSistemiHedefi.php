<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

/**
 * Sunucunun kendi dosya sistemine yazan hedef (Yerel, NAS). Servis kipinde
 * bu klasörlere çalıştırıcının hesabı yazar; web isteğindeki "Bağlantıyı
 * Sına" yazma iznini web hesabıyla sınarsa yanlış sonuç verir. Web yalnız
 * klasörün varlığını / paylaşımın bağlı olduğunu denetler (YF-21).
 */
interface DosyaSistemiHedefi extends HedefBaglayicisi
{
    /**
     * Klasör var (NAS'ta paylaşım bağlı ve klasör onun altında); yazılabilirlik
     * denetlenmez.
     *
     * @throws HedefHatasi
     */
    public function varlikDenetle(): void;
}
