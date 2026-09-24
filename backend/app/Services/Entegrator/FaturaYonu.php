<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

/**
 * e-Fatura yönü; İzibiz'de gelen kutusu (inbox) / giden kutusu (outbox).
 */
enum FaturaYonu: string
{
    case Gelen = 'gelen';
    case Giden = 'giden';

    public function izibizKutusu(): string
    {
        return match ($this) {
            self::Gelen => 'inbox',
            self::Giden => 'outbox',
        };
    }
}
