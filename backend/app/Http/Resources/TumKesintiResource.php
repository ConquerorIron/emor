<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TumKesinti;

/**
 * @mixin TumKesinti
 */
final class TumKesintiResource extends IcmalSatiriResource
{
    /**
     * @return array<string, mixed>
     */
    protected function alanlar(): array
    {
        // id yalnız kendi icmalinde benzersiz: satırı id + icmal tanıtır
        return ['icmal' => $this->icmal];
    }
}
