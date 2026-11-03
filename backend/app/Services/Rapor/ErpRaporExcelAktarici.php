<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ERP raporlarının Excel (.xlsx) çıktısı, ekranın sekmeleri gibi:
 *
 * - "Data": ekranda süzülen satırlar ve kolonlar (ErpRaporu::KOLONLAR).
 * - Her özet sekmesi (ErpRaporu::PIVOTLAR) ayrı sayfa: gruplar + kayıt
 *   sayısı + tutarlar.
 *
 * Her sayfanın altında raporun toplam satırları (otomatik filtrenin dışında).
 * Tarihler Excel tarihi olarak yazılır (süzülüp sıralanabilir). Metin
 * hücreleri metin tipiyle yazılır: "=", "+", "-", "@" ile başlayan değer
 * formül olarak yorumlanmaz.
 */
final class ErpRaporExcelAktarici
{
    /**
     * @param  list<array<string, int|string|float|null>>  $satirlar  ekranda süzülen satırlar, ekrandaki sırayla
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(ErpRaporu $rapor, array $satirlar): string
    {
        $basliklar = __('rapor.'.$rapor::DIL.'.kolonlar');
        $baslik = __('rapor.'.$rapor::DIL.'.baslik');
        $toplamlar = $rapor->toplamSatirlari($satirlar);
        $kolon = fn (string $alan, string $tur, string $baslik): array => ['alan' => $alan, 'tur' => $tur, 'baslik' => $baslik];
        $tutarKolonlari = [];
        foreach ($rapor::KOLONLAR as $alan => $tur) {
            if ($tur === 'tutar') {
                $tutarKolonlari[] = $kolon($alan, $tur, $basliklar[$alan]);
            }
        }

        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle($baslik);

        // Sekmeli raporda ilk sayfa ekrandaki gibi "Data"; Excel sayfa adı en fazla 31 karakter
        $this->tabloYaz(
            $kitap->getActiveSheet()->setTitle(mb_substr($rapor::PIVOTLAR === [] ? $baslik : __('rapor.pivot.data'), 0, 31)),
            [
                $kolon('sira_no', 'sayi', __('rapor.sira_no')),
                ...array_map(fn (string $alan, string $tur): array => $kolon($alan, $tur, $basliklar[$alan]), array_keys($rapor::KOLONLAR), $rapor::KOLONLAR),
            ],
            array_map(fn (array $satir, int $sira): array => ['sira_no' => $sira + 1, ...$satir], $satirlar, array_keys($satirlar)),
            $toplamlar,
        );

        foreach ($rapor::PIVOTLAR as $pivot => $gruplar) {
            $this->tabloYaz(
                $kitap->createSheet()->setTitle(__("rapor.pivot.{$pivot}")),
                [
                    ...array_map(fn (string $alan): array => match ($alan) {
                        'yil' => $kolon($alan, 'sayi', __('rapor.yil')),
                        'ay' => $kolon($alan, 'ay', __('rapor.ay')),
                        default => $kolon($alan, 'metin', $basliklar[$alan]),
                    }, $gruplar),
                    $kolon('satir_sayisi', 'sayi', __('rapor.kayit_sayisi')),
                    ...$tutarKolonlari,
                ],
                $rapor->pivot($satirlar, $pivot),
                $toplamlar,
            );
        }

        $kitap->setActiveSheetIndex(0);
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
     * Başlık + satırlar; altında toplam satırları (etiket ilk kolonda, değerler
     * kolon adıyla eşleşen hücrelerde).
     *
     * @param  list<array{alan: string, tur: string, baslik: string}>  $kolonlar
     * @param  list<array<string, int|string|float|null>>  $satirlar
     * @param  list<array{etiket: string, degerler: array<string, string|float>}>  $toplamlar
     */
    private function tabloYaz(Worksheet $sayfa, array $kolonlar, array $satirlar, array $toplamlar): void
    {
        foreach ($kolonlar as $i => $kolon) {
            $sayfa->setCellValueExplicit([$i + 1, 1], $kolon['baslik'], DataType::TYPE_STRING);
        }

        $satir = 2;

        foreach ($satirlar as $degerler) {
            foreach ($kolonlar as $i => $kolon) {
                $this->hucreYaz($sayfa, $i + 1, $satir, $kolon['tur'], $degerler[$kolon['alan']] ?? null);
            }
            $satir++;
        }

        $sonVeriSatiri = max(2, $satir - 1);

        foreach ($toplamlar as $toplam) {
            $sayfa->setCellValueExplicit([1, $satir], $toplam['etiket'], DataType::TYPE_STRING);
            foreach (array_slice($kolonlar, 1, preserve_keys: true) as $i => $kolon) {
                $this->hucreYaz($sayfa, $i + 1, $satir, $kolon['tur'], $toplam['degerler'][$kolon['alan']] ?? null);
            }
            $sayfa->getStyle([1, $satir, count($kolonlar), $satir])->getFont()->setBold(true);
            $satir++;
        }

        $sonSatir = max(2, $satir - 1);

        foreach ($kolonlar as $i => $kolon) {
            $harf = Coordinate::stringFromColumnIndex($i + 1);
            $bicim = match ($kolon['tur']) {
                'tarih' => 'dd.mm.yyyy',
                'tutar' => '#,##0.00',
                'sayi' => '0',
                default => null,
            };

            if ($bicim !== null) {
                $sayfa->getStyle("{$harf}2:{$harf}{$sonSatir}")->getNumberFormat()->setFormatCode($bicim);
            }
            // Veri ne kadar genişse kolon o kadar
            $sayfa->getColumnDimension($harf)->setAutoSize(true);
        }

        $sonKolon = Coordinate::stringFromColumnIndex(count($kolonlar));
        $sayfa->getStyle("A1:{$sonKolon}1")->getFont()->setBold(true);
        $sayfa->freezePane('A2');
        $sayfa->setAutoFilter("A1:{$sonKolon}{$sonVeriSatiri}");
    }

    private function hucreYaz(Worksheet $sayfa, int $sutun, int $satir, string $tur, mixed $deger): void
    {
        if ($deger === null || $deger === '') {
            return;
        }

        match ($tur) {
            'tarih' => $sayfa->setCellValueExplicit([$sutun, $satir], ExcelTarihi::PHPToExcel((string) $deger), DataType::TYPE_NUMERIC),
            'tutar', 'sayi' => $sayfa->setCellValueExplicit([$sutun, $satir], $deger, DataType::TYPE_NUMERIC),
            // Ekrandaki gibi Türkçe ay adı ("Mart")
            'ay' => $sayfa->setCellValueExplicit([$sutun, $satir], CarbonImmutable::create(2000, (int) $deger, 1)->locale('tr')->translatedFormat('F'), DataType::TYPE_STRING),
            default => $sayfa->setCellValueExplicit([$sutun, $satir], (string) $deger, DataType::TYPE_STRING),
        };
    }
}
