<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * İmzalanan verinin tek biçimli JSON hâli: nesne anahtarları sıralı, liste
 * sırası korunur, kaçış seçenekleri sabit. Aynı veri her zaman aynı baytları
 * verir; imza dosyadaki girintiye ya da anahtar sırasına bağlı kalmaz.
 */
final class KanonikJson
{
    public static function kodla(mixed $veri): string
    {
        return json_encode(
            self::sirala($veri),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    private static function sirala(mixed $veri): mixed
    {
        if (! is_array($veri)) {
            return $veri;
        }

        if (! array_is_list($veri)) {
            ksort($veri, SORT_STRING);
        }

        return array_map(self::sirala(...), $veri);
    }
}
