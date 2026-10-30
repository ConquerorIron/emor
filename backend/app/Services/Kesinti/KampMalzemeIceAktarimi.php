<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KampMalzemeKesintisi;
use App\Models\KesintiYuklemesi;

/**
 * Kamp Malzeme Kesintileri aktarımının kolonları (akış: IcmalIceAktarimi).
 * Kamp dosyasının oda sayfası ürün/miktar kolonlarını taşımadığı için
 * okunmaz; yalnız malzeme sayfası aktarılır.
 */
final class KampMalzemeIceAktarimi extends IcmalIceAktarimi
{
    public const ICMAL = KesintiYuklemesi::ICMAL_KAMP_MALZEME;

    protected const MODEL = KampMalzemeKesintisi::class;

    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        // Taşeronun hemen yanında (kullanıcı kararı 2026-10-30)
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'FİRMA'],
        'urun_tanimi' => ['ÜRÜN TANIMI'],
        'birim_fiyat' => ['BİRİM FİYAT', 'MALZEME BİRİM FİYATI'],
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
        'birim_fiyat', 'miktar', 'para_birimi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const METINLER = [
        'mahal' => [200, false], 'kaynak_firma' => [200, false], 'urun_tanimi' => [200, true], 'birim' => [20, false],
    ];

    protected const SAYILAR = [
        'birim_fiyat' => [4, 1e14], 'miktar' => [2, 1e10],
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    protected const SECENEKLER = [
        'yansitma_turu' => YansitmaTuru::class,
    ];
}
