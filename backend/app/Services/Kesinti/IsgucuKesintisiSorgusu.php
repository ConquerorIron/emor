<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\IsgucuKesintisi;

/**
 * İşgücü Kesintileri listesinin kolonları (akış: IcmalSorgusu).
 */
final class IsgucuKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'isgucu_kesintileri';

    protected const MODEL = IsgucuKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'mahal', 'kaynak_firma', 'kaynak_fis_no', 'adi_soyadi', 'vasif', 'mesai_turu', 'yansitma_turu',
        'tanim', 'gorev', 'departman', 'aktivite', 'miktar_saat', 'miktar_yevmiye', 'birim_fiyat',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['adi_soyadi', 'kaynak_fis_no', 'kaynak_firma', 'mahal', 'tanim', 'gorev', 'departman', 'aktivite'];

    /** Özet sekmeleri (kullanıcı isteği 2026-10-30): sekme => gruplar */
    protected const PIVOTLAR = [
        'taseron' => ['taseron', 'proje', 'para_birimi'],
        'yansitma_turu' => ['yansitma_turu', 'proje', 'para_birimi'],
        'proje' => ['proje', 'para_birimi'],
        'vasif' => ['vasif', 'proje', 'para_birimi'],
        'mesai_turu' => ['mesai_turu', 'proje', 'para_birimi'],
        'tarih' => ['yil', 'ay', 'proje', 'para_birimi'],
    ];

    protected const PIVOT_TOPLAMLARI = ['miktar_saat'];
}
