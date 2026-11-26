<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Faturanın UBL'inden okunan belge düzeyi vergi kalemi (KDV oranı, tevkifat…).
 * Yalnız UBL okuması yazar (EFaturaUblKaydi); fatura silinince kalemleri de gider.
 */
final class EFaturaVergiKalemi extends Model
{
    public const TUR_VERGI = 'vergi';

    public const TUR_TEVKIFAT = 'tevkifat';

    protected $table = 'efatura_vergi_kalemleri';

    public $timestamps = false;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sira' => 'integer',
            'oran' => 'decimal:4',
            'matrah' => 'decimal:4',
            'tutar' => 'decimal:4',
        ];
    }
}
