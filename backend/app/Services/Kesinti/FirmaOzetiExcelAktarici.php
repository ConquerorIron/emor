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
 * Firma Özeti'nin Excel (.xlsx) çıktısı: üstte tarih ve para birimi, altında
 * ekrandaki tablo (taşeron, taşeron adı, proje, kesinti türleri, toplam kalan)
 * ve TOPLAM satırı. Başlık satırı süzülebilir; metinler metin tipiyle yazılır
 * (formül olarak yorumlanmaz). Ekrandaki gibi: o türde kesinti yoksa hücre
 * boş, kesinti var ama tamamı kesilmişse 0,00.
 */
final class FirmaOzetiExcelAktarici
{
    private const BASLIK_SATIRI = 3;

    private const TUTAR = '#,##0.00';

    /**
     * @param  array{tarih: string, para_birimi: string, kesinti_turleri: list<array{id: int, kod: string, ad: string}>, satirlar: list<array{taseron: string, taseron_ad: string, proje: string, kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}>, toplam: array{kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}}  $ozet  FirmaOzeti::hesapla()
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(array $ozet): string
    {
        $baslik = __('kesinti.firma_ozeti.baslik', [
            'tarih' => CarbonImmutable::parse($ozet['tarih'])->format('d.m.Y'),
            'para' => $ozet['para_birimi'],
        ]);
        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle($baslik);
        $sayfa = $kitap->getActiveSheet()->setTitle(__('kesinti.firma_ozeti.sayfa'));
        $sayfa->setCellValueExplicit('A1', $baslik, DataType::TYPE_STRING);
        $sayfa->getStyle('A1')->getFont()->setBold(true)->setSize(12);

        $basliklar = [
            __('kesinti.firma_ozeti.kolon.taseron'),
            __('kesinti.firma_ozeti.kolon.taseron_ad'),
            __('kesinti.firma_ozeti.kolon.proje'),
            ...array_column($ozet['kesinti_turleri'], 'kod'),
            __('kesinti.firma_ozeti.kolon.toplam_kalan'),
        ];
        $sonKolon = Coordinate::stringFromColumnIndex(count($basliklar));
        $satir = self::BASLIK_SATIRI;
        foreach ($basliklar as $i => $metin) {
            $sayfa->setCellValueExplicit([$i + 1, $satir], $metin, DataType::TYPE_STRING);
        }
        $sayfa->getStyle("A{$satir}:{$sonKolon}{$satir}")->getFont()->setBold(true);
        $sayfa->getStyle("A{$satir}:{$sonKolon}{$satir}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);

        foreach ($ozet['satirlar'] as $degerler) {
            $satir++;
            $sayfa->setCellValueExplicit([1, $satir], $degerler['taseron'], DataType::TYPE_STRING);
            $sayfa->setCellValueExplicit([2, $satir], $degerler['taseron_ad'], DataType::TYPE_STRING);
            $sayfa->setCellValueExplicit([3, $satir], $degerler['proje'], DataType::TYPE_STRING);
            $this->tutarlariYaz($sayfa, $satir, $degerler);
        }
        $sonVeriSatiri = max(self::BASLIK_SATIRI + 1, $satir);

        $satir++;
        $sayfa->setCellValueExplicit([1, $satir], __('kesinti.firma_ozeti.toplam'), DataType::TYPE_STRING);
        $this->tutarlariYaz($sayfa, $satir, $ozet['toplam']);
        $sayfa->getStyle("A{$satir}:{$sonKolon}{$satir}")->getFont()->setBold(true);
        $sayfa->getStyle("A{$satir}:{$sonKolon}{$satir}")->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);

        $sayfa->getStyle('D'.(self::BASLIK_SATIRI + 1).":{$sonKolon}{$satir}")->getNumberFormat()->setFormatCode(self::TUTAR);
        foreach (range(1, count($basliklar)) as $i) {
            $sayfa->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        // Taşeron ve proje kaydırırken görünür kalır
        $sayfa->freezePane('D'.(self::BASLIK_SATIRI + 1));
        $sayfa->setAutoFilter('A'.self::BASLIK_SATIRI.":{$sonKolon}{$sonVeriSatiri}");
        $sayfa->setSelectedCell('A1');

        $yol = tempnam(sys_get_temp_dir(), 'firma-ozeti-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('Firma özeti Excel geçici dosyası oluşturulamadı.');
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
     * Kesinti türü kalanları (kesintisi olmayan tür boş) + toplam kalan, D kolonundan başlayarak.
     *
     * @param  array{kalanlar: list<string>, kayit_sayilari: list<int>, toplam_kalan: string}  $degerler
     */
    private function tutarlariYaz(Worksheet $sayfa, int $satir, array $degerler): void
    {
        foreach ($degerler['kalanlar'] as $i => $tutar) {
            if ($degerler['kayit_sayilari'][$i] > 0) {
                $sayfa->setCellValueExplicit([4 + $i, $satir], (float) $tutar, DataType::TYPE_NUMERIC);
            }
        }
        $sayfa->setCellValueExplicit([4 + count($degerler['kalanlar']), $satir], (float) $degerler['toplam_kalan'], DataType::TYPE_NUMERIC);
    }
}
