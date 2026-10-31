<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\TetkikKesintisi;

/**
 * Tetkik Kesintisi listesinin kolonları (akış: IcmalSorgusu).
 */
final class TetkikKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'tetkik_kesintileri';

    protected const MODEL = TetkikKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma', 'adi_soyadi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['adi_soyadi', 'kaynak_firma', 'mahal'];

    /** Özet sekmeleri (kullanıcı isteği 2026-10-31: Yemek ile aynı); yalnız 5 tutar toplanır */
    protected const PIVOTLAR = [
        'taseron' => ['taseron', 'proje', 'para_birimi'],
        'yansitma_turu' => ['yansitma_turu', 'proje', 'para_birimi'],
        'proje' => ['proje', 'para_birimi'],
        'tarih' => ['yil', 'ay', 'proje', 'para_birimi'],
    ];
}
