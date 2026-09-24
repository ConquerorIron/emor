<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use Carbon\CarbonImmutable;

/**
 * İzibiz'den alınmış erişim token'ı. `bitis` UTC'ye çevrilmiş geçerlilik sonudur.
 */
final readonly class IzibizToken
{
    public function __construct(
        public string $erisimToken,
        public CarbonImmutable $bitis,
        public string $musteriTipi,
    ) {}
}
