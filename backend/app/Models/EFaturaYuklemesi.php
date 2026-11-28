<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statik kaynağın (Logo, e-Arşiv / e-SMM) bir içe aktarım partisi (EK-06).
 * Parti silinince EKLEDİĞİ faturalar da silinir (cascade); güncellediği
 * faturalar eski hâline dönmez. Geri alma kuralları (elle işlem, sonraki
 * partinin yeniden yüklediği fatura → 409) EFaturaIceAktarimi::geriAl'dadır.
 *
 * @property int $id
 * @property int $entegrator_baglanti_id
 * @property string|null $belge_turu
 * @property string $dosya_adi
 * @property int|null $kullanici_id
 * @property string $kullanici_adi
 * @property int $yeni
 * @property int $guncellenen
 * @property int $degismeyen
 * @property CarbonImmutable $created_at
 */
final class EFaturaYuklemesi extends Model
{
    protected $table = 'efatura_yuklemeleri';

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
            'yeni' => 'integer',
            'guncellenen' => 'integer',
            'degismeyen' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
