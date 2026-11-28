<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Faturanın belge düzeyi vergi kalemi (KDV oranı, tevkifat…). İki yazan var:
 * entegratör faturalarında UBL okuması (EFaturaUblKaydi), Logo listesinde içe
 * aktarım (IceAktarim\EFaturaIceAktarimi — kalemler statik tanımın faturalarına
 * yazılır, UBL kalemleriyle çakışmaz). Fatura silinince kalemleri de gider.
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
