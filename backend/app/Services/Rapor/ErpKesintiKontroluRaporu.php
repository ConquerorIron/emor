<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Models\Firma;
use App\Models\Proje;
use App\Services\Kesinti\TumKesintilerSorgusu;
use Collator;

/**
 * Raporlar → ERP Kesinti Kontrolü (kullanıcı isteği 2026-11-04): kesinti
 * icmallerine girilen satırlar Taşeron + Proje + Para Birimi bazında
 * toplanır (Tüm Kesintiler'in Taşeron özeti), yanına aynı grubun ERP'deki
 * Taşeron Hakedişleri "Yansıtılacak Gider" toplamı ve Gider Yansıtma
 * Faturaları genel toplamı konur. Fatura tutarı faturanın kendi para
 * biriminde ve KDV dahil: canlıda hakedişin yansıtılacak gideriyle birebir.
 *
 * Eşleşme adla (proc'lar kimlik döndürmez): taşeronun tam adı, proje kodu
 * (ERP'nin proje değeri projelerde ad olarak duruyorsa koduna çevrilir) ve
 * para birimi kodu; büyük / küçük harf ve boşluk farkı yok sayılır.
 *
 * Kesinti girilen projelerde ERP'de olup kesintisi olmayan gruplar da
 * listelenir (kesinti tutarları 0) — ör. kesinti TL, ERP faturası USD
 * girilmişse fark görünsün. Diğer projelerin ERP verisi raporun konusu değil.
 *
 * ERP'yi iki raporun kendisi okur (aynı parametreler, aynı kullanıcı
 * süzgeci); taban sınıfın tek proc'luk kaynağı kullanılmaz.
 */
final class ErpKesintiKontroluRaporu extends ErpRaporu
{
    public const DIL = 'erp_kesinti_kontrolu';

    /** Taşeron + proje + para biriminden türeyen kısa özet (adlar uzun; Excel isteği anahtar başına 100 karakter) */
    public const ANAHTAR = 'anahtar';

    public const DOSYA_ADI = 'erp-kesinti-kontrolu.xlsx';

    public const KOLONLAR = [
        'taseron' => 'metin',
        'proje' => 'metin',
        'para_kodu' => 'metin',
        'toplam_tutar' => 'tutar',
        'iptal_edilen_tutar' => 'tutar',
        'yansitilan_tutar' => 'tutar',
        'kesilen_tutar' => 'tutar',
        'kalan_tutar' => 'tutar',
        'hakedis_yansitilacak_gider' => 'tutar',
        'yansitma_faturasi' => 'tutar',
    ];

    /** Kesinti icmallerinin ortak tutarları */
    private const KESINTI_TUTARLARI = ['toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar'];

    private readonly Collator $siralayici;

    public function __construct(
        ErpRaporKaynagi $kaynak,
        private readonly TumKesintilerSorgusu $kesintiler,
        private readonly TaseronHakedisleriRaporu $hakedisler,
        private readonly GiderYansitmaFaturalariRaporu $faturalar,
    ) {
        parent::__construct($kaynak);
        $this->siralayici = new Collator('tr_TR');
        $this->siralayici->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
    }

    public function satirlar(int $erpKullaniciId, array $parametreler): array
    {
        $projeKodlari = $this->projeKodlari();
        $gruplar = [];

        foreach ($this->kesintiGruplari($parametreler) as $grup) {
            $gruplar[$this->eslesme($grup['taseron_ad'], $grup['proje'], $grup['para_birimi'])] = [
                'taseron' => $grup['taseron_ad'],
                'proje' => $grup['proje'],
                'para_kodu' => $grup['para_birimi'],
                ...array_map(fn (string $tutar): float => (float) $tutar, array_intersect_key($grup, array_flip(self::KESINTI_TUTARLARI))),
            ];
        }

        $kesintiProjeleri = array_flip(array_map(fn (array $grup): string => $this->bicim($grup['proje']), $gruplar));

        foreach ($this->hakedisler->satirlar($erpKullaniciId, $parametreler) as $hakedis) {
            $proje = $projeKodlari[$this->bicim($hakedis['proje_adi'])] ?? $hakedis['proje_adi'];
            $this->erpEkle($gruplar, $kesintiProjeleri, $hakedis['firma_adi'], $proje, $hakedis['para_kodu'], 'hakedis_yansitilacak_gider', $hakedis['yansitilacak_gider']);
        }

        foreach ($this->faturalar->satirlar($erpKullaniciId, $parametreler) as $fatura) {
            $proje = $projeKodlari[$this->bicim($fatura['proje_kodu'])] ?? $fatura['proje_kodu'];
            $this->erpEkle($gruplar, $kesintiProjeleri, $fatura['unvan'], $proje, $fatura['para_kodu'], 'yansitma_faturasi', $fatura['genel_toplam_doviz']);
        }

        $satirlar = array_map($this->satir(...), array_values($gruplar));
        usort($satirlar, $this->karsilastir(...));

        return $satirlar;
    }

    public function toplamSatirlari(array $satirlar): array
    {
        $tutarlar = array_keys(array_filter(self::KOLONLAR, fn (string $tur): bool => $tur === 'tutar'));

        return $this->etiketle(__('rapor.toplam'), $this->paraKodunaGoreTopla($satirlar, $tutarlar));
    }

    /** Birleşmiş grup → rapor satırı: anahtar eklenir, toplamalardaki float artıkları temizlenir */
    protected function satir(array $ham): array
    {
        $satir = ['anahtar' => hash('xxh3', $this->eslesme($ham['taseron'], $ham['proje'], $ham['para_kodu']))];

        foreach (self::KOLONLAR as $alan => $tur) {
            $satir[$alan] = $tur === 'tutar' ? $this->tutar($ham[$alan] ?? 0) : $this->metin($ham[$alan]);
        }

        return $satir;
    }

    /** Taşeron, proje, para birimi sırasıyla (Türkçe; sayılar sayı gibi: ZONE 2 < ZONE 10) */
    protected function karsilastir(array $a, array $b): int
    {
        foreach (['taseron', 'proje', 'para_kodu'] as $alan) {
            $fark = (int) $this->siralayici->compare((string) $a[$alan], (string) $b[$alan]);
            if ($fark !== 0) {
                return $fark;
            }
        }

        return 0;
    }

    /**
     * Kesinti satırları parametrelerle süzülür: tarih kesintinin tarihi; proje
     * kodu / firma adı ERP seçeneklerinden gelir, karşılığı yoksa satır yok.
     *
     * @param  array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}  $parametreler
     * @return list<array<string, int|string|null>>
     */
    private function kesintiGruplari(array $parametreler): array
    {
        $sorgu = $this->kesintiler->filtrele([
            'baslangic' => $parametreler['ilk_tarih'],
            'bitis' => $parametreler['son_tarih'],
            'proje_id' => $parametreler['proje_kodu'] === null
                ? null
                : (Proje::query()->where('kod', $parametreler['proje_kodu'])->value('id') ?? 0),
            'firma_id' => $parametreler['firma_adi'] === null
                ? null
                : (Firma::query()->where('ad', $parametreler['firma_adi'])->value('id') ?? 0),
        ]);

        return $this->kesintiler->pivot($sorgu, 'taseron');
    }

    /**
     * ERP tutarı eşleşen gruba eklenir. Eşleşmezse yalnız kesinti girilen bir
     * projedeyse ve tutarı varsa yeni grup açılır (kesinti tutarları 0).
     *
     * @param  array<string, array<string, string|float>>  $gruplar
     * @param  array<string, int>  $kesintiProjeleri  biçimlenmiş proje kodu => herhangi
     */
    private function erpEkle(array &$gruplar, array $kesintiProjeleri, string $taseron, string $proje, string $para, string $alan, float $tutar): void
    {
        $anahtar = $this->eslesme($taseron, $proje, $para);

        if (! isset($gruplar[$anahtar])) {
            if ($tutar === 0.0 || ! isset($kesintiProjeleri[$this->bicim($proje)])) {
                return;
            }

            $gruplar[$anahtar] = ['taseron' => $taseron, 'proje' => $proje, 'para_kodu' => $para];
        }

        $gruplar[$anahtar][$alan] = ($gruplar[$anahtar][$alan] ?? 0) + $tutar;
    }

    /**
     * ERP'nin proje değeri (biçimlenmiş) => projenin kodu: hem kod hem ad
     * tanınır (hakediş proc'u proje ADI, fatura proc'u proje KODU döndürür).
     *
     * @return array<string, string>
     */
    private function projeKodlari(): array
    {
        $kodlar = [];

        foreach (Proje::query()->get(['kod', 'ad']) as $proje) {
            $kodlar[$this->bicim($proje->ad)] ??= $proje->kod;
            $kodlar[$this->bicim($proje->kod)] = $proje->kod;
        }

        return $kodlar;
    }

    private function eslesme(string $taseron, string $proje, string $para): string
    {
        return $this->bicim($taseron)."\0".$this->bicim($proje)."\0".$this->bicim($para);
    }

    /** Türkçe büyük harf (i → İ, ı → I), tek boşluk */
    private function bicim(string $metin): string
    {
        return mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], preg_replace('/\s+/u', ' ', trim($metin)) ?? ''), 'UTF-8');
    }
}
