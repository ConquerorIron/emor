<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use TCPDF;

/**
 * Kesinti ekstresinin PDF belgesi: her sayfanın altında belge bilgisi (solda)
 * ve "Sayfa X / Y" (sağda). Çizim KesintiEkstresiPdf'te.
 */
final class EkstreBelgesi extends TCPDF
{
    /** Sayfa altındaki belge bilgisi (taşeron, proje) */
    public string $altBilgi = '';

    /** "Sayfa :no / :toplam" kalıbı (dil dosyasından) */
    public string $sayfaKalibi = ':no / :toplam';

    public function Footer(): void
    {
        $kenar = $this->getMargins()['left'];
        $this->SetY(-10);
        $this->SetFont('dejavusans', '', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->SetX($kenar);
        $this->Cell(250, 5, $this->altBilgi, 0, 0, 'L');
        $this->SetX($this->getPageWidth() - $kenar - 60);
        $this->Cell(60, 5, strtr($this->sayfaKalibi, [':no' => $this->getAliasNumPage(), ':toplam' => $this->getAliasNbPages()]), 0, 0, 'R');
    }
}
