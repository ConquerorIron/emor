<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Throwable;

/**
 * Panonun gönderen firma dökümü (EK-09) .xlsx olarak. Metin hücreleri açıkça
 * metin yazılır (unvan formül olarak yorumlanmaz); tutar sayıdır.
 */
final class PanoFirmaExcelAktarici
{
    /**
     * @param  list<array{vkn: string|null, unvan: string|null, para_birimi: string, adet: int, tutar: string}>  $firmalar
     * @return string geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(array $firmalar): string
    {
        $kitap = new Spreadsheet;
        $sayfa = $kitap->getActiveSheet();
        $sayfa->setTitle(__('efatura.pano.firmalar'));

        foreach ([__('efatura.alan.gonderici_vkn'), __('efatura.alan.gonderici_unvan'), __('efatura.alan.para_birimi'), __('efatura.excel.adet'), __('efatura.alan.tutar')] as $i => $baslik) {
            $sayfa->setCellValueExplicit([$i + 1, 1], (string) $baslik, DataType::TYPE_STRING);
        }
        $sayfa->getStyle('A1:E1')->getFont()->setBold(true);

        foreach ($firmalar as $i => $firma) {
            $satir = $i + 2;
            $sayfa->setCellValueExplicit([1, $satir], (string) $firma['vkn'], DataType::TYPE_STRING);
            $sayfa->setCellValueExplicit([2, $satir], (string) $firma['unvan'], DataType::TYPE_STRING);
            $sayfa->setCellValueExplicit([3, $satir], $firma['para_birimi'], DataType::TYPE_STRING);
            $sayfa->setCellValueExplicit([4, $satir], $firma['adet'], DataType::TYPE_NUMERIC);
            $sayfa->setCellValueExplicit([5, $satir], (float) $firma['tutar'], DataType::TYPE_NUMERIC);
        }

        $son = max(2, count($firmalar) + 1);
        $sayfa->getStyle("E2:E{$son}")->getNumberFormat()->setFormatCode('#,##0.00');
        foreach (['A' => 14, 'B' => 50, 'C' => 10, 'D' => 8, 'E' => 18] as $kolon => $genislik) {
            $sayfa->getColumnDimension($kolon)->setWidth($genislik);
        }

        $yol = tempnam(sys_get_temp_dir(), 'efatura-pano-');
        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new RuntimeException('Pano Excel geçici dosyası oluşturulamadı.');
        }

        try {
            (new Xlsx($kitap))->save($yol);
        } catch (Throwable $hata) {
            unlink($yol);

            throw $hata;
        } finally {
            $kitap->disconnectWorksheets();
        }

        return $yol;
    }
}
