<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Yarım hâli hiç görünmeyen dosya yazımı (Dikkat_Edilecekler D3): aynı
 * klasörde nokta önekli geçici ada yazılır, diske zorlanır, tek adımda yerine
 * taşınır, sonra klasör diske zorlanır. Hata yolunda geçici dosya silinir;
 * eski dosya (varsa) yerinde kalır. `tempnam` kullanılmaz: klasör yazılamazsa
 * sistem geçici klasörüne düşer ve taşıma tek adım olmaktan çıkar.
 */
final class AtomikDosya
{
    /**
     * @throws RuntimeException
     */
    public static function yaz(string $yol, string $icerik, int $izin = 0640): void
    {
        self::akisla($yol, $izin, function ($hedef) use ($icerik): bool {
            return fwrite($hedef, $icerik) === strlen($icerik);
        });
    }

    /**
     * Kaynağı $yol'a kopyalar (akışla; dosya belleğe alınmaz).
     *
     * @throws RuntimeException
     */
    public static function kopyala(string $kaynak, string $yol, int $izin = 0640): void
    {
        $giris = @fopen($kaynak, 'rb');
        if ($giris === false) {
            throw new RuntimeException("Kopyalanacak dosya açılamadı: {$kaynak}");
        }

        try {
            self::akisla($yol, $izin, function ($hedef) use ($giris): bool {
                $boyut = stream_copy_to_stream($giris, $hedef);

                return $boyut !== false && feof($giris);
            });
        } finally {
            fclose($giris);
        }
    }

    /** Geçici dosyanın adı: okuyanların ve taramanın görmediği nokta önekli ad */
    public static function geciciAd(string $yol): string
    {
        return dirname($yol).'/.'.basename($yol).'.'.bin2hex(random_bytes(6)).'.tmp';
    }

    /**
     * @param  callable(resource): bool  $yazici
     *
     * @throws RuntimeException
     */
    private static function akisla(string $yol, int $izin, callable $yazici): void
    {
        $klasor = dirname($yol);
        $gecici = self::geciciAd($yol);
        $tutamac = @fopen($gecici, 'xb');

        if ($tutamac === false) {
            throw new RuntimeException("Geçici dosya açılamadı: {$klasor}");
        }

        try {
            $yazildi = chmod($gecici, $izin)
                && $yazici($tutamac)
                && fflush($tutamac)
                && fsync($tutamac);
            fclose($tutamac);
            $tutamac = null;

            if (! $yazildi || ! rename($gecici, $yol)) {
                throw new RuntimeException("Dosya yazılamadı: {$yol}");
            }
        } finally {
            if ($tutamac !== null) {
                fclose($tutamac);
            }
            if (file_exists($gecici)) {
                @unlink($gecici);
            }
        }

        KaliciKlasor::esitle($klasor);
    }
}
