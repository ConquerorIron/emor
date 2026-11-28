<?php

declare(strict_types=1);

namespace App\Services\Entegrator\IceAktarim;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;

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
            // Rapor verileri (2026-11-26) — isteğe bağlı: eski biçimli liste de okunur
            'vergi_haric_tutar' => ['Vergi Hariç Tutar'],
            'indirim_tutari' => ['İskonto Toplamı'],
            'vergi_dahil_tutar' => ['Vergi Dahil Tutar'],
            'kur' => ['Kur'],
            ...$this->kdvKolonlari(),
            'tevkifat_toplami' => ['Tevkifat Toplamı'],
            'tevkifat_kodu' => ['İlk Tevkifat Kodu'],
            'ek_vergiler' => ['Ek Vergiler'],
        ];
    }

    /** Logo'nun KDV oranları; "Diğer" oransızdır */
    private const KDV_ORANLARI = ['0' => '0.0000', '1' => '1.0000', '8' => '8.0000', '10' => '10.0000', '18' => '18.0000', '20' => '20.0000', 'diger' => null];

    /** @return array<string, list<string>> */
    private function kdvKolonlari(): array
    {
        $kolonlar = [];
        foreach (array_keys(self::KDV_ORANLARI) as $oran) {
            $ad = $oran === 'diger' ? 'Diğer' : '%'.$oran;
            $kolonlar["kdv_{$oran}"] = ["KDV {$ad}"];
            $kolonlar["matrah_{$oran}"] = ["KDV {$ad} Matrah"];
        }

        return $kolonlar;
    }

    /**
     * Dosyanın oran bazında KDV, tevkifat ve ek vergi kolonları → vergi
     * kalemleri (UBL'li kaynaklarla aynı şekil). Yalnız sıfırdan farklı
     * olanlar. Bu kolonların hiçbirinde değer yoksa null (kalemlere dokunulmaz):
     * eski biçimli liste ya da başlıksız kısa yapıştırma kalemleri silmesin.
     *
     * @param  array<string, mixed>  $degerler
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     * @return list<array{tur: string, vergi_kodu: string|null, vergi_adi: string|null, oran: string|null, matrah: string|null, tutar: string, istisna_kodu: null, istisna_adi: null}>|null
     */
    private function vergiKalemleri(array $degerler, array &$hatalar): ?array
    {
        $kolonlar = [...array_keys($this->kdvKolonlari()), 'tevkifat_toplami', 'ek_vergiler'];
        $dolu = array_filter(
            array_intersect_key($degerler, array_flip($kolonlar)),
            fn (mixed $deger): bool => $deger !== null && $deger !== '',
        );
        if ($dolu === []) {
            return null;
        }

        $kalem = fn (string $tur, ?string $kod, ?string $ad, ?string $oran, ?string $matrah, ?string $tutar): array => [
            'tur' => $tur, 'vergi_kodu' => $kod, 'vergi_adi' => $ad, 'oran' => $oran,
            'matrah' => $matrah, 'tutar' => $tutar ?? '0.0000', 'istisna_kodu' => null, 'istisna_adi' => null,
        ];
        $sifirDegil = fn (?string $deger): bool => $deger !== null && bccomp($deger, '0', 4) !== 0;

        $kalemler = [];
        foreach (self::KDV_ORANLARI as $anahtar => $oran) {
            $tutar = $this->tutar($degerler, "kdv_{$anahtar}", false, $hatalar);
            $matrah = $this->tutar($degerler, "matrah_{$anahtar}", false, $hatalar);
            if ($sifirDegil($tutar) || $sifirDegil($matrah)) {
                $kalemler[] = $kalem(EFaturaVergiKalemi::TUR_VERGI, '0015', $oran === null ? 'KDV Diğer' : 'KDV', $oran, $matrah, $tutar);
            }
        }

        $ekVergi = $this->tutar($degerler, 'ek_vergiler', false, $hatalar);
        if ($sifirDegil($ekVergi)) {
            $kalemler[] = $kalem(EFaturaVergiKalemi::TUR_VERGI, null, 'Ek Vergiler', null, null, $ekVergi);
        }

        $tevkifat = $this->tutar($degerler, 'tevkifat_toplami', false, $hatalar);
        if ($sifirDegil($tevkifat)) {
            $kod = $this->metin($degerler['tevkifat_kodu'] ?? null);
            $kalemler[] = $kalem(EFaturaVergiKalemi::TUR_TEVKIFAT, $kod !== null ? mb_substr($kod, 0, 16) : null, null, null, null, $tevkifat);
        }

        return $kalemler;
    }

    /**
     * Kur 6 basamakla (kolon decimal(20,6)); sıfır ya da eksi kur yok sayılır.
     *
     * @param  array<string, mixed>  $degerler
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    private function kur(array $degerler, array &$hatalar): ?string
    {
        $kur = $this->kurDegeri($degerler, 'kur', $hatalar);

        return $kur !== null && bccomp($kur, '0', 6) > 0 ? $kur : null;
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
            // Tutarlar belge para biriminde; mal-hizmet toplamı (indirim öncesi) dosyada yok.
            // Bu dört alan boş gelirse mevcut değer korunur (StatikBelgeTuru::BOSKEN_KORUNAN_ALANLAR)
            'vergi_haric_tutar' => $this->tutar($degerler, 'vergi_haric_tutar', false, $hatalar),
            'indirim_tutari' => $this->tutar($degerler, 'indirim_tutari', false, $hatalar),
            'vergi_dahil_tutar' => $this->tutar($degerler, 'vergi_dahil_tutar', false, $hatalar),
            // Kur elle girilmiş (dövizli satırlar); TRY'de ya da boşsa yok
            'kur' => $this->kur($degerler, $hatalar),
        ];

        $kalemler = $this->vergiKalemleri($degerler, $hatalar);
        if ($kalemler !== null) {
            $alanlar[self::VERGI_KALEMLERI] = $kalemler;
        }

        return [$alanlar, $hatalar];
    }
}
