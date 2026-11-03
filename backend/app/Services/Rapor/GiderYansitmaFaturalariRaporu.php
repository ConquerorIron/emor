<?php

declare(strict_types=1);

namespace App\Services\Rapor;

/**
 * Raporlar → Gider Yansıtma Faturaları (kullanıcı isteği 2026-11-03): ERP
 * proc'unun 22 kolonunun hepsi, proc sırasıyla; Para Kodu Döviz kolonlarının
 * hemen önünde. Her tutar TL karşılığıyla ve faturanın kendi para biriminde
 * (Döviz; TL faturada TL'nin aynısı) gelir. Canlı ölçüm: 493 satır ~0,1 sn;
 * FATURA_NO tekil. Toplamlar: TL kolonlarının genel toplamı + para koduna
 * göre Döviz kolonları.
 */
final class GiderYansitmaFaturalariRaporu extends ErpRaporu
{
    protected const PROC = 'dbo.ERP_GIDER_YANSITMA_FATURALARI';

    public const DIL = 'gider_yansitma_faturalari';

    public const ANAHTAR = 'fatura_no';

    public const DOSYA_ADI = 'gider-yansitma-faturalari.xlsx';

    public const KOLONLAR = [
        'tarih' => 'tarih',
        'fatura_no' => 'metin',
        'unvan' => 'metin',
        'proje_kodu' => 'metin',
        'aciklama' => 'metin',
        'kdv_matrahi_tl' => 'tutar',
        'kdv_tl' => 'tutar',
        'vergi_tl' => 'tutar',
        'tevkifat_tl' => 'tutar',
        'genel_toplam_tl' => 'tutar',
        'kesinti_toplami_tl' => 'tutar',
        'net_toplam_tl' => 'tutar',
        'para_kodu' => 'metin',
        'kdv_matrahi_doviz' => 'tutar',
        'kdv_doviz' => 'tutar',
        'vergi_doviz' => 'tutar',
        'tevkifat_doviz' => 'tutar',
        'genel_toplam_doviz' => 'tutar',
        'kesinti_toplami_doviz' => 'tutar',
        'net_toplam_doviz' => 'tutar',
        'kayit_eden' => 'metin',
        'guncelleyen' => 'metin',
    ];

    /** Rapor alanı => proc kolonu (proc'ta TL KDV matrahı "_TL" eki olmadan: KDV_MATRAHI) */
    private const KAYNAK = [
        'tarih' => 'TARIH',
        'fatura_no' => 'FATURA_NO',
        'unvan' => 'UNVAN',
        'proje_kodu' => 'PROJE_KODU',
        'aciklama' => 'ACIKLAMA',
        'kdv_matrahi_tl' => 'KDV_MATRAHI',
        'kdv_tl' => 'KDV_TL',
        'vergi_tl' => 'VERGI_TL',
        'tevkifat_tl' => 'TEVKIFAT_TL',
        'genel_toplam_tl' => 'GENEL_TOPLAM_TL',
        'kesinti_toplami_tl' => 'KESINTI_TOPLAMI_TL',
        'net_toplam_tl' => 'NET_TOPLAM_TL',
        'para_kodu' => 'PARA_KODU',
        'kdv_matrahi_doviz' => 'KDV_MATRAHI_DOVIZ',
        'kdv_doviz' => 'KDV_DOVIZ',
        'vergi_doviz' => 'VERGI_DOVIZ',
        'tevkifat_doviz' => 'TEVKIFAT_DOVIZ',
        'genel_toplam_doviz' => 'GENEL_TOPLAM_DOVIZ',
        'kesinti_toplami_doviz' => 'KESINTI_TOPLAMI_DOVIZ',
        'net_toplam_doviz' => 'NET_TOPLAM_DOVIZ',
        'kayit_eden' => 'KAYIT_EDEN_KULLANICI',
        'guncelleyen' => 'GUNCELLEYEN_KULLANICI',
    ];

    public function toplamSatirlari(array $satirlar): array
    {
        if ($satirlar === []) {
            return [];
        }

        $tutarlar = array_keys(array_filter(self::KOLONLAR, fn (string $tur): bool => $tur === 'tutar'));
        $tl = array_values(array_filter($tutarlar, fn (string $alan): bool => str_ends_with($alan, '_tl')));
        $doviz = array_values(array_filter($tutarlar, fn (string $alan): bool => str_ends_with($alan, '_doviz')));

        return [
            ['etiket' => __('rapor.genel_toplam_tl'), 'degerler' => $this->topla($satirlar, $tl)],
            ...$this->etiketle(__('rapor.toplam'), $this->paraKodunaGoreTopla($satirlar, $doviz)),
        ];
    }

    protected function satir(array $ham): array
    {
        $satir = [];

        foreach (self::KOLONLAR as $alan => $tur) {
            $deger = $ham[self::KAYNAK[$alan]] ?? null;
            $satir[$alan] = match ($tur) {
                'tarih' => $this->zaman($deger)?->toDateString(),
                'tutar' => $this->tutar($deger),
                'metin' => $this->metin($deger),
            };
        }

        return $satir;
    }

    /** En yeni fatura önce; aynı günde fatura numarası büyük olan önce */
    protected function karsilastir(array $a, array $b): int
    {
        return [$b['tarih'], $b['fatura_no']] <=> [$a['tarih'], $a['fatura_no']];
    }
}
