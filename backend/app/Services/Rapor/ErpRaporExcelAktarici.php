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
 * ERP raporlarının Excel (.xlsx) çıktısı: ekrandaki satırlar ve kolonlar
 * (ErpRaporu::KOLONLAR), altında raporun toplam satırları (otomatik
 * filtrenin dışında). Tarihler Excel tarihi olarak yazılır (süzülüp
 * sıralanabilir). Metin hücreleri metin tipiyle yazılır: "=", "+", "-", "@"
 * ile başlayan değer formül olarak yorumlanmaz.
 */
final class ErpRaporExcelAktarici
{
    /**
     * @param  list<array<string, int|string|float|null>>  $satirlar  ekranda süzülen satırlar, ekrandaki sırayla
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(ErpRaporu $rapor, array $satirlar): string
    {
        $kolonlar = $rapor::KOLONLAR;
        $basliklar = __('rapor.'.$rapor::DIL.'.kolonlar');
        $baslik = __('rapor.'.$rapor::DIL.'.baslik');
        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle($baslik);
        // Excel sayfa adı en fazla 31 karakter
        $sayfa = $kitap->getActiveSheet()->setTitle(mb_substr($baslik, 0, 31));

        $sayfa->setCellValueExplicit([1, 1], __('rapor.sira_no'), DataType::TYPE_STRING);
        foreach (array_keys($kolonlar) as $i => $alan) {
            $sayfa->setCellValueExplicit([$i + 2, 1], $basliklar[$alan], DataType::TYPE_STRING);
        }

        $satir = 2;

        foreach ($satirlar as $sira => $degerler) {
            $sayfa->setCellValueExplicit([1, $satir], $sira + 1, DataType::TYPE_NUMERIC);
            $this->satirYaz($sayfa, $satir, $kolonlar, $degerler);
            $satir++;
        }

        $sonVeriSatiri = max(2, $satir - 1);

        foreach ($rapor->toplamSatirlari($satirlar) as $toplam) {
            $sayfa->setCellValueExplicit([1, $satir], $toplam['etiket'], DataType::TYPE_STRING);
            $this->satirYaz($sayfa, $satir, $kolonlar, $toplam['degerler']);
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

        $yol = tempnam(sys_get_temp_dir(), 'rapor-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('Rapor Excel geçici dosyası oluşturulamadı.');
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
     * Sıra No kolonunun sağından kolon sırasıyla; satırda olmayan alan
     * (toplam satırında metin / tarih kolonları) boş kalır.
     *
     * @param  array<string, 'metin'|'tarih'|'tutar'>  $kolonlar
     * @param  array<string, int|string|float|null>  $degerler
     */
    private function satirYaz(Worksheet $sayfa, int $satir, array $kolonlar, array $degerler): void
    {
        foreach (array_keys($kolonlar) as $i => $alan) {
            $sutun = $i + 2;
            $deger = $degerler[$alan] ?? null;

            if ($deger === null || $deger === '') {
                continue;
            }

            match ($kolonlar[$alan]) {
                'tarih' => $sayfa->setCellValueExplicit([$sutun, $satir], ExcelTarihi::PHPToExcel((string) $deger), DataType::TYPE_NUMERIC),
                'tutar' => $sayfa->setCellValueExplicit([$sutun, $satir], (float) $deger, DataType::TYPE_NUMERIC),
                default => $sayfa->setCellValueExplicit([$sutun, $satir], (string) $deger, DataType::TYPE_STRING),
            };
        }
    }
}
