<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EFaturaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * İzibiz'den okunan e-Fatura özeti (EFAT-10). Kayıtlar yalnız senkron servisi
 * tarafından upsert edilir; ekrandan düzenlenmez.
 *
 * @property int $id
 * @property int $entegrator_baglanti_id
 * @property string $yon
 * @property int $kaynak_id
 * @property string $ettn
 * @property string $belge_no
 * @property CarbonImmutable $belge_tarihi
 * @property string $tutar
 * @property bool|null $erp_okundu
 */
final class EFatura extends Model
{
    /** @use HasFactory<EFaturaFactory> */
    use HasFactory;

    protected $table = 'efatura_faturalari';

    /** @var list<string> */
    protected $guarded = ['id'];

    /**
     * @return BelongsTo<EntegratorBaglanti, $this>
     */
    public function entegratorBaglanti(): BelongsTo
    {
        return $this->belongsTo(EntegratorBaglanti::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kaynak_id' => 'integer',
            'belge_tarihi' => 'immutable_date',
            'olusturma_zamani' => 'immutable_datetime',
            'tutar' => 'decimal:4',
            'vergi_tutari' => 'decimal:4',
            'satir_sayisi' => 'integer',
            'gib_durum_kodu' => 'integer',
            'erp_okundu' => 'boolean',
            'okundu' => 'boolean',
            'ilk_gorulme' => 'immutable_datetime',
            'son_gorulme' => 'immutable_datetime',
        ];
    }
}
