<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Yedek klasörü bu sistemin deposu olarak doğrulanamadı (YedekDeposu;
 * BY-011-R3): bağlanmamış disk ya da yanlış klasör olabilir. Tarama ve yedek
 * alma hiçbir şeye dokunmadan durur.
 */
final class DepoIsaretiYok extends RuntimeException {}
