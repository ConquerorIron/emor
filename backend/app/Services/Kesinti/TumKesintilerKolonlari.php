<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

/**
 * Tüm Kesintiler'in Excel'e aktarım kolonları: her icmalde ortak kolonlar,
 * kullanıcının sırasıyla. Bu ekrana veri aktarılmaz (rota yok); IcmalIceAktarimi'nin
 * yalnız kolon tanımı (excelKolonlari / excelBasligi / secenekEtiketi) kullanılır.
 */
final class TumKesintilerKolonlari extends IcmalIceAktarimi
{
    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI'],
        'yansitma_turu' => ['YANSITMA TÜRÜ'],
        'proje' => ['PROJE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA'],
        'para_birimi' => ['PARA BİRİMİ'],
        'toplam_tutar' => ['TOPLAM TUTAR'],
        'iptal_edilen_tutar' => ['İPTAL EDİLEN TUTAR'],
        'yansitilan_tutar' => ['YANSITILAN TUTAR'],
        'kesilen_tutar' => ['KESİLEN TUTAR'],
        'kalan_tutar' => ['KALAN TUTAR'],
    ];

    protected const SAYILAR = [
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    protected const SECENEKLER = [
        'yansitma_turu' => YansitmaTuru::class,
    ];
}
