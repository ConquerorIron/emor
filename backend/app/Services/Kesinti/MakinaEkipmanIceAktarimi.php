<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KesintiYuklemesi;
use App\Models\MakinaEkipmanKesintisi;

/**
 * Makina Ekipman Kesintileri aktarımının kolonları (akış: IcmalIceAktarimi).
 * Excel'deki çalışma günü / saati, gün/ay ve aylık birim fiyat alınmaz.
 */
final class MakinaEkipmanIceAktarimi extends IcmalIceAktarimi
{
    public const ICMAL = KesintiYuklemesi::ICMAL_MAKINA_EKIPMAN;

    protected const MODEL = MakinaEkipmanKesintisi::class;

    /** Sıra kullanıcının listesi (2026-10-30) */
    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'KİRALANAN FİRMA'],
        'kaynak_fis_no' => ['KAYNAK FİŞ NO', 'FİŞ NUMARASI', 'FİŞ NO'],
        'makina_tanimi' => ['MAKİNA TANIMI', 'MAKİNE TANIMI'],
        'aktivite' => ['AKTİVİTE'],
        'disiplin' => ['DİSİPLİN'],
        'para_birimi' => ['PARA BİRİMİ'],
        'toplam_tutar' => ['TOPLAM TUTAR'],
        'iptal_edilen_tutar' => ['İPTAL EDİLEN TUTAR'],
        'yansitilan_tutar' => ['YANSITILAN TUTAR'],
        'kesilen_tutar' => ['KESİLEN TUTAR'],
        'kalan_tutar' => ['KALAN TUTAR'],
    ];

    protected const ZORUNLU_KOLONLAR = [
        'kesinti_turu', 'taseron', 'yansitma_turu', 'proje', 'tarih', 'makina_tanimi', 'para_birimi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const METINLER = [
        'mahal' => [200, false], 'kaynak_firma' => [200, false], 'kaynak_fis_no' => [100, false],
        'makina_tanimi' => [200, true], 'aktivite' => [200, false], 'disiplin' => [100, false],
    ];

    protected const SAYILAR = [
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    protected const SECENEKLER = [
        'yansitma_turu' => YansitmaTuru::class,
    ];
}
