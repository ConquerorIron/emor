<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\OsgbKesintisi;

/**
 * OSGB Kesintisi listesinin kolonları (akış: IcmalSorgusu).
 */
final class OsgbKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'osgb_kesintileri';

    protected const MODEL = OsgbKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma', 'toplam_kisi', 'birim_fiyat',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['kaynak_firma', 'mahal'];

    /** Tablonun altındaki / üstündeki toplam satırlarında tutarlarla birlikte toplanır (Kamp'taki toplam oda gibi) */
    protected const EK_TOPLAMLAR = ['toplam_kisi'];

    /** Özet sekmeleri (kullanıcı isteği 2026-10-31: Yemek ile aynı); yalnız 5 tutar toplanır */
    protected const PIVOTLAR = [
        'taseron' => ['taseron', 'proje', 'para_birimi'],
        'yansitma_turu' => ['yansitma_turu', 'proje', 'para_birimi'],
        'proje' => ['proje', 'para_birimi'],
        'tarih' => ['yil', 'ay', 'proje', 'para_birimi'],
    ];
}
