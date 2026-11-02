<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KartBasimKesintisi;
use App\Models\KesintiYuklemesi;

/**
 * Personel Kart Basım Kesintisi aktarımının kolonları (akış: IcmalIceAktarimi).
 */
final class KartBasimIceAktarimi extends IcmalIceAktarimi
{
    public const ICMAL = KesintiYuklemesi::ICMAL_KART_BASIM;

    protected const MODEL = KartBasimKesintisi::class;

    /** Sıra kullanıcının listesi (2026-11-02) */
    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'FİRMA'],
        'personel_adi_soyadi' => ['PERSONEL ADI SOYADI', 'ADI SOYADI'],
        'urun_tanimi' => ['ÜRÜN TANIMI'],
        'kart_basim_nedeni' => ['KART BASIM NEDENİ'],
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
        'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'tarih', 'personel_adi_soyadi', 'urun_tanimi',
        'malzeme_birim_fiyati', 'miktar', 'para_birimi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const METINLER = [
        'mahal' => [200, false], 'kaynak_firma' => [200, false], 'personel_adi_soyadi' => [100, true],
        'urun_tanimi' => [100, true], 'kart_basim_nedeni' => [100, false], 'birim' => [20, false],
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
