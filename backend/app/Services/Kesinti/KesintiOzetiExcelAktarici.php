<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Support\Metin;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Kesinti Özeti'nin Excel (.xlsx) çıktısı: ekrandaki rapor tek sayfada, kullanıcının
 * örnek Excel'i gibi — üst bilgi, özet kartları, proje ve kesinti türü dağılımı,
 * en yüksek kesintili taşeronlar. Metinler metin tipiyle yazılır (formül olarak
 * yorumlanmaz); sıfır tutar "-" görünür.
 */
final class KesintiOzetiExcelAktarici
{
    private const SON_KOLON = 'G';

    /** Bölüm şeridi ve toplam satırı zemini (lacivert) */
    private const KOYU = '1F2A44';

    /** Etiket ve alt yazı rengi */
    private const SOLUK = '64748B';

    private const TUTAR = '#,##0.00;-#,##0.00;"-"';

    /** Dağılım tablolarının tutar kolonları (B–G): alan => başlık (kesinti.ozet.kolon.*) */
    private const DAGILIM_KOLONLARI = ['toplam' => 'toplam', 'hab' => 'hab', 'taseron' => 'taseron', 'iptal' => 'iptal', 'kesilen' => 'kesilen', 'kalan' => 'kalan'];

    /** Taşeron tablosunun tutar kolonları (B–E): firmanın toplamı = taşeron payı, "Toplam Kesinti" başlığıyla */
    private const TASERON_KOLONLARI = ['taseron' => 'toplam', 'iptal' => 'iptal', 'kesilen' => 'kesilen', 'kalan' => 'kalan'];

    /**
     * @param  array{tarih: string, para_birimi: string, isveren: string|null, projeler: list<string>, firmalar: list<string>, genel: array<string, string>, proje_dagilimi: list<array<string, string>>, tur_dagilimi: list<array<string, string>>, taseronlar: list<array<string, string>>}  $ozet  KesintiOzeti::hesapla()
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(array $ozet): string
    {
        $baslik = $this->baslik($ozet);
        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle($baslik);
        $kitap->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);
        $sayfa = $kitap->getActiveSheet()->setTitle(__('kesinti.ozet.sayfa'));
        $sayfa->setShowGridlines(false);
        $sayfa->getColumnDimension('A')->setWidth(26);
        foreach (['B', 'C', 'D', 'E', 'F', 'G'] as $kolon) {
            $sayfa->getColumnDimension($kolon)->setWidth(17);
        }
        $sayfa->getPageSetup()
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        // Bölümler arasında bir boş satır; bölüm başlığıyla şerit arasında da
        // Üst bilgiden sonra bir boş satır
        $satir = $this->ozetKartlari($sayfa, $ozet, $this->ustBilgi($sayfa, $ozet, $baslik) + 2);

        $this->bolumBasligi($sayfa, $satir, __('kesinti.ozet.dagilim'));
        $satir = $this->dagilimTablosu($sayfa, $satir + 2, __('kesinti.ozet.proje_bazinda'), __('kesinti.ozet.kolon.proje'), $ozet['proje_dagilimi'], $ozet['genel']);
        $satir = $this->dagilimTablosu($sayfa, $satir, __('kesinti.ozet.tur_bazinda'), __('kesinti.ozet.kolon.kesinti_turu'), $ozet['tur_dagilimi'], $ozet['genel']);

        $this->bolumBasligi($sayfa, $satir, __('kesinti.ozet.taseron_durumu'));
        $this->taseronTablosu($sayfa, $satir + 2, $ozet['taseronlar']);

        $sayfa->setSelectedCell('A1');
        $yol = tempnam(sys_get_temp_dir(), 'kesinti-ozeti-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('Kesinti özeti Excel geçici dosyası oluşturulamadı.');
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
     * "ZONE 6 - ZONE 7 – EYLÜL SONU İTİBARIYLA KESİNTİ LİSTESİ"; tarih ay sonu
     * değilse gün ("15.09.2026 İTİBARIYLA") — ekrandaki başlıkla aynı kural.
     *
     * @param  array{tarih: string, projeler: list<string>}  $ozet
     */
    private function baslik(array $ozet): string
    {
        $tarih = CarbonImmutable::parse($ozet['tarih']);
        $donem = $tarih->isLastOfMonth()
            ? __('kesinti.ozet.ay_sonu', ['ay' => Metin::buyukHarf($tarih->locale('tr')->translatedFormat('F'))])
            : $tarih->format('d.m.Y');
        $baslik = __('kesinti.ozet.baslik', ['donem' => $donem]);

        return $ozet['projeler'] === [] ? $baslik : implode(' - ', $ozet['projeler']).' – '.$baslik;
    }

    /**
     * Şirket + tarih, başlık, Bütçe / İşveren / para birimi; firma seçildiyse
     * altında Taşeron satırı. Üst bilginin son satırını döner.
     *
     * @param  array{tarih: string, para_birimi: string, isveren: string|null, projeler: list<string>, firmalar: list<string>}  $ozet
     */
    private function ustBilgi(Worksheet $sayfa, array $ozet, string $baslik): int
    {
        $this->metin($sayfa, 'A1', __('kesinti.ozet.sirket'));
        $this->metin($sayfa, 'G1', CarbonImmutable::parse($ozet['tarih'])->format('d.m.Y'));
        $sayfa->getStyle('A1:G1')->getFont()->setColor($this->renk(self::SOLUK));
        $sayfa->getStyle('G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

        $this->metin($sayfa, 'A2', $baslik);
        $sayfa->mergeCells('A2:G2');
        $sayfa->getStyle('A2')->getFont()->setBold(true)->setSize(14)->setColor($this->renk(self::KOYU));
        $sayfa->getRowDimension(2)->setRowHeight(22);

        $this->metin($sayfa, 'A3', __('kesinti.ozet.butce'));
        $this->metin($sayfa, 'B3', implode(' · ', $ozet['projeler']));
        $sayfa->mergeCells('B3:D3');
        $this->metin($sayfa, 'E3', __('kesinti.ozet.isveren'));
        $this->metin($sayfa, 'F3', $ozet['isveren'] ?? '-');
        $this->metin($sayfa, 'G3', $ozet['para_birimi']);
        $sayfa->getStyle('A3:G3')->getFont()->setBold(true);
        $sayfa->getStyle('A3')->getFont()->setColor($this->renk(self::SOLUK));
        $sayfa->getStyle('E3')->getFont()->setColor($this->renk(self::SOLUK));
        $sayfa->getStyle('E3:G3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $son = 3;

        if ($ozet['firmalar'] !== []) {
            $this->metin($sayfa, 'A4', __('kesinti.ozet.taseron'));
            $this->metin($sayfa, 'B4', implode(' · ', $ozet['firmalar']));
            $sayfa->mergeCells('B4:G4');
            $sayfa->getStyle('A4:G4')->getFont()->setBold(true);
            $sayfa->getStyle('A4')->getFont()->setColor($this->renk(self::SOLUK));
            $son = 4;
        }

        $sayfa->getStyle("A{$son}:G{$son}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->setColor($this->renk(self::KOYU));

        return $son;
    }

    /**
     * 1. Özet: GENEL (toplam, HAB, taşeron payı — toplamın oranı) ve TAŞERON
     * (iptal, kesilen, kalan — taşeron payının oranı) kartları. Sonraki boş satırı döner.
     *
     * @param  array{para_birimi: string, genel: array<string, string>}  $ozet
     */
    private function ozetKartlari(Worksheet $sayfa, array $ozet, int $satir): int
    {
        $this->bolumBasligi($sayfa, $satir, __('kesinti.ozet.ozet'));
        $genel = $ozet['genel'];
        $oran = fn (string $pay, string $payda, string $anahtar): string => $ozet['para_birimi'].' · '.__(
            "kesinti.ozet.oran.{$anahtar}",
            ['oran' => number_format((float) $genel[$payda] == 0.0 ? 0.0 : (float) $genel[$pay] / (float) $genel[$payda] * 100, 1, ',', '.')],
        );

        $gruplar = [
            __('kesinti.ozet.genel') => [
                ['toplam', $ozet['para_birimi'].' · '.__('kesinti.ozet.oran.tamami')],
                ['hab', $oran('hab', 'toplam', 'toplamin')],
                ['taseron', $oran('taseron', 'toplam', 'toplamin')],
            ],
            __('kesinti.ozet.taseron') => [
                ['iptal', $oran('iptal', 'taseron', 'taseron_payinin')],
                ['kesilen', $oran('kesilen', 'taseron', 'taseron_payinin')],
                ['kalan', $oran('kalan', 'taseron', 'taseron_payinin')],
            ],
        ];

        $satir += 2;

        foreach ($gruplar as $grup => $kartlar) {
            $this->metin($sayfa, "A{$satir}", $grup);
            $sayfa->getStyle("A{$satir}")->getFont()->setBold(true)->setColor($this->renk(self::SOLUK));

            foreach ($kartlar as $i => [$alan, $altYazi]) {
                // Kart iki kolon: B:C, D:E, F:G
                [$ilk, $son] = [chr(ord('B') + $i * 2), chr(ord('C') + $i * 2)];
                foreach ([$satir, $satir + 1, $satir + 2] as $no) {
                    $sayfa->mergeCells("{$ilk}{$no}:{$son}{$no}");
                }
                $this->metin($sayfa, "{$ilk}{$satir}", __("kesinti.ozet.kolon.{$alan}"));
                $sayfa->setCellValueExplicit($ilk.($satir + 1), (float) $genel[$alan], DataType::TYPE_NUMERIC);
                $this->metin($sayfa, $ilk.($satir + 2), $altYazi);

                $sayfa->getStyle("{$ilk}{$satir}")->getFont()->setBold(true)->setColor($this->renk(self::SOLUK));
                $sayfa->getStyle($ilk.($satir + 1))->getFont()->setBold(true)->setSize(16)->setColor($this->renk(self::KOYU));
                $sayfa->getStyle($ilk.($satir + 1))->getNumberFormat()->setFormatCode('#,##0');
                $sayfa->getStyle($ilk.($satir + 1))->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
                $sayfa->getStyle($ilk.($satir + 2))->getFont()->setSize(8)->setColor($this->renk(self::SOLUK));
                $sayfa->getStyle("{$ilk}{$satir}:{$ilk}".($satir + 2))->getBorders()->getLeft()->setBorderStyle(Border::BORDER_THIN)->setColor($this->renk('CBD5E1'));
            }

            $sayfa->getRowDimension($satir + 1)->setRowHeight(24);
            $satir += 4;
        }

        return $satir;
    }

    private function bolumBasligi(Worksheet $sayfa, int $satir, string $metin): void
    {
        $this->metin($sayfa, "A{$satir}", $metin);
        $sayfa->getStyle("A{$satir}")->getFont()->setBold(true)->setSize(12)->setColor($this->renk(self::KOYU));
    }

    /**
     * Lacivert şerit + GENEL / HAB / TAŞERON grup başlığı + kolon başlıkları +
     * satırlar + TOPLAM. Sonraki boş satırı döner.
     *
     * @param  list<array<string, string>>  $satirlar
     * @param  array<string, string>  $genel
     */
    private function dagilimTablosu(Worksheet $sayfa, int $satir, string $serit, string $ilkKolon, array $satirlar, array $genel): int
    {
        $this->serit($sayfa, $satir, $serit, self::SON_KOLON);
        $this->metin($sayfa, 'B'.($satir + 1), __('kesinti.ozet.genel'));
        $this->metin($sayfa, 'C'.($satir + 1), __('kesinti.ozet.hab'));
        $this->metin($sayfa, 'D'.($satir + 1), __('kesinti.ozet.taseron'));
        $sayfa->mergeCells('D'.($satir + 1).':G'.($satir + 1));
        $this->grupBasligiBicimi($sayfa, 'B'.($satir + 1).':G'.($satir + 1));

        return $this->tablo($sayfa, $satir + 2, $ilkKolon, self::DAGILIM_KOLONLARI, $satirlar, $genel);
    }

    /**
     * 3.1 En yüksek kesintili taşeronlar: firmanın taşerona yansıtılan
     * kesintileri (toplam = taşeron payı); kısmi liste olduğu için toplam satırı yok.
     *
     * @param  list<array<string, string>>  $satirlar
     */
    private function taseronTablosu(Worksheet $sayfa, int $satir, array $satirlar): void
    {
        $this->serit($sayfa, $satir, __('kesinti.ozet.en_yuksek', ['adet' => KesintiOzeti::TASERON_SAYISI]), 'E');
        $this->metin($sayfa, 'B'.($satir + 1), __('kesinti.ozet.taseron'));
        $sayfa->mergeCells('B'.($satir + 1).':E'.($satir + 1));
        $this->grupBasligiBicimi($sayfa, 'B'.($satir + 1).':E'.($satir + 1));

        $this->tablo($sayfa, $satir + 2, __('kesinti.ozet.kolon.taseron_firma'), self::TASERON_KOLONLARI, $satirlar, null);
    }

    /**
     * Kolon başlıkları + satırlar (+ verildiyse lacivert TOPLAM satırı).
     *
     * @param  array<string, string>  $kolonlar  alan => başlık anahtarı
     * @param  list<array<string, string>>  $satirlar
     * @param  array<string, string>|null  $toplam
     */
    private function tablo(Worksheet $sayfa, int $satir, string $ilkKolon, array $kolonlar, array $satirlar, ?array $toplam): int
    {
        $alanlar = array_keys($kolonlar);
        $son = chr(ord('A') + count($alanlar));
        $this->metin($sayfa, "A{$satir}", $ilkKolon);
        foreach (array_values($kolonlar) as $i => $anahtar) {
            $this->metin($sayfa, chr(ord('B') + $i).$satir, __("kesinti.ozet.kolon.{$anahtar}"));
        }
        $sayfa->getStyle("A{$satir}:{$son}{$satir}")->getFont()->setBold(true)->setSize(8)->setColor($this->renk(self::SOLUK));
        $sayfa->getStyle("B{$satir}:{$son}{$satir}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sayfa->getStyle("A{$satir}:{$son}{$satir}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->setColor($this->renk(self::KOYU));
        $satir++;

        $ilkVeri = $satir;
        foreach ($satirlar as $degerler) {
            $this->metin($sayfa, "A{$satir}", $degerler['kod']);
            $this->tutarlariYaz($sayfa, $satir, $alanlar, $degerler);
            $sayfa->getStyle("A{$satir}")->getFont()->setBold(true);
            $sayfa->getStyle("A{$satir}:{$son}{$satir}")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_HAIR)->setColor($this->renk('CBD5E1'));
            $satir++;
        }

        if ($toplam !== null) {
            $this->metin($sayfa, "A{$satir}", __('kesinti.ozet.toplam'));
            $this->tutarlariYaz($sayfa, $satir, $alanlar, $toplam);
            $sayfa->getStyle("A{$satir}:{$son}{$satir}")->getFont()->setBold(true)->setColor($this->renk('FFFFFF'));
            $this->zemin($sayfa, "A{$satir}:{$son}{$satir}");
            $satir++;
        }

        if ($satir > $ilkVeri) {
            $sayfa->getStyle('B'.$ilkVeri.':'.$son.($satir - 1))->getNumberFormat()->setFormatCode(self::TUTAR);
        }

        return $satir + 1;
    }

    /**
     * @param  list<string>  $alanlar
     * @param  array<string, string>  $degerler
     */
    private function tutarlariYaz(Worksheet $sayfa, int $satir, array $alanlar, array $degerler): void
    {
        foreach ($alanlar as $i => $alan) {
            $sayfa->setCellValueExplicit(chr(ord('B') + $i).$satir, (float) $degerler[$alan], DataType::TYPE_NUMERIC);
        }
    }

    private function serit(Worksheet $sayfa, int $satir, string $metin, string $sonKolon): void
    {
        $this->metin($sayfa, "A{$satir}", $metin);
        $this->zemin($sayfa, "A{$satir}:{$sonKolon}{$satir}");
        $sayfa->getStyle("A{$satir}")->getFont()->setBold(true)->setColor($this->renk('FFFFFF'));
        $sayfa->getRowDimension($satir)->setRowHeight(18);
    }

    private function grupBasligiBicimi(Worksheet $sayfa, string $aralik): void
    {
        $stil = $sayfa->getStyle($aralik);
        $stil->getFont()->setBold(true)->setSize(8)->setColor($this->renk(self::KOYU));
        $stil->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $stil->getBorders()->getOutline()->setBorderStyle(Border::BORDER_THIN)->setColor($this->renk('CBD5E1'));
        $stil->getBorders()->getInside()->setBorderStyle(Border::BORDER_THIN)->setColor($this->renk('CBD5E1'));
    }

    private function zemin(Worksheet $sayfa, string $aralik): void
    {
        $sayfa->getStyle($aralik)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::KOYU);
    }

    private function metin(Worksheet $sayfa, string $hucre, string $metin): void
    {
        $sayfa->setCellValueExplicit($hucre, $metin, DataType::TYPE_STRING);
    }

    private function renk(string $rgb): Color
    {
        return (new Color)->setRGB($rgb);
    }
}
