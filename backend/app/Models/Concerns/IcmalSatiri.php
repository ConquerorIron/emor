<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Models\ParaBirimi;
use App\Models\Proje;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kesinti icmali satırlarının ortak ilişkileri: her icmal tablosunda aynı
 * adla duran tanım kolonları (kesinti_turu_id, firma_id, proje_id,
 * para_birimi_id) ve partisi (yukleme_id).
 */
trait IcmalSatiri
{
    /**
     * @return BelongsTo<KesintiTuru, $this>
     */
    public function kesintiTuru(): BelongsTo
    {
        return $this->belongsTo(KesintiTuru::class);
    }

    /**
     * Taşeron (kesinti yapılacak firma).
     *
     * @return BelongsTo<Firma, $this>
     */
    public function firma(): BelongsTo
    {
        return $this->belongsTo(Firma::class);
    }

    /**
     * @return BelongsTo<Proje, $this>
     */
    public function proje(): BelongsTo
    {
        return $this->belongsTo(Proje::class);
    }

    /**
     * @return BelongsTo<ParaBirimi, $this>
     */
    public function paraBirimi(): BelongsTo
    {
        return $this->belongsTo(ParaBirimi::class);
    }

    /**
     * @return BelongsTo<KesintiYuklemesi, $this>
     */
    public function yukleme(): BelongsTo
    {
        return $this->belongsTo(KesintiYuklemesi::class);
    }
}
