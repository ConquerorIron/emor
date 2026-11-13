<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

/**
 * Kesinti ekstresinin PDF'i (A3 yatay; kullanıcı isteği 2026-11-13). TCPDF ile
 * doğrudan çizilir — HTML'den PDF (dompdf) binlerce satırda dakikalar ve
 * gigabaytlar sürüyordu; bu yol 10.000 satırı ~4 sn / ~80 MB'ta çizer.
 *
 * İlk sayfa: üst bilgi + tür bazında özet. Her tür bir bölüm: lacivert şerit,
 * kolon başlıkları (sayfa değişince tekrarlanır), satırlar, TOPLAM.
 */
final class KesintiEkstresiPdf
{
    private const KENAR = 10.0;

    /** Kullanılabilir genişlik: A3 yatay 420 mm − kenarlar */
    private const GENISLIK = 400.0;

    /** Bu yüksekliğin altına satır çizilmez (sayfa altlığına yer) */
    private const ALT_SINIR = 282.0;

    private const SATIR = 4.4;

    private const BASLIK_SATIRI = 8.0;

    private const KOYU = [31, 42, 68];

    private const SOLUK = [100, 116, 139];

    private const CIZGI = [203, 213, 225];

    private const ZEMIN = [241, 245, 249];

    private const ZEBRA = [248, 250, 252];

    private EkstreBelgesi $pdf;

    /**
     * @param  array<string, mixed>  $veri  KesintiEkstresi::veri()
     * @return string PDF içeriği
     */
    public function olustur(array $veri): string
    {
        $this->pdf = new EkstreBelgesi('L', 'mm', 'A3', true, 'UTF-8');
        $this->pdf->SetCreator('eMOR ERP');
        $this->pdf->SetTitle(__('kesinti.ekstre.baslik').' — '.$veri['firma']['kod'].' — '.$veri['proje']);
        $this->pdf->setPrintHeader(false);
        $this->pdf->SetMargins(self::KENAR, self::KENAR, self::KENAR);
        $this->pdf->SetAutoPageBreak(false);
        $this->pdf->altBilgi = __('kesinti.ekstre.alt_bilgi', [
            'firma' => $veri['firma']['kod'],
            'proje' => $veri['proje'],
            'tarih' => $veri['tarih'],
        ]);
        $this->pdf->sayfaKalibi = __('kesinti.ekstre.sayfa');
        $this->pdf->SetDrawColor(...self::CIZGI);
        $this->pdf->SetLineWidth(0.1);

        $this->pdf->AddPage();
        $this->ustBilgi($veri);

        if ($veri['bolumler'] === []) {
            $this->yazi(9, '', self::SOLUK);
            $this->pdf->Cell(self::GENISLIK, 8, __('kesinti.ekstre.kayit_yok'), 0, 1, 'L');
        } else {
            $this->ozet($veri);
            foreach ($veri['bolumler'] as $bolum) {
                $this->bolum($veri, $bolum);
            }
        }

        return $this->pdf->Output('', 'S');
    }

    /**
     * @param  array<string, mixed>  $veri
     */
    private function ustBilgi(array $veri): void
    {
        $pdf = $this->pdf;
        $this->yazi(7, '', self::SOLUK);
        $pdf->Cell(self::GENISLIK / 2, 4, __('kesinti.ekstre.sirket'), 0, 0, 'L');
        $pdf->Cell(self::GENISLIK / 2, 4, __('kesinti.ekstre.duzenlenme', ['tarih' => $veri['duzenlenme']]), 0, 1, 'R');

        $this->yazi(16, 'B', self::KOYU);
        $pdf->Cell(self::GENISLIK, 9, __('kesinti.ekstre.baslik'), 0, 1, 'L');

        $firma = $veri['firma'];
        $this->etiketliSatir([
            [__('kesinti.ekstre.taseron'), $firma['ad'] !== '' && $firma['ad'] !== $firma['kod'] ? "{$firma['kod']} — {$firma['ad']}" : $firma['kod']],
            [__('kesinti.ekstre.vkn'), $firma['vergi_kimlik_no'] ?? '-'],
            [__('kesinti.ekstre.vergi_dairesi'), $firma['vergi_dairesi'] ?? '-'],
        ]);
        $this->etiketliSatir([
            [__('kesinti.ekstre.proje'), $veri['proje']],
            [__('kesinti.ekstre.kesinti_turu'), $veri['kesinti_turu'] ?? __('kesinti.ekstre.tum_turler')],
            [__('kesinti.ekstre.tarih'), __('kesinti.ekstre.itibariyla', ['tarih' => $veri['tarih']])],
            [__('kesinti.ekstre.para_birimi'), $veri['para_birimi']],
            [__('kesinti.ekstre.isveren'), $veri['isveren'] ?? '-'],
        ]);

        $this->yazi(6.5, '', self::SOLUK);
        $pdf->Cell(self::GENISLIK, 4, __('kesinti.ekstre.aciklama'), 0, 1, 'L');
        $this->cizgi(1.5);
    }

    /**
     * "Etiket: değer" çiftleri yan yana, tek satır.
     *
     * @param  list<array{0: string, 1: string}>  $ciftler
     */
    private function etiketliSatir(array $ciftler): void
    {
        $pdf = $this->pdf;
        foreach ($ciftler as [$etiket, $deger]) {
            $this->yazi(8, 'B', self::SOLUK);
            $metin = $etiket.': ';
            $pdf->Cell($pdf->GetStringWidth($metin) + 0.5, 5, $metin, 0, 0, 'L');
            $this->yazi(8, 'B', self::KOYU);
            $pdf->Cell($pdf->GetStringWidth($deger) + 8, 5, $deger, 0, 0, 'L');
        }
        $pdf->Ln(5);
    }

    /**
     * Tür bazında özet: kayıt sayısı + 5 tutar; birden çok türde TOPLAM.
     *
     * @param  array<string, mixed>  $veri
     */
    private function ozet(array $veri): void
    {
        $pdf = $this->pdf;
        $genislikler = [60.0, 18.0, 40.0, 40.0, 40.0, 40.0, 40.0];
        $this->yazi(10, 'B', self::KOYU);
        $pdf->Cell(self::GENISLIK, 7, __('kesinti.ekstre.ozet'), 0, 1, 'L');

        $basliklar = [
            __('kesinti.ekstre.kesinti_turu'),
            __('kesinti.ekstre.kayit'),
            ...array_map(fn (string $alan): string => __("kesinti.ekstre.tutar.{$alan}"), KesintiEkstresi::TUTARLAR),
        ];
        $this->yazi(7, 'B', [255, 255, 255]);
        $pdf->SetFillColor(...self::KOYU);
        foreach ($basliklar as $i => $baslik) {
            $pdf->Cell($genislikler[$i], 5.5, $baslik, 0, 0, $i === 0 ? 'L' : 'R', true);
        }
        $pdf->Ln();

        $satir = function (array $hucreler, bool $kalin) use ($pdf, $genislikler): void {
            $this->yazi(7.5, $kalin ? 'B' : '', self::KOYU);
            foreach ($hucreler as $i => $hucre) {
                $pdf->Cell($genislikler[$i], 5, $hucre, 'B', 0, $i === 0 ? 'L' : 'R');
            }
            $pdf->Ln();
        };
        foreach ($veri['bolumler'] as $bolum) {
            $satir([$bolum['kod'], number_format(count($bolum['satirlar']), 0, ',', '.'), ...array_values($bolum['toplam'])], false);
        }
        if (count($veri['bolumler']) > 1) {
            $kayit = array_sum(array_map(fn (array $bolum): int => count($bolum['satirlar']), $veri['bolumler']));
            $satir([__('kesinti.ekstre.toplam'), number_format($kayit, 0, ',', '.'), ...array_values($veri['genel_toplam'])], true);
        }
        $pdf->Ln(4);
    }

    /**
     * @param  array<string, mixed>  $veri
     * @param  array{kod: string, ad: string, kolonlar: list<array{baslik: string, tur: string}>, kolon_alanlari: list<string>, satirlar: list<list<string>>, toplam: array<string, string>}  $bolum
     */
    private function bolum(array $veri, array $bolum): void
    {
        $pdf = $this->pdf;
        $genislikler = $this->genislikler($bolum);
        $hizalar = ['C', ...array_map(fn (array $kolon): string => match ($kolon['tur']) {
            'sayi' => 'R',
            'tarih' => 'C',
            default => 'L',
        }, $bolum['kolonlar'])];

        // Şerit + başlık + birkaç satır sığmıyorsa bölüm yeni sayfada başlar
        if ($pdf->GetY() + 7 + self::BASLIK_SATIRI + 3 * self::SATIR > self::ALT_SINIR) {
            $this->yeniSayfa($veri);
        }
        $this->serit($bolum, false);
        $this->kolonBasliklari($bolum, $genislikler);

        $this->yazi(7, '', self::KOYU);
        foreach ($bolum['satirlar'] as $sira => $hucreler) {
            if ($pdf->GetY() + self::SATIR > self::ALT_SINIR) {
                $this->yeniSayfa($veri);
                $this->serit($bolum, true);
                $this->kolonBasliklari($bolum, $genislikler);
                $this->yazi(7, '', self::KOYU);
            }
            $pdf->SetFillColor(...self::ZEBRA);
            $zebra = $sira % 2 === 1;
            $pdf->Cell($genislikler[0], self::SATIR, (string) ($sira + 1), 'B', 0, 'C', $zebra, '', 1);
            foreach ($hucreler as $i => $hucre) {
                $pdf->Cell($genislikler[$i + 1], self::SATIR, $hucre, 'B', 0, $hizalar[$i + 1], $zebra, '', 1);
            }
            $pdf->Ln();
        }

        if ($pdf->GetY() + 5.5 > self::ALT_SINIR) {
            $this->yeniSayfa($veri);
            $this->serit($bolum, true);
        }
        $this->toplamSatiri($bolum, $genislikler);
        $pdf->Ln(4);
    }

    /**
     * Kolon genişlikleri: başlık (iki satıra kırılabilir), en uzun değer ve
     * (tutar kolonunda) kalın yazılan bölüm toplamı kadar, sonra kullanılabilir
     * genişliğe ölçeklenir. İlk kolon Sıra No.
     *
     * @param  array{kolonlar: list<array{baslik: string, tur: string}>, kolon_alanlari: list<string>, satirlar: list<list<string>>, toplam: array<string, string>}  $bolum
     * @return list<float>
     */
    private function genislikler(array $bolum): array
    {
        $pdf = $this->pdf;
        $dogal = [$pdf->GetStringWidth((string) count($bolum['satirlar']), 'dejavusans', '', 7) + 4];

        foreach ($bolum['kolonlar'] as $i => $kolon) {
            // En uzun (karakter sayısıyla) değer ölçülür: her hücreyi ölçmek gereksiz yavaş
            $enUzun = '';
            foreach ($bolum['satirlar'] as $hucreler) {
                if (mb_strlen($hucreler[$i]) > mb_strlen($enUzun)) {
                    $enUzun = $hucreler[$i];
                }
            }
            $kelimeler = preg_split('/\s+/u', $kolon['baslik']) ?: [$kolon['baslik']];
            $baslik = max(
                $pdf->GetStringWidth($kolon['baslik'], 'dejavusans', 'B', 6.5) / 2,
                ...array_map(fn (string $kelime): float => $pdf->GetStringWidth($kelime, 'dejavusans', 'B', 6.5), $kelimeler),
            );
            $toplam = $bolum['toplam'][$bolum['kolon_alanlari'][$i]] ?? '';
            $dogal[] = max(
                $baslik,
                $pdf->GetStringWidth($enUzun, 'dejavusans', '', 7),
                $pdf->GetStringWidth($toplam, 'dejavusans', 'B', 7),
            ) + 2.5;
        }

        $carpan = self::GENISLIK / array_sum($dogal);

        return array_map(fn (float $genislik): float => $genislik * $carpan, $dogal);
    }

    /**
     * @param  array{kod: string, ad: string, satirlar: list<list<string>>}  $bolum
     */
    private function serit(array $bolum, bool $devam): void
    {
        $pdf = $this->pdf;
        $ad = $bolum['ad'] !== '' && $bolum['ad'] !== $bolum['kod'] ? "{$bolum['kod']} — {$bolum['ad']}" : $bolum['kod'];
        $metin = __('kesinti.ekstre.bolum', ['tur' => $ad, 'adet' => number_format(count($bolum['satirlar']), 0, ',', '.')])
            .($devam ? ' '.__('kesinti.ekstre.devam') : '');
        $this->yazi(8.5, 'B', [255, 255, 255]);
        $pdf->SetFillColor(...self::KOYU);
        $pdf->Cell(self::GENISLIK, 7, ' '.$metin, 0, 1, 'L', true);
    }

    /**
     * @param  array{kolonlar: list<array{baslik: string, tur: string}>}  $bolum
     * @param  list<float>  $genislikler
     */
    private function kolonBasliklari(array $bolum, array $genislikler): void
    {
        $pdf = $this->pdf;
        $this->yazi(6.5, 'B', self::KOYU);
        $pdf->SetFillColor(...self::ZEMIN);
        $basliklar = [__('kesinti.ekstre.sira_no'), ...array_column($bolum['kolonlar'], 'baslik')];
        $x = self::KENAR;
        $y = $pdf->GetY();
        foreach ($basliklar as $i => $baslik) {
            $pdf->MultiCell($genislikler[$i], self::BASLIK_SATIRI, $baslik, 'B', 'C', true, 0, $x, $y, true, 0, false, true, self::BASLIK_SATIRI, 'M', true);
            $x += $genislikler[$i];
        }
        $pdf->SetXY(self::KENAR, $y + self::BASLIK_SATIRI);
    }

    /**
     * "TOPLAM" ilk tutar kolonuna kadar birleşik; tutar kolonlarının altında bölüm toplamları.
     *
     * @param  array{kolon_alanlari: list<string>, toplam: array<string, string>}  $bolum
     * @param  list<float>  $genislikler
     */
    private function toplamSatiri(array $bolum, array $genislikler): void
    {
        $pdf = $this->pdf;
        $alanlar = ['sira_no', ...$bolum['kolon_alanlari']];
        $ilkTutar = min(array_keys(array_intersect($alanlar, KesintiEkstresi::TUTARLAR)));
        $this->yazi(7, 'B', self::KOYU);
        $pdf->SetFillColor(...self::ZEMIN);
        $pdf->Cell(array_sum(array_slice($genislikler, 0, $ilkTutar)), 5.5, __('kesinti.ekstre.toplam'), 'T', 0, 'L', true);
        foreach (array_slice($alanlar, $ilkTutar, preserve_keys: true) as $i => $alan) {
            $pdf->Cell($genislikler[$i], 5.5, $bolum['toplam'][$alan] ?? '', 'T', 0, 'R', true, '', 1);
        }
        $pdf->Ln();
    }

    /**
     * Sonraki sayfa: kısa üst bilgi (ekstre, taşeron, proje, tarih, para birimi).
     *
     * @param  array<string, mixed>  $veri
     */
    private function yeniSayfa(array $veri): void
    {
        $pdf = $this->pdf;
        $pdf->AddPage();
        $this->yazi(8, 'B', self::KOYU);
        $pdf->Cell(self::GENISLIK, 5, implode('  ·  ', [
            __('kesinti.ekstre.baslik'),
            $veri['firma']['kod'],
            $veri['proje'],
            $veri['kesinti_turu'] ?? __('kesinti.ekstre.tum_turler'),
            __('kesinti.ekstre.itibariyla', ['tarih' => $veri['tarih']]),
            $veri['para_birimi'],
        ]), 0, 1, 'L');
        $this->cizgi(1.5);
    }

    private function cizgi(float $bosluk): void
    {
        $pdf = $this->pdf;
        $pdf->Ln(0.5);
        $pdf->SetDrawColor(...self::KOYU);
        $pdf->Line(self::KENAR, $pdf->GetY(), self::KENAR + self::GENISLIK, $pdf->GetY());
        $pdf->SetDrawColor(...self::CIZGI);
        $pdf->Ln($bosluk);
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $renk
     */
    private function yazi(float $boyut, string $stil, array $renk): void
    {
        $this->pdf->SetFont('dejavusans', $stil, $boyut);
        $this->pdf->SetTextColor(...$renk);
    }
}
