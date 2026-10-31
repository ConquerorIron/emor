<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\IsgCezaKesintisi;

/**
 * İSG ceza satırında icmale özgü kolon yok; ortak kolonlar IcmalSatiriResource'ta.
 *
 * @mixin IsgCezaKesintisi
 */
final class IsgCezaKesintisiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        return [];
    }
}
