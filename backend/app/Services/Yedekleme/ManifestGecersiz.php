<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/** Manifest şemaya uymuyor; okuyucu yedeği "güvenilmeyen" sınıflar. */
final class ManifestGecersiz extends RuntimeException {}
