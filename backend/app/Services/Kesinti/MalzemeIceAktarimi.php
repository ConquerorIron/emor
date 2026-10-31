<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KesintiYuklemesi;
use App\Models\MalzemeKesintisi;

/**
 * Malzeme Kesintisi aktarımının kolonları (akış: IcmalIceAktarimi). Excel'in
 * "Ambar Malzeme" sayfasındaki KDV / tevkifat kolonları alınmaz.
 */
final class MalzemeIceAktarimi extends IcmalIceAktarimi
{
    public const ICMAL = KesintiYuklemesi::ICMAL_MALZEME;

    protected const MODEL = MalzemeKesintisi::class;

    /** Sıra kullanıcının listesi (2026-10-31) */
    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'FİRMA'],
        'kaynak_irsaliye_no' => ['KAYNAK İRSALİYE NO', 'İRSALİYE NO'],
        'urun_tanimi' => ['ÜRÜN TANIMI'],
        'malzeme_birim_fiyati' => ['MALZEME BİRİM FİYATI', 'BİRİM FİYAT'],
        'miktar' => ['MİKTAR'],
        'birim' => ['BİRİM'],
        'para_birimi' => ['PARA BİRİMİ'],
        'toplam_tutar' => ['TOPLAM TUTAR'],
        'iptal_edilen_tutar' => ['İPTAL EDİLEN TUTAR'],
        'yansitilan_tutar' => ['YANSITILAN TUTAR'],
        'kesilen_tutar' => ['KESİLEN TUTAR'],
        'kalan_tutar' => ['KALAN TUTAR'],
    ];

    protected const ZORUNLU_KOLONLAR = [
        'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'tarih', 'urun_tanimi',
        'malzeme_birim_fiyati', 'miktar', 'para_birimi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const METINLER = [
        'mahal' => [200, false], 'kaynak_firma' => [200, false], 'kaynak_irsaliye_no' => [100, false],
        'urun_tanimi' => [200, true], 'birim' => [20, false],
    ];

    protected const SAYILAR = [
        'malzeme_birim_fiyati' => [4, 1e14], 'miktar' => [2, 1e10],
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    protected const SECENEKLER = [
        'yansitma_turu' => YansitmaTuru::class,
    ];
}
