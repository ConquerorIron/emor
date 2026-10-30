<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KampMalzemeKesintisi;

/**
 * Kamp Malzeme Kesintileri listesinin kolonları (akış: IcmalSorgusu).
 */
final class KampMalzemeKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'kamp_malzeme_kesintileri';

    protected const MODEL = KampMalzemeKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma', 'urun_tanimi', 'birim_fiyat', 'miktar', 'birim',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['urun_tanimi', 'kaynak_firma', 'mahal', 'birim'];
}
