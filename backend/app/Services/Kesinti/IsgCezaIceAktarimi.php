<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\IsgCezaKesintisi;
use App\Models\KesintiYuklemesi;

/**
 * İSG Ceza Kesintisi aktarımının kolonları (akış: IcmalIceAktarimi). Excel'deki
 * uygunsuzluk tipi, risk, açıklama, ceza/uyarı ve madde kolonları alınmaz;
 * "UYGUNSUZLUK TESPİT YERİ" mahaldir.
 */
final class IsgCezaIceAktarimi extends IcmalIceAktarimi
{
    public const ICMAL = KesintiYuklemesi::ICMAL_ISG_CEZA;

    protected const MODEL = IsgCezaKesintisi::class;

    /** Sıra kullanıcının listesi (2026-10-31) */
    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL', 'UYGUNSUZLUK TESPİT YERİ'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'FİRMA'],
        'para_birimi' => ['PARA BİRİMİ'],
        'toplam_tutar' => ['TOPLAM TUTAR'],
        'iptal_edilen_tutar' => ['İPTAL EDİLEN TUTAR'],
        'yansitilan_tutar' => ['YANSITILAN TUTAR'],
        'kesilen_tutar' => ['KESİLEN TUTAR'],
        'kalan_tutar' => ['KALAN TUTAR'],
    ];

    protected const ZORUNLU_KOLONLAR = [
        'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'tarih', 'para_birimi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const METINLER = [
        'mahal' => [200, false], 'kaynak_firma' => [200, false],
    ];

    protected const SAYILAR = [
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    protected const SECENEKLER = [
        'yansitma_turu' => YansitmaTuru::class,
    ];
}
