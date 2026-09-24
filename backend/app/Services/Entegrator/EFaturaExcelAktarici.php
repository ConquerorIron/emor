<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * e-Fatura listesinin Excel (.xlsx) çıktısı (EFAT-11).
 *
 * - Listeyle AYNI filtre ve sıralama sorgusunu alır; görünen sayfa değil
 *   filtrenin tamamı yazılır. Satır sınırını çağıran denetler.
 * - Metin hücreleri açıkça metin tipiyle yazılır: "=", "+", "-", "@" ile
 *   başlayan unvan/açıklama formül olarak yorumlanmaz (CSV/Excel enjeksiyonu).
 * - Tarihler Excel tarihidir. Güvenle temsil edilen tutarlar sayı, Excel'in
 *   15 anlamlı basamak sınırını aşan tutarlar kuruş kaybını önlemek için metindir.
 */
final class EFaturaExcelAktarici
{
    /**
     * @param  Builder<EFatura>  $sorgu  filtrelenmiş ve sıralanmış sorgu
     * @param  list<array{para_birimi: string, adet: int, tutar: string, vergi_tutari: string}>  $ozet
     * @param  array{baslangic: string, bitis: string}  $aralik
     * @return string oluşturulan geçici dosyanın yolu (çağıran siler)
     */
    public function olustur(Builder $sorgu, FaturaYonu $yon, EntegratorBaglanti $tanim, array $ozet, array $aralik): string
    {
        $kitap = new Spreadsheet;
        $kitap->getProperties()->setCreator('eMOR ERP')->setTitle(__('efatura.excel.baslik_'.$yon->value));

        $sayfa = $kitap->getActiveSheet();
        $sayfa->setTitle(__('efatura.excel.faturalar'));
        $this->faturalariYaz($sayfa, $sorgu, $yon);

        $this->ozetYaz($kitap->createSheet(), $yon, $tanim, $ozet, $aralik);

        $yol = tempnam(sys_get_temp_dir(), 'efatura-');

        if ($yol === false) {
            $kitap->disconnectWorksheets();

            throw new \RuntimeException('e-Fatura Excel geçici dosyası oluşturulamadı.');
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
     * @param  Builder<EFatura>  $sorgu
     */
    private function faturalariYaz(Worksheet $sayfa, Builder $sorgu, FaturaYonu $yon): void
    {
        $karsi = $yon === FaturaYonu::Gelen ? 'gonderici' : 'alici';
        $basliklar = [
            __('efatura.alan.belge_no'),
            __('efatura.alan.belge_tarihi'),
            __('efatura.alan.'.$karsi.'_vkn'),
            __('efatura.alan.'.$karsi.'_unvan'),
            __('efatura.alan.fatura_tipi'),
            __('efatura.alan.senaryo'),
            __('efatura.alan.para_birimi'),
            __('efatura.alan.tutar'),
            __('efatura.alan.vergi_tutari'),
            __('efatura.alan.durum'),
            __('efatura.alan.gib_durum'),
            __('efatura.alan.erp_okundu'),
            __('efatura.alan.ettn'),
            __('efatura.alan.olusturma_zamani'),
        ];

        foreach ($basliklar as $i => $baslik) {
            $sayfa->setCellValueExplicit([$i + 1, 1], (string) $baslik, DataType::TYPE_STRING);
        }

        $sonKolon = Coordinate::stringFromColumnIndex(count($basliklar));
        $sayfa->getStyle("A1:{$sonKolon}1")->getFont()->setBold(true);
        $sayfa->freezePane('A2');

        $saatDilimi = (string) config('entegrator.izibiz.saat_dilimi');
        $satir = 2;

        foreach ($sorgu->cursor() as $f) {
            /** @var EFatura $f */
            $this->metin($sayfa, 1, $satir, $f->belge_no);
            $sayfa->setCellValueExplicit([2, $satir], ExcelTarihi::PHPToExcel($f->belge_tarihi), DataType::TYPE_NUMERIC);
            $this->metin($sayfa, 3, $satir, $f->getAttribute($karsi.'_vkn'));
            $this->metin($sayfa, 4, $satir, $f->getAttribute($karsi.'_unvan'));
            $this->metin($sayfa, 5, $satir, $f->getAttribute('fatura_tipi'));
            $this->metin($sayfa, 6, $satir, $f->getAttribute('senaryo'));
            $this->metin($sayfa, 7, $satir, $f->getAttribute('para_birimi'));
            $this->tutar($sayfa, 8, $satir, $f->tutar);
            $vergi = $f->getAttribute('vergi_tutari');
            if ($vergi !== null) {
                $this->tutar($sayfa, 9, $satir, $vergi);
            }
            $this->metin($sayfa, 10, $satir, $f->getAttribute('durum_aciklamasi') ?? $f->getAttribute('durum'));
            $this->metin($sayfa, 11, $satir, $f->getAttribute('gib_durum_aciklamasi'));
            $this->metin($sayfa, 12, $satir, match ($f->erp_okundu) {
                true => __('efatura.evet'),
                false => __('efatura.hayir'),
                null => '',
            });
            $this->metin($sayfa, 13, $satir, $f->ettn);
            $olusturma = $f->getAttribute('olusturma_zamani');
            if ($olusturma instanceof CarbonImmutable) {
                // Excel saat dilimi bilmez: İstanbul yerel saati yazılır
                $sayfa->setCellValueExplicit(
                    [14, $satir],
                    ExcelTarihi::PHPToExcel($olusturma->setTimezone($saatDilimi)->toDateTimeString()),
                    DataType::TYPE_NUMERIC,
                );
            }
            $satir++;
        }

        $sonSatir = max(2, $satir - 1);
        $sayfa->getStyle("B2:B{$sonSatir}")->getNumberFormat()->setFormatCode('dd.mm.yyyy');
        $sayfa->getStyle("H2:I{$sonSatir}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sayfa->getStyle("N2:N{$sonSatir}")->getNumberFormat()->setFormatCode('dd.mm.yyyy hh:mm');
        $sayfa->setAutoFilter("A1:{$sonKolon}{$sonSatir}");

        foreach (['A' => 20, 'B' => 12, 'C' => 14, 'D' => 45, 'E' => 12, 'F' => 18, 'G' => 8, 'H' => 16, 'I' => 14, 'J' => 22, 'K' => 30, 'L' => 10, 'M' => 38, 'N' => 17] as $kolon => $genislik) {
            $sayfa->getColumnDimension($kolon)->setWidth($genislik);
        }
    }

    /**
     * @param  list<array{para_birimi: string, adet: int, tutar: string, vergi_tutari: string}>  $ozet
     * @param  array{baslangic: string, bitis: string}  $aralik
     */
    private function ozetYaz(Worksheet $sayfa, FaturaYonu $yon, EntegratorBaglanti $tanim, array $ozet, array $aralik): void
    {
        $sayfa->setTitle(__('efatura.excel.ozet'));

        $bilgiler = [
            [__('efatura.excel.liste'), __('efatura.excel.baslik_'.$yon->value)],
            [__('efatura.excel.ortam'), __('efatura.ortam.'.$tanim->ortam)],
            [__('efatura.excel.tarih_araligi'), CarbonImmutable::parse($aralik['baslangic'])->format('d.m.Y').' – '.CarbonImmutable::parse($aralik['bitis'])->format('d.m.Y')],
            [__('efatura.excel.olusturuldu'), CarbonImmutable::now((string) config('entegrator.izibiz.saat_dilimi'))->format('d.m.Y H:i')],
        ];

        $satir = 1;
        foreach ($bilgiler as [$etiket, $deger]) {
            $this->metin($sayfa, 1, $satir, (string) $etiket);
            $this->metin($sayfa, 2, $satir, (string) $deger);
            $sayfa->getStyle("A{$satir}")->getFont()->setBold(true);
            $satir++;
        }

        $satir++;
        foreach ([__('efatura.alan.para_birimi'), __('efatura.excel.adet'), __('efatura.alan.tutar'), __('efatura.alan.vergi_tutari')] as $i => $baslik) {
            $this->metin($sayfa, $i + 1, $satir, (string) $baslik);
        }
        $sayfa->getStyle("A{$satir}:D{$satir}")->getFont()->setBold(true);

        foreach ($ozet as $grup) {
            $satir++;
            $this->metin($sayfa, 1, $satir, $grup['para_birimi']);
            $sayfa->setCellValueExplicit([2, $satir], $grup['adet'], DataType::TYPE_NUMERIC);
            $this->tutar($sayfa, 3, $satir, $grup['tutar']);
            $this->tutar($sayfa, 4, $satir, $grup['vergi_tutari']);
            $sayfa->getStyle("C{$satir}:D{$satir}")->getNumberFormat()->setFormatCode('#,##0.00');
        }

        $sayfa->getColumnDimension('A')->setWidth(18);
        $sayfa->getColumnDimension('B')->setWidth(28);
        $sayfa->getColumnDimension('C')->setWidth(18);
        $sayfa->getColumnDimension('D')->setWidth(18);
    }

    private function metin(Worksheet $sayfa, int $kolon, int $satir, mixed $deger): void
    {
        if ($deger === null || $deger === '') {
            return;
        }

        $sayfa->setCellValueExplicit([$kolon, $satir], (string) $deger, DataType::TYPE_STRING);
    }

    /** Excel'in 15 anlamlı basamak sınırından sonra sayıya çevirme veri kaybettirir. */
    private function tutar(Worksheet $sayfa, int $kolon, int $satir, string $deger): void
    {
        $basamaklar = ltrim(str_replace(['-', '.'], '', $deger), '0');

        if (strlen($basamaklar) > 15) {
            $sayfa->setCellValueExplicit([$kolon, $satir], $deger, DataType::TYPE_STRING);

            return;
        }

        $sayfa->setCellValueExplicit([$kolon, $satir], (float) $deger, DataType::TYPE_NUMERIC);
    }
}
