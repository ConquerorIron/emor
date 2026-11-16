<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use Collator;

/**
 * Firma Özeti raporu (kullanıcı isteği 2026-11-13): taşeron + proje başına her
 * kesinti türünden kalan tutar yan yana (kesinti türlerinin pivotu) ve toplam
 * kalan. Tüm Kesintiler'den, Kesinti Özeti'nin parametreleriyle.
 *
 * Kalan yalnız taşerona yansıtılan satırlardan (Kesinti Özeti'nin "Kalan"ıyla
 * aynı tanım): HAB'ın üstlendiği kesintinin kalanı taşerondan kesilmez.
 * Taşerona yansıtılmış kesintisi olan her taşeron + proje listelenir; her
 * hücrenin kayıt sayısı da gelir — ekranda "-" kesinti yok, 0,00 kesinti var
 * ama tamamı kesilmiş (kullanıcı 2026-11-13: işgücünün kalanı sıfır olduğu
 * için kolon boş sanılıyordu). Hücreden ekstre: KesintiEkstresi.
 *
 * Menüde "Pivot" (kullanıcı isteği 2026-11-16), üç sekme: Özet (taşeron +
 * proje), Firma Özeti (yalnız taşeron, projeler toplanır) ve Proje Özeti
 * (yalnız proje, taşeronlar toplanır). Üçü de aynı hücre sorgusundan.
 */
final class FirmaOzeti
{
    public function __construct(private readonly TumKesintilerSorgusu $kesintiler) {}

    /**
     * @param  array{tarih: string, para_birimi_id: int, proje_id: list<int>, firma_id: list<int>, kesinti_turu_id?: list<int>}  $parametreler  ekran kesinti türü göndermez (boş = hepsi)
     * @return array{
     *     tarih: string,
     *     para_birimi: string,
     *     kesinti_turleri: list<array{id: int, kod: string, ad: string}>,
     *     satirlar: list<array{firma_id: int, taseron: string, taseron_ad: string, proje_id: int, proje: string, proje_ad: string, kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}>,
     *     firma_satirlari: list<array{firma_id: int, taseron: string, taseron_ad: string, kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}>,
     *     proje_satirlari: list<array{proje_id: int, proje: string, proje_ad: string, kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}>,
     *     toplam: array{kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string},
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
        $sifirAdet = array_fill(0, count($turler), 0);

        $satirlar = [];
        $firmaSatirlari = [];
        $projeSatirlari = [];
        $hucreler = $sorgu->clone()->toBase()
            ->join('firmalar', 'firmalar.id', '=', 'tum_kesintiler.firma_id')
            ->join('projeler', 'projeler.id', '=', 'tum_kesintiler.proje_id')
            ->groupBy('tum_kesintiler.firma_id', 'tum_kesintiler.proje_id', 'tum_kesintiler.kesinti_turu_id', 'firmalar.kod', 'firmalar.ad', 'projeler.kod', 'projeler.ad')
            ->select([
                'tum_kesintiler.firma_id', 'tum_kesintiler.proje_id', 'tum_kesintiler.kesinti_turu_id',
                'firmalar.kod AS taseron', 'firmalar.ad AS taseron_ad', 'projeler.kod AS proje', 'projeler.ad AS proje_ad',
            ])
            ->selectRaw('SUM(tum_kesintiler.kalan_tutar) AS kalan, COUNT(*) AS adet')
            ->get();

        foreach ($hucreler as $hucre) {
            $firma = [
                'firma_id' => (int) $hucre->firma_id,
                'taseron' => (string) $hucre->taseron,
                'taseron_ad' => (string) $hucre->taseron_ad,
            ];
            $proje = [
                'proje_id' => (int) $hucre->proje_id,
                'proje' => (string) $hucre->proje,
                'proje_ad' => (string) $hucre->proje_ad,
            ];
            $sira = $kolon[(int) $hucre->kesinti_turu_id];
            $kalan = round((float) $hucre->kalan, 2);
            $adet = (int) $hucre->adet;

            $anahtar = $hucre->firma_id.'-'.$hucre->proje_id;
            $satirlar[$anahtar] ??= [...$firma, ...$proje, 'kalanlar' => $bos, 'kayit_sayilari' => $sifirAdet];
            $satirlar[$anahtar]['kalanlar'][$sira] = $kalan;
            $satirlar[$anahtar]['kayit_sayilari'][$sira] = $adet;

            // Firma Özeti / Proje Özeti sekmeleri: aynı hücreler taşerona / projeye toplanır
            $firmaSatirlari[$firma['firma_id']] ??= [...$firma, 'kalanlar' => $bos, 'kayit_sayilari' => $sifirAdet];
            $firmaSatirlari[$firma['firma_id']]['kalanlar'][$sira] += $kalan;
            $firmaSatirlari[$firma['firma_id']]['kayit_sayilari'][$sira] += $adet;
            $projeSatirlari[$proje['proje_id']] ??= [...$proje, 'kalanlar' => $bos, 'kayit_sayilari' => $sifirAdet];
            $projeSatirlari[$proje['proje_id']]['kalanlar'][$sira] += $kalan;
            $projeSatirlari[$proje['proje_id']]['kayit_sayilari'][$sira] += $adet;
        }

        // Taşeron, sonra proje; kodlar doğal sırayla (ZONE 7, ZONE 10'dan önce)
        $siralayici = new Collator('tr_TR');
        $siralayici->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        $taserona = fn (array $a, array $b): int => (int) $siralayici->compare($a['taseron'], $b['taseron']);
        $projeye = fn (array $a, array $b): int => (int) $siralayici->compare($a['proje'], $b['proje']);
        $satirlar = array_values($satirlar);
        usort($satirlar, fn (array $a, array $b): int => $taserona($a, $b) ?: $projeye($a, $b));
        $firmaSatirlari = array_values($firmaSatirlari);
        usort($firmaSatirlari, $taserona);
        $projeSatirlari = array_values($projeSatirlari);
        usort($projeSatirlari, $projeye);

        $turToplamlari = $sorgu->clone()->toBase()
            ->groupBy('tum_kesintiler.kesinti_turu_id')
            ->select(['tum_kesintiler.kesinti_turu_id'])
            ->selectRaw('SUM(tum_kesintiler.kalan_tutar) AS kalan, COUNT(*) AS adet')
            ->get();
        $toplam = $bos;
        $toplamAdet = $sifirAdet;
        foreach ($turToplamlari as $turToplami) {
            $sira = $kolon[(int) $turToplami->kesinti_turu_id];
            $toplam[$sira] = round((float) $turToplami->kalan, 2);
            $toplamAdet[$sira] = (int) $turToplami->adet;
        }

        $cikti = fn (array $satir): array => [
            ...$satir,
            'kalanlar' => array_map($this->tutar(...), $satir['kalanlar']),
            'toplam_kalan' => $this->tutar(array_sum($satir['kalanlar'])),
        ];

        return [
            'tarih' => $parametreler['tarih'],
            'para_birimi' => (string) ParaBirimi::query()->whereKey($parametreler['para_birimi_id'])->value('kod'),
            'kesinti_turleri' => $turler,
            'satirlar' => array_map($cikti, $satirlar),
            'firma_satirlari' => array_map($cikti, $firmaSatirlari),
            'proje_satirlari' => array_map($cikti, $projeSatirlari),
            'toplam' => [
                'kalanlar' => array_map($this->tutar(...), $toplam),
                'kayit_sayilari' => $toplamAdet,
                'toplam_kalan' => $this->tutar((float) $sorgu->clone()->sum('tum_kesintiler.kalan_tutar')),
            ],
        ];
    }

    /**
     * Kolonlar: tüm kesinti türleri, bağlı oldukları ekranın menü sırasıyla.
     *
     * @return list<array{id: int, kod: string, ad: string}>
     */
    private function kesintiTurleri(): array
    {
        return array_map(
            fn (KesintiTuru $tur): array => ['id' => (int) $tur->id, 'kod' => (string) $tur->kod, 'ad' => (string) $tur->ad],
            KesintiTuru::ekranSirasiyla(),
        );
    }

    /** Noktalı iki ondalıklı metin (diğer kesinti uçlarıyla aynı) */
    private function tutar(float $deger): string
    {
        return number_format($deger, 2, '.', '');
    }
}
