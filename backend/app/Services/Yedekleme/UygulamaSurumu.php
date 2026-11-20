<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Çalışan kodun git commit'i (manifestin `uygulama_surumu`; README §5.3).
 * `git` çalıştırılmaz: depo dosyaları okunur (HEAD → dal → nesne kimliği,
 * gerekirse packed-refs). Okunamazsa null — yedeği engellemez.
 */
final class UygulamaSurumu
{
    public static function oku(?string $depo = null): ?string
    {
        $git = ($depo ?? dirname(base_path())).'/.git';
        $head = @file_get_contents($git.'/HEAD');

        if (! is_string($head)) {
            return null;
        }

        $head = trim($head);
        if (preg_match('/^[0-9a-f]{40}$/D', $head) === 1) {
            return substr($head, 0, 12);
        }

        if (preg_match('#^ref: (refs/heads/[A-Za-z0-9._/-]+)$#D', $head, $p) !== 1) {
            return null;
        }

        $nesne = @file_get_contents($git.'/'.$p[1]);
        if (is_string($nesne) && preg_match('/^[0-9a-f]{40}$/D', trim($nesne)) === 1) {
            return substr(trim($nesne), 0, 12);
        }

        foreach (@file($git.'/packed-refs', FILE_IGNORE_NEW_LINES) ?: [] as $satir) {
            if (preg_match('/^([0-9a-f]{40}) '.preg_quote($p[1], '/').'$/D', $satir, $e) === 1) {
                return substr($e[1], 0, 12);
            }
        }

        return null;
    }
}
