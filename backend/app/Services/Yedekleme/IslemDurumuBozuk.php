<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * İşlem durumu dosyası okunamıyor ya da şemaya uymuyor. "İşlem yok" ANLAMINA
 * GELMEZ: dosyaya bakarak karar veren her çağıran (yayın, planlı yedek,
 * çalıştırıcı) bu durumda işi başlatmamalı (README §6.4).
 */
final class IslemDurumuBozuk extends RuntimeException
{
    public static function neden(string $yol, string $neden): self
    {
        return new self("İşlem durumu dosyası bozuk ({$yol}): {$neden}. Dosyayı inceleyin; içeriği bilinmeden işlem başlatılmaz.");
    }
}
