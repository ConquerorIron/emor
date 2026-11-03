<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Services\ErpOkumaHatasi;
use App\Services\Kesinti\ErpFirmaKaynagi;
use App\Services\Kesinti\ErpProjeKaynagi;
use Carbon\CarbonImmutable;
use Collator;

/**
 * Raporlar → Taşeron Hakedişleri (kullanıcı isteği 2026-11-03): ERP proc'unun
 * hakedişleri, hakediş başına bir satır, kullanıcının verdiği kolon sırasıyla;
 * para koduna göre toplamlarıyla. Proje / firma seçenekleri proc'un süzgeç
 * değerleridir: proje KODU ve firma ADI (WEB_ERP_* view'ları; canlıda proc'un
 * döndürdüğü tüm proje ve firmalar bu listelerde).
 */
final class TaseronHakedisleriRaporu
{
    /**
     * Ekrandaki ve Excel'deki kolonlar, Sıra No'dan sonra kullanıcının sırasıyla.
     *
     * @var array<string, 'metin'|'tarih'|'tutar'>
     */
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

    /**
     * Tutar kolonları: rapor alanı => proc kolonu.
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

    /** Metin kolonları (nchar olanlar sonu boşlukla dolu gelir, kırpılır) */
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

    public function __construct(
        private readonly TaseronHakedisKaynagi $kaynak,
        private readonly ErpProjeKaynagi $projeler,
        private readonly ErpFirmaKaynagi $firmalar,
    ) {}

    /**
     * Hakediş başına bir satır, en yeni hakediş tarihi önce. Tarihler
     * Y-m-d; onay zamanı tarih ve saat olarak ayrılır. Proc tutarları float
     * döner: ERP'nin gösterdiği gibi kuruşa yuvarlanır.
     *
     * @param  array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}  $parametreler
     * @return list<array<string, int|string|float|null>>
     *
     * @throws ErpOkumaHatasi
     */
    public function hakedisler(int $erpKullaniciId, array $parametreler): array
    {
        $satirlar = array_map($this->satir(...), $this->kaynak->hakedisler($erpKullaniciId, $parametreler));
        usort($satirlar, fn (array $a, array $b): int => [$b['hakedis_tarihi'], $b['hakedis_id']] <=> [$a['hakedis_tarihi'], $a['hakedis_id']]);

        return $satirlar;
    }

    /**
     * Ekranda süzülmüş hakedişler, verilen (ekrandaki) sırayla; proc arada
     * değiştiyse artık olmayan kimlik atlanır.
     *
     * @param  list<array<string, int|string|float|null>>  $satirlar  hakedisler()'in sonucu
     * @param  list<int>  $idler
     * @return list<array<string, int|string|float|null>>
     */
    public function sec(array $satirlar, array $idler): array
    {
        $kimlige = array_column($satirlar, null, 'hakedis_id');

        return array_values(array_filter(array_map(fn (int $id): ?array => $kimlige[$id] ?? null, $idler)));
    }

    /**
     * Para kodu başına genel toplam (Excel'in TOPLAM satırları; ekran aynı
     * hesabı süzülmüş satırlarla kendisi yapar).
     *
     * @param  list<array<string, int|string|float|null>>  $satirlar  hakedisler()'in sonucu
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

        // Toplamadaki float artıkları temizlenir (kuruş)
        return array_values(array_map(function (array $toplam): array {
            foreach (array_keys(self::TUTARLAR) as $alan) {
                $toplam[$alan] = round((float) $toplam[$alan], 2);
            }

            return $toplam;
        }, $toplamlar));
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
        // Türkçe harf sırası; sayılar sayı gibi (ZONE 7, ZONE 10'dan önce)
        $siralayici = new Collator('tr_TR');
        $siralayici->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

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

    /**
     * Proc satırı → rapor satırı (KOLONLAR + satır kimliği hakedis_id).
     *
     * @param  array<string, mixed>  $hakedis
     * @return array<string, int|string|float|null>
     */
    private function satir(array $hakedis): array
    {
        $onay = $this->zaman($hakedis['ONAY_ZAMANI'] ?? null);
        $satir = ['hakedis_id' => (int) ($hakedis['HAKEDIS_ID'] ?? 0)];

        foreach (array_keys(self::KOLONLAR) as $alan) {
            $satir[$alan] = match (true) {
                isset(self::METINLER[$alan]) => trim((string) ($hakedis[self::METINLER[$alan]] ?? '')),
                isset(self::TARIHLER[$alan]) => $this->zaman($hakedis[self::TARIHLER[$alan]] ?? null)?->toDateString(),
                isset(self::TUTARLAR[$alan]) => round((float) ($hakedis[self::TUTARLAR[$alan]] ?? 0), 2),
                $alan === 'onay_tarihi' => $onay?->toDateString(),
                $alan === 'onay_saati' => $onay?->format('H:i:s'),
            };
        }

        return $satir;
    }

    private function zaman(mixed $deger): ?CarbonImmutable
    {
        return $deger === null || trim((string) $deger) === '' ? null : CarbonImmutable::parse((string) $deger);
    }
}
