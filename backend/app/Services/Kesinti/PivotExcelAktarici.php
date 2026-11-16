<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Pivot ekranının Excel (.xlsx) çıktısı: ekrandaki üç sekme ayrı
 * sayfa — Özet (taşeron + proje), Firma Özeti (taşeron), Proje Özeti (proje).
 * Her sayfada üstte başlık (tarih, para birimi), altında tablo (kimlik
 * kolonları, kesinti türleri, toplam kalan) ve TOPLAM satırı. Başlık satırı
 * süzülebilir; metinler metin tipiyle yazılır (formül olarak yorumlanmaz).
 * Ekrandaki gibi: o türde kesinti yoksa hücre boş, kesinti var ama tamamı
 * kesilmişse 0,00.
 */
final class PivotExcelAktarici
{
    private const BASLIK_SATIRI = 3;

    private const TUTAR = '#,##0.00';

    /**
     * @param  array{tarih: string, para_birimi: string, kesinti_turleri: list<array{id: int, kod: string, ad: string}>, satirlar: list<array<string, mixed>>, firma_satirlari: list<array<string, mixed>>, proje_satirlari: list<array<string, mixed>>, toplam: array{kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}}  $ozet  Pivot::hesapla()
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(array $ozet): string
    {
        $kolon = fn (string $anahtar): string => (string) __("kesinti.pivot.kolon.{$anahtar}");
        $sayfalar = [
            'ozet' => [
                'kimlikler' => ['taseron' => $kolon('taseron'), 'taseron_ad' => $kolon('taseron_ad'), 'proje' => $kolon('proje')],
                'satirlar' => $ozet['satirlar'],
            ],
            'firma' => [
                'kimlikler' => ['taseron' => $kolon('taseron'), 'taseron_ad' => $kolon('taseron_ad')],
                'satirlar' => $ozet['firma_satirlari'],
            ],
            'proje' => [
                'kimlikler' => ['proje' => $kolon('proje'), 'proje_ad' => $kolon('proje_ad')],
                'satirlar' => $ozet['proje_satirlari'],
            ],
        ];

        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle((string) __('kesinti.pivot.dosya'));
        $kitap->removeSheetByIndex(0);
        foreach ($sayfalar as $anahtar => $tanim) {
            $this->sayfaYaz(
                $kitap->createSheet(),
                (string) __("kesinti.pivot.sayfa.{$anahtar}"),
                $tanim['kimlikler'],
                $tanim['satirlar'],
                $ozet,
            );
        }
        $kitap->setActiveSheetIndex(0);

        $yol = tempnam(sys_get_temp_dir(), 'pivot-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('Pivot Excel geçici dosyası oluşturulamadı.');
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
     * Bir sekmenin sayfası: başlık, kimlik kolonları + kesinti türleri + toplam
     * kalan, TOPLAM satırı; kimlik kolonları kaydırırken görünür kalır.
     *
     * @param  array<string, string>  $kimlikler  satır alanı => kolon başlığı
     * @param  list<array<string, mixed>>  $satirlar
     * @param  array{tarih: string, para_birimi: string, kesinti_turleri: list<array{id: int, kod: string, ad: string}>, toplam: array{kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}}  $ozet
     */
    private function sayfaYaz(Worksheet $sayfa, string $sayfaAdi, array $kimlikler, array $satirlar, array $ozet): void
    {
        $sayfa->setTitle($sayfaAdi);
        $baslik = __('kesinti.pivot.baslik', [
            'sayfa' => $sayfaAdi,
            'tarih' => CarbonImmutable::parse($ozet['tarih'])->format('d.m.Y'),
            'para' => $ozet['para_birimi'],
        ]);
        $sayfa->setCellValueExplicit('A1', $baslik, DataType::TYPE_STRING);
        $sayfa->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $basliklar = [
            ...array_values($kimlikler),
            ...array_column($ozet['kesinti_turleri'], 'kod'),
            __('kesinti.pivot.kolon.toplam_kalan'),
        ];
        $ilkTutar = count($kimlikler) + 1;
        $ilkTutarHarfi = Coordinate::stringFromColumnIndex($ilkTutar);
        $sonKolon = Coordinate::stringFromColumnIndex(count($basliklar));
        $satir = self::BASLIK_SATIRI;
        foreach ($basliklar as $i => $metin) {
            $sayfa->setCellValueExplicit([$i + 1, $satir], $metin, DataType::TYPE_STRING);
        }
        $sayfa->getStyle("A{$satir}:{$sonKolon}{$satir}")->getFont()->setBold(true);
        $sayfa->getStyle("A{$satir}:{$sonKolon}{$satir}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        foreach ($satirlar as $degerler) {
            $satir++;
            foreach (array_keys($kimlikler) as $i => $alan) {
                $sayfa->setCellValueExplicit([$i + 1, $satir], (string) $degerler[$alan], DataType::TYPE_STRING);
            }
            /** @var array{kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string} $degerler */
            $this->tutarlariYaz($sayfa, $satir, $ilkTutar, $degerler);
        }
        $sonVeriSatiri = max(self::BASLIK_SATIRI + 1, $satir);

        $satir++;
        $sayfa->setCellValueExplicit([1, $satir], __('kesinti.pivot.toplam'), DataType::TYPE_STRING);
        $this->tutarlariYaz($sayfa, $satir, $ilkTutar, $ozet['toplam']);
        $sayfa->getStyle("A{$satir}:{$sonKolon}{$satir}")->getFont()->setBold(true);
        $sayfa->getStyle("A{$satir}:{$sonKolon}{$satir}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);

        $sayfa->getStyle($ilkTutarHarfi.(self::BASLIK_SATIRI + 1).":{$sonKolon}{$satir}")->getNumberFormat()->setFormatCode(self::TUTAR);
        foreach (range(1, count($basliklar)) as $i) {
            $sayfa->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $sayfa->freezePane($ilkTutarHarfi.(self::BASLIK_SATIRI + 1));
        $sayfa->setAutoFilter('A'.self::BASLIK_SATIRI.":{$sonKolon}{$sonVeriSatiri}");
        $sayfa->setSelectedCell('A1');
    }

    /**
     * Kesinti türü kalanları (kesintisi olmayan tür boş) + toplam kalan, ilk tutar kolonundan başlayarak.
     *
     * @param  array{kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}  $degerler
     */
    private function tutarlariYaz(Worksheet $sayfa, int $satir, int $ilkKolon, array $degerler): void
    {
        foreach ($degerler['kalanlar'] as $i => $tutar) {
            if ($degerler['kayit_sayilari'][$i] > 0) {
                $sayfa->setCellValueExplicit([$ilkKolon + $i, $satir], (float) $tutar, DataType::TYPE_NUMERIC);
            }
        }
        $sayfa->setCellValueExplicit([$ilkKolon + count($degerler['kalanlar']), $satir], (float) $degerler['toplam_kalan'], DataType::TYPE_NUMERIC);
    }
}
