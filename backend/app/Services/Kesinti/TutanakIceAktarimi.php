<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KesintiYuklemesi;
use App\Models\TutanakKesintisi;

/**
 * Tutanak Kesintisi aktarımının kolonları (akış: IcmalIceAktarimi). Excel'deki
 * malzeme / işçilik tutarı ve durum alınmaz.
 */
final class TutanakIceAktarimi extends IcmalIceAktarimi
{
    public const ICMAL = KesintiYuklemesi::ICMAL_TUTANAK;

    protected const MODEL = TutanakKesintisi::class;

    /** Sıra kullanıcının listesi (2026-10-31); ikinci başlıklar bugünkü Excel'in adları */
    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'FİRMA'],
        'kaynak_tutanak_no' => ['KAYNAK TUTANAK NO', 'TUTANAK NO'],
        'kaynak_sozlesme' => ['KAYNAK SÖZLEŞME', 'SÖZLEŞME KAPSAMI'],
        'kaynak_hakedis_no' => ['KAYNAK HAKEDİŞ NO', 'HAKEDİŞ NO'],
        'aktivite' => ['AKTİVİTE', 'İŞ KAPSAMI'],
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
        'mahal' => [200, false], 'kaynak_firma' => [200, false], 'kaynak_tutanak_no' => [100, false],
        'kaynak_sozlesme' => [100, false], 'kaynak_hakedis_no' => [100, false], 'aktivite' => [200, false],
    ];

    protected const SAYILAR = [
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    protected const SECENEKLER = [
        'yansitma_turu' => YansitmaTuru::class,
    ];
}
