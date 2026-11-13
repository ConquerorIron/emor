<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Models\ParaBirimi;
use Collator;

/**
 * Firma Özeti raporu (kullanıcı isteği 2026-11-13): taşeron + proje başına her
 * kesinti türünden kalan tutar yan yana (kesinti türlerinin pivotu) ve toplam
 * kalan. Tüm Kesintiler'den, Kesinti Özeti'nin parametreleriyle.
 *
 * Kalan yalnız taşerona yansıtılan satırlardan (Kesinti Özeti'nin "Kalan"ıyla
 * aynı tanım): HAB'ın üstlendiği kesintinin kalanı taşerondan kesilmez.
 * Kalanı olmayan taşeron + proje satırı listelenmez. Hücreler firma, proje ve
 * kesinti türü kimliğiyle gelir (sonraki adım: hücreden ekstre).
 */
final class FirmaOzeti
{
    public function __construct(private readonly TumKesintilerSorgusu $kesintiler) {}

    /**
     * @param  array{tarih: string, para_birimi_id: int, proje_id: list<int>, firma_id: list<int>}  $parametreler
     * @return array{
     *     tarih: string,
     *     para_birimi: string,
     *     kesinti_turleri: list<array{id: int, kod: string, ad: string}>,
     *     satirlar: list<array{firma_id: int, taseron: string, taseron_ad: string, proje_id: int, proje: string, kalanlar: list<string>, toplam_kalan: string}>,
     *     toplam: array{kalanlar: list<string>, toplam_kalan: string},
     * }
     */
    public function hesapla(array $parametreler): array
    {
        $sorgu = $this->kesintiler->raporSorgusu($parametreler)
            ->where('tum_kesintiler.yansitma_turu', YansitmaTuru::Taseron->value);
        $turler = $this->kesintiTurleri();
        // Kesinti türü kimliği => kolon sırası
        $kolon = array_flip(array_column($turler, 'id'));
        $bos = array_fill(0, count($turler), 0.0);

        $satirlar = [];
        $hucreler = $sorgu->clone()->toBase()
            ->join('firmalar', 'firmalar.id', '=', 'tum_kesintiler.firma_id')
            ->join('projeler', 'projeler.id', '=', 'tum_kesintiler.proje_id')
            ->groupBy('tum_kesintiler.firma_id', 'tum_kesintiler.proje_id', 'tum_kesintiler.kesinti_turu_id', 'firmalar.kod', 'firmalar.ad', 'projeler.kod')
            ->select([
                'tum_kesintiler.firma_id', 'tum_kesintiler.proje_id', 'tum_kesintiler.kesinti_turu_id',
                'firmalar.kod AS taseron', 'firmalar.ad AS taseron_ad', 'projeler.kod AS proje',
            ])
            ->selectRaw('SUM(tum_kesintiler.kalan_tutar) AS kalan')
            ->get();

        foreach ($hucreler as $hucre) {
            $anahtar = $hucre->firma_id.'-'.$hucre->proje_id;
            $satirlar[$anahtar] ??= [
                'firma_id' => (int) $hucre->firma_id,
                'taseron' => (string) $hucre->taseron,
                'taseron_ad' => (string) $hucre->taseron_ad,
                'proje_id' => (int) $hucre->proje_id,
                'proje' => (string) $hucre->proje,
                'kalanlar' => $bos,
            ];
            $satirlar[$anahtar]['kalanlar'][$kolon[(int) $hucre->kesinti_turu_id]] = round((float) $hucre->kalan, 2);
        }

        $satirlar = array_values(array_filter(
            $satirlar,
            fn (array $satir): bool => array_filter($satir['kalanlar'], fn (float $kalan): bool => $kalan !== 0.0) !== [],
        ));
        // Taşeron, sonra proje; kodlar doğal sırayla (ZONE 7, ZONE 10'dan önce)
        $siralayici = new Collator('tr_TR');
        $siralayici->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        usort($satirlar, fn (array $a, array $b): int => (int) $siralayici->compare($a['taseron'], $b['taseron'])
            ?: (int) $siralayici->compare($a['proje'], $b['proje']));

        $turToplamlari = $sorgu->clone()->toBase()
            ->groupBy('tum_kesintiler.kesinti_turu_id')
            ->select(['tum_kesintiler.kesinti_turu_id'])
            ->selectRaw('SUM(tum_kesintiler.kalan_tutar) AS kalan')
            ->get();
        $toplam = $bos;
        foreach ($turToplamlari as $turToplami) {
            $toplam[$kolon[(int) $turToplami->kesinti_turu_id]] = round((float) $turToplami->kalan, 2);
        }

        return [
            'tarih' => $parametreler['tarih'],
            'para_birimi' => (string) ParaBirimi::query()->whereKey($parametreler['para_birimi_id'])->value('kod'),
            'kesinti_turleri' => $turler,
            'satirlar' => array_map(fn (array $satir): array => [
                ...$satir,
                'kalanlar' => array_map($this->tutar(...), $satir['kalanlar']),
                'toplam_kalan' => $this->tutar(array_sum($satir['kalanlar'])),
            ], $satirlar),
            'toplam' => [
                'kalanlar' => array_map($this->tutar(...), $toplam),
                'toplam_kalan' => $this->tutar((float) $sorgu->clone()->sum('tum_kesintiler.kalan_tutar')),
            ],
        ];
    }

    /**
     * Kolonlar: tüm kesinti türleri, bağlı oldukları ekranın menü sırasıyla
     * (KesintiYuklemesi::ICMALLER); ekrana bağlanmamışlar sonda, koduna göre.
     *
     * @return list<array{id: int, kod: string, ad: string}>
     */
    private function kesintiTurleri(): array
    {
        $ekranSirasi = array_flip(KesintiYuklemesi::ICMALLER);
        $sira = fn (KesintiTuru $tur): int => $tur->icmal === null ? PHP_INT_MAX : ($ekranSirasi[$tur->icmal] ?? PHP_INT_MAX);
        $siralayici = new Collator('tr_TR');
        $turler = KesintiTuru::query()->get(['id', 'kod', 'ad', 'icmal'])->all();
        usort($turler, fn (KesintiTuru $a, KesintiTuru $b): int => $sira($a) <=> $sira($b)
            ?: (int) $siralayici->compare($a->kod, $b->kod));

        return array_map(fn (KesintiTuru $tur): array => ['id' => (int) $tur->id, 'kod' => (string) $tur->kod, 'ad' => (string) $tur->ad], $turler);
    }

    /** Noktalı iki ondalıklı metin (diğer kesinti uçlarıyla aynı) */
    private function tutar(float $deger): string
    {
        return number_format($deger, 2, '.', '');
    }
}
