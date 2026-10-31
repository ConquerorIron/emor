<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KesintiYuklemesi;
use App\Models\OsgbKesintisi;

/**
 * OSGB Kesintisi aktarımının kolonları (akış: IcmalIceAktarimi). Excel'deki
 * günlük (1–31) ve ilave kişi kolonları alınmaz; "TOPLAM" toplam kişidir.
 */
final class OsgbIceAktarimi extends IcmalIceAktarimi
{
    public const ICMAL = KesintiYuklemesi::ICMAL_OSGB;

    protected const MODEL = OsgbKesintisi::class;

    /** Sıra kullanıcının listesi (2026-10-31) */
    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'FİRMA'],
        'toplam_kisi' => ['TOPLAM KİŞİ', 'TOPLAM'],
        'birim_fiyat' => ['BİRİM FİYAT'],
        'para_birimi' => ['PARA BİRİMİ'],
        'toplam_tutar' => ['TOPLAM TUTAR'],
        'iptal_edilen_tutar' => ['İPTAL EDİLEN TUTAR'],
        'yansitilan_tutar' => ['YANSITILAN TUTAR'],
        'kesilen_tutar' => ['KESİLEN TUTAR'],
        'kalan_tutar' => ['KALAN TUTAR'],
    ];

    protected const ZORUNLU_KOLONLAR = [
        'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'tarih', 'toplam_kisi', 'birim_fiyat', 'para_birimi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const METINLER = [
        'mahal' => [200, false], 'kaynak_firma' => [200, false],
    ];

    protected const SAYILAR = [
        'toplam_kisi' => [2, 1e10], 'birim_fiyat' => [4, 1e14],
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    protected const SECENEKLER = [
        'yansitma_turu' => YansitmaTuru::class,
    ];
}
