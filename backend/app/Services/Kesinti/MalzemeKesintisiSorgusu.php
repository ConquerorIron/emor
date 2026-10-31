<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\MalzemeKesintisi;

/**
 * Malzeme Kesintisi listesinin kolonları (akış: IcmalSorgusu).
 */
final class MalzemeKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'malzeme_kesintileri';

    protected const MODEL = MalzemeKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma', 'kaynak_irsaliye_no', 'urun_tanimi',
        'malzeme_birim_fiyati', 'miktar', 'birim',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['urun_tanimi', 'kaynak_irsaliye_no', 'kaynak_firma', 'mahal'];

    /** Özet sekmeleri (kullanıcı isteği 2026-10-31: Yemek ile aynı); yalnız 5 tutar toplanır */
    protected const PIVOTLAR = [
        'taseron' => ['taseron', 'proje', 'para_birimi'],
        'yansitma_turu' => ['yansitma_turu', 'proje', 'para_birimi'],
        'proje' => ['proje', 'para_birimi'],
        'tarih' => ['yil', 'ay', 'proje', 'para_birimi'],
    ];
}
