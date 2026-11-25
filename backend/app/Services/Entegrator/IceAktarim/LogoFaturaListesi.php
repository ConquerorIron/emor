<?php

declare(strict_types=1);

namespace App\Services\Entegrator\IceAktarim;

use App\Models\EFatura;

/**
 * Logo portalının gelen e-Fatura listesi (EK-07; docs/efatura §3.1). Anahtar
 * ETTN'dir (küçük harf). "Uygulama Yanıtı = RED" kaynakta RED'dir. "TL …"
 * kolonları formüldür, alınmaz. Alıcı şirketin VKN'si olmalı.
 */
final class LogoFaturaListesi extends StatikBelgeTuru
{
    public function kolonlar(): array
    {
        return [
            'ettn' => ['ETTN'],
            'belge_no' => ['Fatura No'],
            'belge_tarihi' => ['Fatura Tarihi'],
            'olusturma_zamani' => ['Oluşturma Tarihi'],
            'gonderici_vkn' => ['Gönderici VKN'],
            'gonderici_unvan' => ['Gönderici Adı'],
            'alici_vkn' => ['Alıcı VKN'],
            'alici_unvan' => ['Alıcı Adı'],
            'senaryo' => ['Senaryo'],
            'fatura_tipi' => ['Tür'],
            // Ödenecek tutar (Protel `payableAmount` ile aynı anlam)
            'tutar' => ['Toplam Tutar'],
            'vergi_tutari' => ['Vergi Toplamı'],
            'para_birimi' => ['Para Birimi'],
            'durum' => ['Durum'],
            'uygulama_yaniti' => ['Uygulama Yanıtı'],
            'yanit_aciklamasi' => ['Uygulama yanıt açıklaması'],
            'gib_durum_kodu' => ['Zarf yanıt kodu'],
            'gib_durum_aciklamasi' => ['Zarf yanıt açıklaması'],
        ];
    }

    public function zorunlular(): array
    {
        return ['ettn', 'belge_no', 'belge_tarihi', 'gonderici_vkn', 'tutar', 'para_birimi'];
    }

    public function belgeTuru(): ?string
    {
        return null;
    }

    public function donustur(array $degerler, array $sirketVknleri): array
    {
        $hatalar = [];

        $ettn = $this->metin($degerler['ettn'] ?? null);
        if ($ettn === null) {
            $hatalar[] = ['ettn', '', 'zorunlu'];
        } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/i', $ettn) !== 1) {
            $hatalar[] = ['ettn', $ettn, 'ettn_gecersiz'];
            $ettn = null;
        } else {
            $ettn = strtolower($ettn);
        }

        $aliciVkn = $this->vkn($degerler, 'alici_vkn', false, $hatalar);
        if ($aliciVkn !== null && $sirketVknleri !== [] && ! in_array($aliciVkn, $sirketVknleri, true)) {
            $hatalar[] = ['alici_vkn', $aliciVkn, 'alici_sirket_degil'];
        }

        $kod = $this->metin($degerler['gib_durum_kodu'] ?? null);
        $red = mb_strtoupper((string) $this->metin($degerler['uygulama_yaniti'] ?? null)) === EFatura::DURUM_RED;

        $alanlar = [
            'belge_anahtari' => $ettn,
            'belge_turu' => null,
            'ettn' => $ettn,
            'belge_no' => $this->zorunluMetin($degerler, 'belge_no', 64, $hatalar),
            'belge_tarihi' => $this->tarih($degerler, 'belge_tarihi', $hatalar),
            'olusturma_zamani' => $this->zaman($degerler, 'olusturma_zamani', $hatalar),
            'gonderici_vkn' => $this->vkn($degerler, 'gonderici_vkn', true, $hatalar),
            'gonderici_unvan' => $this->secimliMetin($degerler, 'gonderici_unvan', null, $hatalar),
            'alici_vkn' => $aliciVkn,
            'alici_unvan' => $this->secimliMetin($degerler, 'alici_unvan', null, $hatalar),
            'senaryo' => $this->secimliMetin($degerler, 'senaryo', 64, $hatalar),
            'fatura_tipi' => $this->secimliMetin($degerler, 'fatura_tipi', 64, $hatalar),
            'para_birimi' => $this->paraBirimi($degerler, 'para_birimi', $hatalar),
            'tutar' => $this->tutar($degerler, 'tutar', true, $hatalar),
            'vergi_tutari' => $this->tutar($degerler, 'vergi_tutari', false, $hatalar),
            'brut_tutar' => null,
            'stopaj_tutari' => null,
            // RED kolondan; değilse dosyanın durumu ("Alındı")
            'durum' => $red ? EFatura::DURUM_RED : $this->secimliMetin($degerler, 'durum', 64, $hatalar),
            'durum_aciklamasi' => $red ? __('efatura.sap_red') : null,
            'yanit_aciklamasi' => $this->secimliMetin($degerler, 'yanit_aciklamasi', null, $hatalar),
            'gib_durum_kodu' => $kod !== null && preg_match('/^\d{1,9}\z/', $kod) === 1 ? (int) $kod : null,
            'gib_durum_aciklamasi' => $this->secimliMetin($degerler, 'gib_durum_aciklamasi', null, $hatalar),
        ];

        return [$alanlar, $hatalar];
    }
}
