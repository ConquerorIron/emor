<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Taşeron Hakedişleri raporunun Excel (.xlsx) çıktısı: ekrandaki satırlar ve
 * kolonlar, altında para koduna göre TOPLAM satırları (otomatik filtrenin
 * dışında). Metin hücreleri metin tipiyle yazılır: "=", "+", "-", "@" ile
 * başlayan değer formül olarak yorumlanmaz.
 */
final class TaseronHakedisleriExcelAktarici
{
    /**
     * @param  list<array<string, string|float>>  $satirlar  TaseronHakedisleriRaporu::ozet()
     * @param  list<array<string, string|float>>  $toplamlar  TaseronHakedisleriRaporu::toplamlar()
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(array $satirlar, array $toplamlar): string
    {
        $basliklar = __('rapor.taseron_hakedisleri.kolonlar');
        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle(__('rapor.taseron_hakedisleri.baslik'));
        $sayfa = $kitap->getActiveSheet()->setTitle(__('rapor.taseron_hakedisleri.baslik'));

        foreach (array_values($basliklar) as $i => $baslik) {
            $sayfa->setCellValueExplicit([$i + 1, 1], $baslik, DataType::TYPE_STRING);
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
            $this->satirYaz($sayfa, $satir, ['proje_adi' => '', ...$toplam]);
            $sayfa->getStyle([1, $satir, count($basliklar), $satir])->getFont()->setBold(true);
            $satir++;
        }

        $sonKolon = Coordinate::stringFromColumnIndex(count($basliklar));
        $ilkTutarKolonu = Coordinate::stringFromColumnIndex(4);
        $sayfa->getStyle("{$ilkTutarKolonu}2:{$sonKolon}".max(2, $satir - 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $sayfa->getStyle("A1:{$sonKolon}1")->getFont()->setBold(true);
        $sayfa->freezePane('A2');
        $sayfa->setAutoFilter("A1:{$sonKolon}{$sonVeriSatiri}");

        foreach (range(1, count($basliklar)) as $sutun) {
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
     * Proje adı, para kodu ve tutarlar; Sıra No kolonunun sağından.
     *
     * @param  array<string, string|float>  $degerler
     */
    private function satirYaz(Worksheet $sayfa, int $satir, array $degerler): void
    {
        foreach (['proje_adi', 'para_kodu'] as $i => $alan) {
            if ($degerler[$alan] !== '') {
                $sayfa->setCellValueExplicit([$i + 2, $satir], (string) $degerler[$alan], DataType::TYPE_STRING);
            }
        }

        foreach (array_keys(TaseronHakedisleriRaporu::TUTARLAR) as $i => $alan) {
            $sayfa->setCellValueExplicit([$i + 4, $satir], (float) $degerler[$alan], DataType::TYPE_NUMERIC);
        }
    }
}
