<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Kesinti icmali listesinin Excel (.xlsx) çıktısı (kullanıcı istekleri 2026-10-30):
 *
 * - İlk sayfa: filtredeki TÜM satırlar ve ekranda gizli olanlar dahil TÜM
 *   kolonlar, yapıştırma sırasıyla (IcmalIceAktarimi::excelKolonlari). Tanımlar
 *   koduyla, seçenekler Excel etiketiyle — sayfa ekrana geri yüklenebilir
 *   (bu yüzden altına toplam satırı yazılmaz).
 * - Ekranın özet (pivot) sekmeleri varsa her biri ayrı sayfa, sekme adıyla;
 *   altında para birimine göre TOPLAM satırları.
 *
 * Metin hücreleri açıkça metin tipiyle yazılır: "=", "+", "-", "@" ile başlayan
 * değer formül olarak yorumlanmaz (Excel enjeksiyonu).
 */
final class IcmalExcelAktarici
{
    /** Tanım alanının satırdaki ilişkisi */
    private const ILISKILER = ['kesinti_turu' => 'kesintiTuru', 'taseron' => 'firma', 'proje' => 'proje', 'para_birimi' => 'paraBirimi'];

    /**
     * @param  Builder<Model>  $sorgu  filtrelenmiş ve sıralanmış sorgu
     * @param  list<array{ad: string, gruplar: list<string>, toplamlar: list<string>, satirlar: list<array<string, int|string|null>>}>  $pivotlar  ekrandaki özet sekmeleri, sırasıyla
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(Builder $sorgu, IcmalIceAktarimi $aktarim, string $baslik, array $pivotlar = []): string
    {
        IceAktarimTablosu::bellekSiniriniYukselt();

        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle($baslik);
        // Sekmeli ekranda ilk sayfa ekrandaki gibi "Data"; Excel sayfa adı en fazla 31 karakter
        $this->satirlariYaz($kitap->getActiveSheet()->setTitle(mb_substr($pivotlar === [] ? $baslik : __('kesinti.excel.data'), 0, 31)), $sorgu, $aktarim);

        foreach ($pivotlar as $pivot) {
            $this->pivotYaz($kitap->createSheet()->setTitle(mb_substr($pivot['ad'], 0, 31)), $pivot, $aktarim);
        }

        $kitap->setActiveSheetIndex(0);
        $yol = tempnam(sys_get_temp_dir(), 'kesinti-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('Kesinti Excel geçici dosyası oluşturulamadı.');
        }

        try {
            (new Xlsx($kitap))->save($yol);
        } catch (\Throwable $hata) {
            unlink($yol);

            throw $hata;
        } finally {
            $kitap->disconnectWorksheets();
        }

        return $yol;
    }

    /**
     * @param  Builder<Model>  $sorgu
     */
    private function satirlariYaz(Worksheet $sayfa, Builder $sorgu, IcmalIceAktarimi $aktarim): void
    {
        $kolonlar = $aktarim->excelKolonlari();

        foreach ($kolonlar as $i => $kolon) {
            $sayfa->setCellValueExplicit([$i + 1, 1], $kolon['baslik'], DataType::TYPE_STRING);
        }

        $satir = 2;

        foreach ($sorgu->with(array_values(self::ILISKILER))->lazy(1000) as $kayit) {
            foreach ($kolonlar as $i => $kolon) {
                $this->hucreYaz($sayfa, $i + 1, $satir, $kolon, $kayit);
            }
            $satir++;
        }

        $sonSatir = max(2, $satir - 1);

        foreach ($kolonlar as $i => $kolon) {
            $bicim = match ($kolon['tur']) {
                'tarih' => 'dd.mm.yyyy',
                'sayi' => '#,##0.'.str_repeat('0', $kolon['basamak']),
                default => null,
            };
            $this->kolonuBicimle($sayfa, $i + 1, $sonSatir, $bicim);
        }

        $this->bitir($sayfa, count($kolonlar), $sonSatir);
    }

    /**
     * Özet sekmesi: gruplar + satır sayısı + toplamlar; altında para birimine
     * göre TOPLAM satırları (otomatik filtrenin dışında).
     *
     * @param  array{ad: string, gruplar: list<string>, toplamlar: list<string>, satirlar: list<array<string, int|string|null>>}  $pivot
     */
    private function pivotYaz(Worksheet $sayfa, array $pivot, IcmalIceAktarimi $aktarim): void
    {
        $alanlar = [...$pivot['gruplar'], 'satir_sayisi', ...$pivot['toplamlar']];

        foreach ($alanlar as $i => $alan) {
            $baslik = $alan === 'satir_sayisi' ? __('kesinti.excel.satir_sayisi') : $aktarim->excelBasligi($alan);
            $sayfa->setCellValueExplicit([$i + 1, 1], $baslik, DataType::TYPE_STRING);
        }

        $satir = 2;
        /** @var array<string, array<string, string|int>> $paraBirimleri */
        $paraBirimleri = [];

        foreach ($pivot['satirlar'] as $grup) {
            foreach ($pivot['gruplar'] as $i => $alan) {
                $deger = $grup[$alan] ?? null;

                match ($alan) {
                    'yil' => $sayfa->setCellValueExplicit([$i + 1, $satir], (int) $deger, DataType::TYPE_NUMERIC),
                    'ay' => $this->metin($sayfa, $i + 1, $satir, CarbonImmutable::create(2000, (int) $deger, 1)->locale('tr')->translatedFormat('F')),
                    default => $this->metin($sayfa, $i + 1, $satir, $aktarim->secenekEtiketi($alan, $deger)),
                };
            }

            $kod = (string) ($grup['para_birimi'] ?? '');
            $paraBirimleri[$kod] ??= ['satir_sayisi' => 0, ...array_fill_keys($pivot['toplamlar'], '0.00')];
            $paraBirimleri[$kod]['satir_sayisi'] += (int) $grup['satir_sayisi'];
            $this->sayilariYaz($sayfa, $satir, count($pivot['gruplar']), $pivot['toplamlar'], $grup);

            foreach ($pivot['toplamlar'] as $alan) {
                $paraBirimleri[$kod][$alan] = bcadd((string) $paraBirimleri[$kod][$alan], (string) $grup[$alan], 2);
            }
            $satir++;
        }

        $sonVeriSatiri = max(2, $satir - 1);
        $paraBirimiSutunu = array_search('para_birimi', $pivot['gruplar'], true);
        ksort($paraBirimleri);

        // Tablonun altında para birimine göre toplamlar (ekrandaki toplam satırları)
        foreach ($paraBirimleri as $kod => $toplam) {
            $this->metin($sayfa, 1, $satir, __('kesinti.excel.toplam'));

            if ($paraBirimiSutunu !== false && $paraBirimiSutunu > 0) {
                $this->metin($sayfa, $paraBirimiSutunu + 1, $satir, $kod);
            }

            $this->sayilariYaz($sayfa, $satir, count($pivot['gruplar']), $pivot['toplamlar'], $toplam);
            $sayfa->getStyle([1, $satir, count($alanlar), $satir])->getFont()->setBold(true);
            $satir++;
        }

        $sonSatir = max(2, $satir - 1);
        $this->kolonuBicimle($sayfa, count($pivot['gruplar']) + 1, $sonSatir, '#,##0');

        foreach ($pivot['toplamlar'] as $i => $alan) {
            $this->kolonuBicimle($sayfa, count($pivot['gruplar']) + 2 + $i, $sonSatir, '#,##0.00');
        }

        $this->bitir($sayfa, count($alanlar), $sonVeriSatiri);
    }

    /**
     * Satır sayısı ve toplamlar, grupların hemen sağından başlayarak.
     *
     * @param  list<string>  $toplamlar
     * @param  array<string, int|string|null>  $degerler
     */
    private function sayilariYaz(Worksheet $sayfa, int $satir, int $grupSayisi, array $toplamlar, array $degerler): void
    {
        $sayfa->setCellValueExplicit([$grupSayisi + 1, $satir], (int) $degerler['satir_sayisi'], DataType::TYPE_NUMERIC);

        foreach ($toplamlar as $i => $alan) {
            $sayfa->setCellValueExplicit([$grupSayisi + 2 + $i, $satir], (float) $degerler[$alan], DataType::TYPE_NUMERIC);
        }
    }

    /**
     * @param  array{alan: string, baslik: string, tur: string, basamak: int}  $kolon
     */
    private function hucreYaz(Worksheet $sayfa, int $sutun, int $satir, array $kolon, Model $kayit): void
    {
        $alan = $kolon['alan'];
        $tarih = $kayit->getAttribute('tarih');

        match ($kolon['tur']) {
            'tanim' => $this->metin($sayfa, $sutun, $satir, $kayit->getRelation(self::ILISKILER[$alan])?->getAttribute('kod')),
            'tarih' => $sayfa->setCellValueExplicit([$sutun, $satir], ExcelTarihi::PHPToExcel($tarih->toDateString()), DataType::TYPE_NUMERIC),
            'yil' => $sayfa->setCellValueExplicit([$sutun, $satir], $tarih->year, DataType::TYPE_NUMERIC),
            // Ekrandaki gibi Türkçe ay adı ("Mart")
            'ay' => $this->metin($sayfa, $sutun, $satir, $tarih->locale('tr')->translatedFormat('F')),
            'secenek' => $this->metin($sayfa, $sutun, $satir, $kayit->getAttribute($alan)?->etiket()),
            'sayi' => $sayfa->setCellValueExplicit([$sutun, $satir], (float) $kayit->getAttribute($alan), DataType::TYPE_NUMERIC),
            default => $this->metin($sayfa, $sutun, $satir, $kayit->getAttribute($alan)),
        };
    }

    private function kolonuBicimle(Worksheet $sayfa, int $sutun, int $sonSatir, ?string $bicim): void
    {
        $harf = Coordinate::stringFromColumnIndex($sutun);

        if ($bicim !== null) {
            $sayfa->getStyle("{$harf}2:{$harf}{$sonSatir}")->getNumberFormat()->setFormatCode($bicim);
        }
        // Veri ne kadar genişse kolon o kadar (kullanıcı isteği)
        $sayfa->getColumnDimension($harf)->setAutoSize(true);
    }

    /** Kalın başlık, sabit ilk satır ve veri satırları üzerinde otomatik filtre */
    private function bitir(Worksheet $sayfa, int $kolonSayisi, int $sonVeriSatiri): void
    {
        $sonKolon = Coordinate::stringFromColumnIndex(max(1, $kolonSayisi));
        $sayfa->getStyle("A1:{$sonKolon}1")->getFont()->setBold(true);
        $sayfa->freezePane('A2');
        $sayfa->setAutoFilter("A1:{$sonKolon}{$sonVeriSatiri}");
    }

    private function metin(Worksheet $sayfa, int $sutun, int $satir, mixed $deger): void
    {
        if ($deger !== null && $deger !== '') {
            $sayfa->setCellValueExplicit([$sutun, $satir], (string) $deger, DataType::TYPE_STRING);
        }
    }
}
