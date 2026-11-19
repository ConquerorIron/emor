<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use Carbon\CarbonImmutable;

/**
 * Protel giriş sonucu. Token yalnız istek başlığında kullanılır; günlüğe,
 * yanıta ya da hata mesajına yazılmaz.
 */
final readonly class ProtelToken
{
    public function __construct(
        public string $erisimToken,
        /** UTC */
        public CarbonImmutable $bitis,
        public string $firmaUnvani,
    ) {}
}
