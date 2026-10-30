<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Kesinti icmali listesinin Excel (.xlsx) çıktısı (kullanıcı isteği 2026-10-30):
 * filtredeki TÜM satırlar ve ekranda gizli olanlar dahil TÜM kolonlar, yapıştırma
 * sırasıyla (IcmalIceAktarimi::excelKolonlari). Tanımlar koduyla, seçenekler
 * Excel etiketiyle yazılır — dosya ekrana olduğu gibi geri yüklenebilir.
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
     * @param  list<array{alan: string, baslik: string, tur: string, basamak: int}>  $kolonlar
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(Builder $sorgu, array $kolonlar, string $sayfaAdi): string
    {
        IceAktarimTablosu::bellekSiniriniYukselt();

        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle($sayfaAdi);
        $sayfa = $kitap->getActiveSheet();
        // Excel sayfa adı en fazla 31 karakter
        $sayfa->setTitle(mb_substr($sayfaAdi, 0, 31));

        foreach ($kolonlar as $i => $kolon) {
            $sayfa->setCellValueExplicit([$i + 1, 1], $kolon['baslik'], DataType::TYPE_STRING);
        }

        $satir = 2;

        foreach ($sorgu->with(array_values(self::ILISKILER))->lazy(1000) as $kayit) {
            foreach ($kolonlar as $i => $kolon) {
                $this->yaz($sayfa, $i + 1, $satir, $kolon, $kayit);
            }
            $satir++;
        }

        $sonKolon = Coordinate::stringFromColumnIndex(max(1, count($kolonlar)));
        $sonSatir = max(2, $satir - 1);
        $sayfa->getStyle("A1:{$sonKolon}1")->getFont()->setBold(true);
        $sayfa->freezePane('A2');
        $sayfa->setAutoFilter("A1:{$sonKolon}{$sonSatir}");

        foreach ($kolonlar as $i => $kolon) {
            $harf = Coordinate::stringFromColumnIndex($i + 1);
            $bicim = match ($kolon['tur']) {
                'tarih' => 'dd.mm.yyyy',
                'sayi' => '#,##0.'.str_repeat('0', $kolon['basamak']),
                default => null,
            };

            if ($bicim !== null) {
                $sayfa->getStyle("{$harf}2:{$harf}{$sonSatir}")->getNumberFormat()->setFormatCode($bicim);
            }
            // Veri ne kadar genişse kolon o kadar (kullanıcı isteği)
            $sayfa->getColumnDimension($harf)->setAutoSize(true);
        }

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
     * @param  array{alan: string, baslik: string, tur: string, basamak: int}  $kolon
     */
    private function yaz(Worksheet $sayfa, int $sutun, int $satir, array $kolon, Model $kayit): void
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

    private function metin(Worksheet $sayfa, int $sutun, int $satir, mixed $deger): void
    {
        if ($deger !== null && $deger !== '') {
            $sayfa->setCellValueExplicit([$sutun, $satir], (string) $deger, DataType::TYPE_STRING);
        }
    }
}
