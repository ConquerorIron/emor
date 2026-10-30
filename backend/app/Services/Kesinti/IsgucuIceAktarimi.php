<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\IsgucuKesintisi;
use App\Models\KesintiYuklemesi;

/**
 * İşgücü Kesintileri aktarımının kolonları (akış: IcmalIceAktarimi).
 */
final class IsgucuIceAktarimi extends IcmalIceAktarimi
{
    public const ICMAL = KesintiYuklemesi::ICMAL_ISGUCU;

    protected const MODEL = IsgucuKesintisi::class;

    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        // Taşeronun hemen yanında (kullanıcı kararı 2026-10-30)
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA', 'YANSITMA 2'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'FİRMA'],
        'kaynak_fis_no' => ['KAYNAK FİŞ NO', 'NO'],
        'adi_soyadi' => ['ADI SOYADI'],
        'vasif' => ['VASFI'],
        'mesai_turu' => ['MESAİ TÜRÜ', 'NORMAL MESAİ M./ FAZLA M.'],
        'tanim' => ['TANIM'],
        'gorev' => ['GÖREV'],
        'departman' => ['DEPARTMAN', 'BİRİM'],
        'aktivite' => ['AKTİVİTE'],
        'miktar_saat' => ['MİKTAR (SAAT)'],
        'miktar_yevmiye' => ['MİKTAR (YEVMİYE)'],
        'birim_fiyat' => ['BİRİM FİYAT'],
        'para_birimi' => ['PARA BİRİMİ'],
        'toplam_tutar' => ['TOPLAM TUTAR'],
        'iptal_edilen_tutar' => ['İPTAL EDİLEN TUTAR'],
        'yansitilan_tutar' => ['YANSITILAN TUTAR'],
        'kesilen_tutar' => ['KESİLEN TUTAR'],
        'kalan_tutar' => ['KALAN TUTAR'],
    ];

    protected const ZORUNLU_KOLONLAR = [
        'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'tarih', 'adi_soyadi', 'vasif', 'mesai_turu',
        'miktar_saat', 'miktar_yevmiye', 'birim_fiyat', 'para_birimi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const METINLER = [
        'mahal' => [200, false], 'kaynak_firma' => [200, false], 'kaynak_fis_no' => [50, false], 'adi_soyadi' => [200, true],
        'tanim' => [200, false], 'gorev' => [200, false], 'departman' => [200, false], 'aktivite' => [200, false],
    ];

    protected const SAYILAR = [
        'miktar_saat' => [2, 1e8], 'miktar_yevmiye' => [2, 1e8], 'birim_fiyat' => [4, 1e14],
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    protected const SECENEKLER = [
        'vasif' => Vasif::class,
        'mesai_turu' => MesaiTuru::class,
        'yansitma_turu' => YansitmaTuru::class,
    ];
}
