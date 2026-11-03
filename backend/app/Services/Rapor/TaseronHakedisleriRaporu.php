<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Services\ErpOkumaHatasi;
use App\Services\Kesinti\ErpFirmaKaynagi;
use App\Services\Kesinti\ErpProjeKaynagi;
use Collator;

/**
 * Raporlar → Taşeron Hakedişleri (kullanıcı isteği 2026-11-03): ERP proc'unun
 * hakediş satırları proje + para kodu başına toplanır; kolonlar kullanıcının
 * verdiği sırayladır. Proje / firma seçenekleri proc'un süzgeç değerleridir:
 * proje KODU ve firma ADI (WEB_ERP_* view'ları; canlıda proc'un döndürdüğü
 * tüm proje ve firmalar bu listelerde).
 */
final class TaseronHakedisleriRaporu
{
    /**
     * Tutar kolonları, ekrandaki sırayla: rapor alanı => proc kolonu.
     *
     * @var array<string, string>
     */
    public const TUTARLAR = [
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

    public function __construct(
        private readonly TaseronHakedisKaynagi $kaynak,
        private readonly ErpProjeKaynagi $projeler,
        private readonly ErpFirmaKaynagi $firmalar,
    ) {}

    /**
     * Proje + para kodu başına toplamlar; proje adına (Türkçe sıra), sonra
     * para koduna göre sıralı. Proc tutarları float döner: her hakediş ERP'nin
     * gösterdiği gibi kuruşa yuvarlanıp toplanır.
     *
     * @param  array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}  $parametreler
     * @return list<array<string, string|float>>
     *
     * @throws ErpOkumaHatasi
     */
    public function ozet(int $erpKullaniciId, array $parametreler): array
    {
        /** @var array<string, array<string, string|float>> $gruplar */
        $gruplar = [];

        foreach ($this->kaynak->hakedisler($erpKullaniciId, $parametreler) as $hakedis) {
            $proje = trim((string) ($hakedis['PROJE_ADI'] ?? ''));
            $para = trim((string) ($hakedis['PARA_KODU'] ?? ''));
            $anahtar = $proje."\0".$para;
            $gruplar[$anahtar] ??= ['proje_adi' => $proje, 'para_kodu' => $para, ...array_fill_keys(array_keys(self::TUTARLAR), 0.0)];

            foreach (self::TUTARLAR as $alan => $kolon) {
                $gruplar[$anahtar][$alan] += round((float) ($hakedis[$kolon] ?? 0), 2);
            }
        }

        $siralayici = $this->siralayici();
        $satirlar = array_map($this->yuvarla(...), array_values($gruplar));
        usort($satirlar, fn (array $a, array $b): int => (int) $siralayici->compare((string) $a['proje_adi'], (string) $b['proje_adi'])
            ?: strcmp((string) $a['para_kodu'], (string) $b['para_kodu']));

        return $satirlar;
    }

    /**
     * Para kodu başına genel toplam (ekranın ve Excel'in TOPLAM satırları).
     *
     * @param  list<array<string, string|float>>  $satirlar  ozet()'in sonucu
     * @return list<array<string, string|float>>
     */
    public function toplamlar(array $satirlar): array
    {
        /** @var array<string, array<string, string|float>> $toplamlar */
        $toplamlar = [];

        foreach ($satirlar as $satir) {
            $para = (string) $satir['para_kodu'];
            $toplamlar[$para] ??= ['para_kodu' => $para, ...array_fill_keys(array_keys(self::TUTARLAR), 0.0)];

            foreach (array_keys(self::TUTARLAR) as $alan) {
                $toplamlar[$para][$alan] += (float) $satir[$alan];
            }
        }

        ksort($toplamlar);

        return array_map($this->yuvarla(...), array_values($toplamlar));
    }

    /**
     * Süzgeç seçenekleri: proc proje KODU ve firma ADIYLA süzer.
     *
     * @return array{projeler: list<array{kod: string, ad: string}>, firmalar: list<string>}
     *
     * @throws ErpOkumaHatasi
     */
    public function secenekler(): array
    {
        $siralayici = $this->siralayici();

        $projeListesi = [];
        foreach ($this->projeler->projeler() as $proje) {
            if ($proje['kod'] !== '') {
                $projeListesi[$proje['kod']] = ['kod' => $proje['kod'], 'ad' => $proje['ad']];
            }
        }
        $projeListesi = array_values($projeListesi);
        usort($projeListesi, fn (array $a, array $b): int => (int) $siralayici->compare($a['kod'], $b['kod']));

        $firmaAdlari = array_values(array_unique(array_filter(
            array_column($this->firmalar->firmalar(), 'ad'),
            fn (string $ad): bool => $ad !== '',
        )));
        $siralayici->sort($firmaAdlari);

        return ['projeler' => $projeListesi, 'firmalar' => $firmaAdlari];
    }

    /** Türkçe harf sırası; sayılar sayı gibi (ZONE 7, ZONE 10'dan önce) */
    private function siralayici(): Collator
    {
        $siralayici = new Collator('tr_TR');
        $siralayici->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

        return $siralayici;
    }

    /**
     * Toplamadaki float artıkları temizlenir (kuruş).
     *
     * @param  array<string, string|float>  $satir
     * @return array<string, string|float>
     */
    private function yuvarla(array $satir): array
    {
        foreach (array_keys(self::TUTARLAR) as $alan) {
            $satir[$alan] = round((float) $satir[$alan], 2);
        }

        return $satir;
    }
}
