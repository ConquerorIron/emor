<?php

declare(strict_types=1);

namespace App\Services\Rapor;

/**
 * Raporlar → Taşeron Hakedişleri (kullanıcı isteği 2026-11-03): ERP proc'unun
 * hakedişleri, hakediş başına bir satır, kullanıcının verdiği kolon sırasıyla;
 * en yeni hakediş önce. Canlı ölçüm: tüm tarihler 638 satır ~9 sn; HAKEDIS_ID
 * tekil. Toplamlar para koduna göre.
 */
final class TaseronHakedisleriRaporu extends ErpRaporu
{
    protected const PROC = 'dbo.DASHBOARD_TASERON_SOZLESME_HAKEDISLERI';

    public const DIL = 'taseron_hakedisleri';

    public const ANAHTAR = 'hakedis_id';

    public const DOSYA_ADI = 'taseron-hakedisleri.xlsx';

    public const KOLONLAR = [
        'firma_adi' => 'metin',
        'proje_adi' => 'metin',
        'sozlesme_no' => 'metin',
        'sozlesme_konusu' => 'metin',
        'hakedis_no' => 'metin',
        'hakedis_tarihi' => 'tarih',
        'donem_baslangici' => 'tarih',
        'donem_sonu' => 'tarih',
        'para_kodu' => 'metin',
        'imalat' => 'tutar',
        'tutanak' => 'tutar',
        'hakedis' => 'tutar',
        'imalat_kesintisi' => 'tutar',
        'kdv' => 'tutar',
        'kdvli_hakedis' => 'tutar',
        'kdv_tevkifati' => 'tutar',
        'cari_kesinti' => 'tutar',
        'yansitilacak_gider' => 'tutar',
        'taseron_adina_yapilan_is' => 'tutar',
        'net_odenecek' => 'tutar',
        'onay_durumu' => 'metin',
        'onaylayan' => 'metin',
        'onay_tarihi' => 'tarih',
        'onay_saati' => 'metin',
        'fatura_no' => 'metin',
        'hakedis_durumu' => 'metin',
        'odeme_talebi_tutari' => 'tutar',
        'odenen_tutar' => 'tutar',
    ];

    /** Tutar kolonları: rapor alanı => proc kolonu */
    private const TUTARLAR = [
        'imalat' => 'IMALAT_TUTARI',
        'tutanak' => 'TUTANAK_TUTARI',
        'hakedis' => 'HAKEDIS',
        'imalat_kesintisi' => 'IMALAT_KESINTI_TUTARI',
        'kdv' => 'KDV',
        'kdvli_hakedis' => 'KDVLI_HAKEDIS',
        'kdv_tevkifati' => 'KDV_TEVKIFATI',
        'cari_kesinti' => 'CARI_KESINTI',
        'yansitilacak_gider' => 'YANSITILACAK_GIDER',
        'taseron_adina_yapilan_is' => 'TASERON_ADINA_YAPILAN_IS',
        'net_odenecek' => 'NET_ODENECEK',
        'odeme_talebi_tutari' => 'ODEME_TALEBI_YAPILAN_TUTAR',
        'odenen_tutar' => 'ODENEN_TUTAR',
    ];

    private const METINLER = [
        'firma_adi' => 'FIRMA_ADI',
        'proje_adi' => 'PROJE_ADI',
        'sozlesme_no' => 'SOZLESME_NO',
        'sozlesme_konusu' => 'SOZLESME_KONUSU',
        'hakedis_no' => 'HAKEDIS_NO',
        'para_kodu' => 'PARA_KODU',
        'onay_durumu' => 'ONAY_DURUMU',
        'onaylayan' => 'ONAYLAYAN',
        'fatura_no' => 'FATURA_NO',
        'hakedis_durumu' => 'HAKEDIS_DURUMU',
    ];

    private const TARIHLER = [
        'hakedis_tarihi' => 'HAKEDIS_TARIHI',
        'donem_baslangici' => 'DONEM_BASLANGICI',
        'donem_sonu' => 'DONEM_SONU',
    ];

    public function toplamSatirlari(array $satirlar): array
    {
        return $this->etiketle(__('rapor.toplam'), $this->paraKodunaGoreTopla($satirlar, array_keys(self::TUTARLAR)));
    }

    /**
     * Onay zamanı tarih ve saat olarak ayrılır.
     */
    protected function satir(array $ham): array
    {
        $onay = $this->zaman($ham['ONAY_ZAMANI'] ?? null);
        $satir = ['hakedis_id' => (int) ($ham['HAKEDIS_ID'] ?? 0)];

        foreach (array_keys(self::KOLONLAR) as $alan) {
            $satir[$alan] = match (true) {
                isset(self::METINLER[$alan]) => $this->metin($ham[self::METINLER[$alan]] ?? null),
                isset(self::TARIHLER[$alan]) => $this->zaman($ham[self::TARIHLER[$alan]] ?? null)?->toDateString(),
                isset(self::TUTARLAR[$alan]) => $this->tutar($ham[self::TUTARLAR[$alan]] ?? null),
                $alan === 'onay_tarihi' => $onay?->toDateString(),
                $alan === 'onay_saati' => $onay?->format('H:i:s'),
            };
        }

        return $satir;
    }

    /** En yeni hakediş tarihi önce; aynı günde son açılan hakediş önce */
    protected function karsilastir(array $a, array $b): int
    {
        return [$b['hakedis_tarihi'], $b['hakedis_id']] <=> [$a['hakedis_tarihi'], $a['hakedis_id']];
    }
}
