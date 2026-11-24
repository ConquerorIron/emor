<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Yedekleme işlerinin geçici çalışma klasörleri (`<önek><onaltılık>`, 0700).
 *
 * Silme işi sahibinin `finally` bloğundadır; süreç öldürülürse (SIGKILL,
 * bellek yetersizliği) o blok çalışmaz ve klasör kalır — içinde döküm
 * boyutunda dosya, günlüklerin düz hâli ya da (anahtarlıkta) özel anahtar
 * olabilir (YF-28). Bu yüzden her yeni klasör açılmadan önce aynı önekli,
 * hiçbir işin kullanıyor olamayacağı kadar eski artıklar silinir. Yalnız bu
 * sürecin kullanıcısına ait düz klasörler; sembolik bağlantı atlanır (D5).
 */
final class GeciciKlasor
{
    /**
     * Artıkları temizleyip yeni klasörü açar. Kök yoksa açılır (0700; üst
     * klasör var olmalı).
     *
     * @param  int  $azamiYas  Bundan eski (sn, değişiklik zamanı) aynı önekli klasörler artıktır
     *
     * @throws RuntimeException Kök ya da klasör açılamadı
     */
    public static function ac(string $kok, string $onEk, int $azamiYas, int $bayt = 6): string
    {
        $kok = rtrim($kok, '/');

        if (! is_dir($kok) && ! @mkdir($kok, 0700) && ! is_dir($kok)) {
            throw new RuntimeException("Geçici çalışma kökü açılamadı: {$kok}");
        }

        self::artiklariSil($kok, $onEk, 2 * $bayt, $azamiYas);
        $klasor = $kok.'/'.$onEk.bin2hex(random_bytes($bayt));

        // chmod: umask ve üst klasörün setgid biti ne olursa olsun yalnız sahibi
        if (! @mkdir($klasor, 0700) || ! @chmod($klasor, 0700)) {
            throw new RuntimeException("Geçici çalışma klasörü açılamadı: {$klasor}");
        }

        return $klasor;
    }

    /**
     * `$kok` altındaki `<önek><$hexUzunluk onaltılık>` klasörlerinden
     * `$azamiYas` saniyeden eski olanları siler. Bağlantı ve başkasının
     * klasörü atlanır; silinemeyen artık hata değildir (sonraki turda yine denenir).
     */
    public static function artiklariSil(string $kok, string $onEk, int $hexUzunluk, int $azamiYas): void
    {
        $kok = rtrim($kok, '/');
        $sinir = time() - $azamiYas;
        $desen = '/^'.preg_quote($onEk, '/').'[0-9a-f]{'.$hexUzunluk.'}$/D';
        $sahip = function_exists('posix_geteuid') ? posix_geteuid() : null;

        foreach (@scandir($kok) ?: [] as $ad) {
            $yol = $kok.'/'.$ad;
            $bilgi = preg_match($desen, $ad) === 1 ? @lstat($yol) : false;

            if ($bilgi === false || ($bilgi['mode'] & 0170000) !== 0040000 || $bilgi['mtime'] >= $sinir
                || ($sahip !== null && $bilgi['uid'] !== $sahip)) {
                continue;
            }

            self::sil($yol);
        }
    }

    /** Klasörü içindekilerle siler; bağlantıları izlemez (bağlantının kendisi silinir). */
    public static function sil(string $klasor): void
    {
        foreach (array_diff(@scandir($klasor) ?: [], ['.', '..']) as $ad) {
            $yol = $klasor.'/'.$ad;

            if (is_dir($yol) && ! is_link($yol)) {
                self::sil($yol);
            } else {
                @unlink($yol);
            }
        }

        @rmdir($klasor);
    }
}
