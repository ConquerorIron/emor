<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Taşeron Hakedişleri raporunun Excel (.xlsx) çıktısı: ekrandaki satırlar ve
 * kolonlar (TaseronHakedisleriRaporu::KOLONLAR), altında para koduna göre
 * TOPLAM satırları (otomatik filtrenin dışında). Tarihler Excel tarihi olarak
 * yazılır (süzülüp sıralanabilir). Metin hücreleri metin tipiyle yazılır:
 * "=", "+", "-", "@" ile başlayan değer formül olarak yorumlanmaz.
 */
final class TaseronHakedisleriExcelAktarici
{
    /**
     * @param  list<array<string, int|string|float|null>>  $satirlar  TaseronHakedisleriRaporu::hakedisler()
     * @param  list<array<string, string|float>>  $toplamlar  TaseronHakedisleriRaporu::toplamlar()
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(array $satirlar, array $toplamlar): string
    {
        $basliklar = __('rapor.taseron_hakedisleri.kolonlar');
        $kolonlar = TaseronHakedisleriRaporu::KOLONLAR;
        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle(__('rapor.taseron_hakedisleri.baslik'));
        $sayfa = $kitap->getActiveSheet()->setTitle(__('rapor.taseron_hakedisleri.baslik'));

        $sayfa->setCellValueExplicit([1, 1], $basliklar['sira_no'], DataType::TYPE_STRING);
        foreach (array_keys($kolonlar) as $i => $alan) {
            $sayfa->setCellValueExplicit([$i + 2, 1], $basliklar[$alan], DataType::TYPE_STRING);
        }

        $satir = 2;

        foreach ($satirlar as $sira => $degerler) {
            $sayfa->setCellValueExplicit([1, $satir], $sira + 1, DataType::TYPE_NUMERIC);
            $this->satirYaz($sayfa, $satir, $degerler);
            $satir++;
        }

        $sonVeriSatiri = max(2, $satir - 1);

        foreach ($toplamlar as $toplam) {
            $sayfa->setCellValueExplicit([1, $satir], __('rapor.taseron_hakedisleri.toplam'), DataType::TYPE_STRING);
            $this->satirYaz($sayfa, $satir, $toplam);
            $sayfa->getStyle([1, $satir, count($kolonlar) + 1, $satir])->getFont()->setBold(true);
            $satir++;
        }

        $sonSatir = max(2, $satir - 1);

        foreach (array_values($kolonlar) as $i => $tur) {
            $harf = Coordinate::stringFromColumnIndex($i + 2);
            $bicim = match ($tur) {
                'tarih' => 'dd.mm.yyyy',
                'tutar' => '#,##0.00',
                default => null,
            };

            if ($bicim !== null) {
                $sayfa->getStyle("{$harf}2:{$harf}{$sonSatir}")->getNumberFormat()->setFormatCode($bicim);
            }
        }

        $sonKolon = Coordinate::stringFromColumnIndex(count($kolonlar) + 1);
        $sayfa->getStyle("A1:{$sonKolon}1")->getFont()->setBold(true);
        $sayfa->freezePane('A2');
        $sayfa->setAutoFilter("A1:{$sonKolon}{$sonVeriSatiri}");

        foreach (range(1, count($kolonlar) + 1) as $sutun) {
            // Veri ne kadar genişse kolon o kadar
            $sayfa->getColumnDimension(Coordinate::stringFromColumnIndex($sutun))->setAutoSize(true);
        }

        $yol = tempnam(sys_get_temp_dir(), 'hakedis-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('Taşeron hakedişleri Excel geçici dosyası oluşturulamadı.');
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
     * Sıra No kolonunun sağından KOLONLAR sırasıyla; satırda olmayan alan
     * (toplam satırında metin / tarih kolonları) boş kalır.
     *
     * @param  array<string, int|string|float|null>  $degerler
     */
    private function satirYaz(Worksheet $sayfa, int $satir, array $degerler): void
    {
        foreach (array_keys(TaseronHakedisleriRaporu::KOLONLAR) as $i => $alan) {
            $sutun = $i + 2;
            $deger = $degerler[$alan] ?? null;

            if ($deger === null || $deger === '') {
                continue;
            }

            match (TaseronHakedisleriRaporu::KOLONLAR[$alan]) {
                'tarih' => $sayfa->setCellValueExplicit([$sutun, $satir], ExcelTarihi::PHPToExcel((string) $deger), DataType::TYPE_NUMERIC),
                'tutar' => $sayfa->setCellValueExplicit([$sutun, $satir], (float) $deger, DataType::TYPE_NUMERIC),
                default => $sayfa->setCellValueExplicit([$sutun, $satir], (string) $deger, DataType::TYPE_STRING),
            };
        }
    }
}
