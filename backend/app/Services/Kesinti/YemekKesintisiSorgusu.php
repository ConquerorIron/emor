<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\YemekKesintisi;

/**
 * Yemek Kesintisi listesinin kolonları (akış: IcmalSorgusu).
 */
final class YemekKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'yemek_kesintileri';

    protected const MODEL = YemekKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma', 'kaynak_hakedis_no',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['kaynak_firma', 'kaynak_hakedis_no', 'mahal'];

    /** Özet sekmeleri (diğer icmallerdeki ortak düzen): sekme => gruplar; yalnız 5 tutar toplanır */
    protected const PIVOTLAR = [
        'taseron' => ['taseron', 'proje', 'para_birimi'],
        'yansitma_turu' => ['yansitma_turu', 'proje', 'para_birimi'],
        'proje' => ['proje', 'para_birimi'],
        'tarih' => ['yil', 'ay', 'proje', 'para_birimi'],
    ];
}
