<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KampKesintisi;

/**
 * Kamp (oda) Kesintileri listesinin kolonları (akış: IcmalSorgusu).
 */
final class KampKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'kamp_kesintileri';

    protected const MODEL = KampKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma', 'oda_tipi', 'toplam_oda', 'birim_fiyat',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['oda_tipi', 'kaynak_firma', 'mahal'];

    /** Tablonun altındaki / üstündeki toplam satırlarında tutarlarla birlikte toplanır */
    protected const EK_TOPLAMLAR = ['toplam_oda'];

    /** Özet sekmeleri (kullanıcı isteği 2026-10-30): sekme => gruplar */
    protected const PIVOTLAR = [
        'taseron' => ['taseron', 'proje', 'para_birimi'],
        'yansitma_turu' => ['yansitma_turu', 'proje', 'para_birimi'],
        'proje' => ['proje', 'para_birimi'],
    ];

    protected const PIVOT_TOPLAMLARI = ['toplam_oda'];
}
