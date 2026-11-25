<?php

declare(strict_types=1);

namespace App\Services\Entegrator\IceAktarim;

/**
 * GİB'den alınan gelen e-Arşiv fatura listesi (EK-08; docs/efatura §3.2).
 * ETTN yok; fatura no tek başına tekil değil: anahtar tür + kimlik no + fatura
 * no. İptal / itiraz kolonu doluysa kaynakta RED (iptal). Sağdaki tekrar eden
 * formül kolonları (TL tutarlar, `#REF!` veren tevkifat) alınmaz: okuyucu
 * aynı adlı ilk kolonu eşler.
 */
final class EarsivFaturaListesi extends StatikBelgeTuru
{
    public function kolonlar(): array
    {
        return [
            'gonderici_unvan' => ['Ünvanı/Adı Soyadı', 'Unvanı/Adı Soyadı'],
            'gonderici_vkn' => ['Vergi Kimlik/T.C. Kimlik Numarası', 'Vergi Kimlik/T.C. Kimlik No'],
            'belge_no' => ['Fatura No'],
            'belge_tarihi' => ['Düzenleme Tarihi'],
            'brut_tutar' => ['Toplam Tutar'],
            'tutar' => ['Ödenecek Tutar'],
            'vergi_tutari' => ['Vergiler Toplamı'],
            'para_birimi' => ['Para Birimi'],
            'iptal' => ['İptal İtiraz Durum', 'İptal/İtiraz Durumu'],
            'iptal_tarihi' => ['İptal İtiraz Tarihi', 'İptal/İtiraz Tarihi'],
        ];
    }

    public function zorunlular(): array
    {
        return ['gonderici_vkn', 'belge_no', 'belge_tarihi', 'tutar', 'para_birimi'];
    }

    public function belgeTuru(): string
    {
        return 'fatura';
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
            'stopaj_tutari' => null,
            'gib_durum_kodu' => null,
            'gib_durum_aciklamasi' => null,
            ...$this->iptal($degerler),
        ];

        return [$alanlar, $hatalar];
    }
}
