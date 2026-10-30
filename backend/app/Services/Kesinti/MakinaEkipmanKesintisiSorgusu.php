<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\MakinaEkipmanKesintisi;

/**
 * Makina Ekipman Kesintileri listesinin kolonları (akış: IcmalSorgusu).
 */
final class MakinaEkipmanKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'makina_ekipman_kesintileri';

    protected const MODEL = MakinaEkipmanKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma', 'kaynak_fis_no', 'makina_tanimi', 'aktivite', 'disiplin',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['makina_tanimi', 'kaynak_fis_no', 'kaynak_firma', 'mahal', 'aktivite', 'disiplin'];
}
