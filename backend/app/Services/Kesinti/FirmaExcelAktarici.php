<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Firmalar listesinin Excel (.xlsx) çıktısı (kullanıcı isteği 2026-10-30):
 * ekranda aranıp sıralanmış firmaların tamamı (sayfa değil), ekrandaki sırayla,
 * tüm kolonlarla. Arama / sıralama tarayıcıda yapıldığı için sıra, gönderilen
 * firma kimliklerinin sırasıdır.
 *
 * Hücreler metin tipiyle yazılır: VKN / GSM baştaki sıfırını kaybetmez,
 * "=", "+", "-", "@" ile başlayan değer formül olarak yorumlanmaz.
 */
final class FirmaExcelAktarici
{
    /**
     * @param  list<int>  $idler  ekrandaki sırayla
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(array $idler): string
    {
        $basliklar = __('kesinti.firmalar_excel.kolonlar');
        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle(__('kesinti.firmalar_excel.baslik'));
        $sayfa = $kitap->getActiveSheet()->setTitle(__('kesinti.firmalar_excel.baslik'));

        foreach (array_values($basliklar) as $i => $baslik) {
            $sayfa->setCellValueExplicit([$i + 1, 1], $baslik, DataType::TYPE_STRING);
        }

        $firmalar = Firma::query()->whereKey($idler)->get()->keyBy('id');
        $satir = 2;

        foreach ($idler as $id) {
            $firma = $firmalar->get($id);

            if ($firma === null) {
                continue;
            }

            $degerler = [
                $firma->kod,
                $firma->ad,
                $firma->vergi_kimlik_no,
                $firma->vergi_dairesi,
                $firma->eposta,
                $firma->gsm_no,
                __($firma->erp_guid === null ? 'kesinti.firmalar_excel.elle' : 'kesinti.firmalar_excel.erp'),
                $firma->firmamiz ? __('kesinti.firmalar_excel.evet') : null,
                $firma->erp_guid,
            ];

            foreach ($degerler as $i => $deger) {
                if ($deger !== null && $deger !== '') {
                    $sayfa->setCellValueExplicit([$i + 1, $satir], (string) $deger, DataType::TYPE_STRING);
                }
            }
            $satir++;
        }

        $sonKolon = Coordinate::stringFromColumnIndex(count($basliklar));
        $sayfa->getStyle("A1:{$sonKolon}1")->getFont()->setBold(true);
        $sayfa->freezePane('A2');
        $sayfa->setAutoFilter("A1:{$sonKolon}".max(2, $satir - 1));

        foreach (range(1, count($basliklar)) as $sutun) {
            // Veri ne kadar genişse kolon o kadar
            $sayfa->getColumnDimension(Coordinate::stringFromColumnIndex($sutun))->setAutoSize(true);
        }

        $yol = tempnam(sys_get_temp_dir(), 'firmalar-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('Firmalar Excel geçici dosyası oluşturulamadı.');
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
}
