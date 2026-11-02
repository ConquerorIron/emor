<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KartBasimKesintisi;

/**
 * Personel Kart Basım Kesintisi listesinin kolonları (akış: IcmalSorgusu).
 */
final class KartBasimKesintisiSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'kart_basim_kesintileri';

    protected const MODEL = KartBasimKesintisi::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma', 'personel_adi_soyadi', 'urun_tanimi', 'kart_basim_nedeni',
        'malzeme_birim_fiyati', 'miktar', 'birim',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['personel_adi_soyadi', 'urun_tanimi', 'kart_basim_nedeni', 'kaynak_firma', 'mahal'];

    /** Özet sekmeleri: diğer kesinti ekranlarıyla aynı (kullanıcı isteği 2026-11-02); yalnız 5 tutar toplanır */
    protected const PIVOTLAR = [
        'taseron' => ['taseron', 'proje', 'para_birimi'],
        'yansitma_turu' => ['yansitma_turu', 'proje', 'para_birimi'],
        'proje' => ['proje', 'para_birimi'],
        'tarih' => ['yil', 'ay', 'proje', 'para_birimi'],
    ];
}
