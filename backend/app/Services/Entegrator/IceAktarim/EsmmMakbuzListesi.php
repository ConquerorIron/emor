<?php

declare(strict_types=1);

namespace App\Services\Entegrator\IceAktarim;

/**
 * GİB'den alınan e-Serbest Meslek Makbuzu listesi (EK-08; docs/efatura §3.3).
 * Kolon adları 2. satırdadır (okuyucu başlığı arar). Tutarlar kendi içinde
 * tutarlı olmayabilir (stopajı düşülmüş / düşülmemiş tahsilat): yeniden
 * hesaplanmaz, dosyadaki (formülse hesaplanmış) değer yazılır.
 */
final class EsmmMakbuzListesi extends StatikBelgeTuru
{
    public function kolonlar(): array
    {
        return [
            'gonderici_vkn' => ['Düzenleyen T.C. / Vergi Kimlik Numarası', 'Düzenleyen T.C. / Vergi Kimlik No'],
            'gonderici_unvan' => ['Düzenleyen Unvanı/Adı Soyadı'],
            'belge_no' => ['Makbuz No'],
            'belge_tarihi' => ['Makbuz Tarihi'],
            'brut_tutar' => ['Brüt Ücret Tutarı'],
            'stopaj_tutari' => ['G.V. Stopaj Tutarı'],
            'vergi_tutari' => ['KDV Tutarı'],
            'tutar' => ['Tahsilat Tutarı'],
            'para_birimi' => ['Para Birimi'],
            'iptal' => ['İptal/itiraz Durumu', 'İptal İtiraz Durum'],
            'iptal_tarihi' => ['İptal/itiraz Tarihi', 'İptal İtiraz Tarihi'],
        ];
    }

    public function zorunlular(): array
    {
        return ['gonderici_vkn', 'belge_no', 'belge_tarihi', 'tutar', 'para_birimi'];
    }

    public function belgeTuru(): string
    {
        return 'makbuz';
    }

    public function donustur(array $degerler, array $sirketVknleri): array
    {
        $hatalar = [];
        $vkn = $this->vkn($degerler, 'gonderici_vkn', true, $hatalar);
        $belgeNo = $this->zorunluMetin($degerler, 'belge_no', 64, $hatalar);

        $alanlar = [
            'belge_anahtari' => $vkn === null || $belgeNo === null ? null : $this->belgeTuru().'|'.$vkn.'|'.$this->anahtarParcasi($belgeNo),
            'belge_turu' => $this->belgeTuru(),
            'ettn' => null,
            'belge_no' => $belgeNo,
            'belge_tarihi' => $this->tarih($degerler, 'belge_tarihi', $hatalar),
            'olusturma_zamani' => null,
            'gonderici_vkn' => $vkn,
            'gonderici_unvan' => $this->secimliMetin($degerler, 'gonderici_unvan', null, $hatalar),
            'alici_vkn' => null,
            'alici_unvan' => null,
            'senaryo' => null,
            'fatura_tipi' => null,
            'para_birimi' => $this->paraBirimi($degerler, 'para_birimi', $hatalar),
            'tutar' => $this->tutar($degerler, 'tutar', true, $hatalar),
            'vergi_tutari' => $this->tutar($degerler, 'vergi_tutari', false, $hatalar),
            'brut_tutar' => $this->tutar($degerler, 'brut_tutar', false, $hatalar),
            'stopaj_tutari' => $this->tutar($degerler, 'stopaj_tutari', false, $hatalar),
            'gib_durum_kodu' => null,
            'gib_durum_aciklamasi' => null,
            ...$this->iptal($degerler),
        ];

        return [$alanlar, $hatalar];
    }
}
