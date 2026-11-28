<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFaturaVergiKalemi;
use DOMDocument;
use DOMNode;
use DOMXPath;

/**
 * e-Fatura UBL'inden (UBL-TR 1.2) rapor verilerini okur; XML saklanmaz
 * (kullanıcı kararı 2026-11-26). İzibiz ve Protel ortak kullanır.
 *
 * Yalnız belge düzeyi (kök altındaki) toplamlar okunur; satır düzeyindeki
 * TaxTotal'lar belge toplamının parçasıdır. Belge düzeyi `cac:TaxTotal/cbc:TaxAmount`
 * kullanılmaz: tevkifatlı faturada kimi satıcı net KDV'yi, kimi brüt KDV'yi
 * yazıyor (canlı ölçüm 2026-11-26) — vergiler kalem kalem tutulur.
 *
 * Okunamayan belge null döner: boş, XML değil, DOCTYPE'lı (dış varlık taşıyabilir),
 * ETTN'siz ya da bir tutarı geçersiz / kolon sınırını aşan (yarım veri yazılmaz).
 */
final class UblOkuyucu
{
    private const CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    /** numeric(20,4): 16 tam basamak */
    private const TUTAR_TAM_BASAMAK = 16;

    /** numeric(20,6): 14 tam basamak */
    private const KUR_TAM_BASAMAK = 14;

    /** numeric(9,4): 5 tam basamak */
    private const ORAN_TAM_BASAMAK = 5;

    /** Kod kolonları string(16); daha uzun kod veri hatasıdır */
    private const KOD_UZUNLUGU = 16;

    /** izibiz_istisna_kodu string(100) */
    private const ISTISNA_KODLARI_UZUNLUGU = 100;

    public function oku(string $xml): ?UblVerisi
    {
        // Boş metinde loadXML istisna fırlatır (PHP 8 ValueError); sözleşme null
        if (trim($xml) === '') {
            return null;
        }

        $belge = new DOMDocument;
        // Ağ erişimi kapalı, dış varlık yüklenmez (XXE); DOCTYPE'lı belge reddedilir
        if (! @$belge->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS) || $belge->doctype !== null) {
            return null;
        }

        $xpath = new DOMXPath($belge);
        $xpath->registerNamespace('cbc', self::CBC);
        $xpath->registerNamespace('cac', self::CAC);

        // Belgenin kendi ETTN'i kök altındaki cbc:UUID (referans belgelerinki değil)
        $ettn = $this->metin($xpath, '/*/cbc:UUID');
        if ($ettn === null) {
            return null;
        }

        $gecersiz = false;
        $tutar = function (string $yol, ?DOMNode $baglam = null) use ($xpath, &$gecersiz): ?string {
            $deger = $this->ondalik($this->metin($xpath, $yol, $baglam), 4, self::TUTAR_TAM_BASAMAK);
            $gecersiz = $gecersiz || $deger === false;

            return $deger === false ? null : $deger;
        };

        $toplam = '/*/cac:LegalMonetaryTotal/cbc:';
        $satirToplami = $tutar($toplam.'LineExtensionAmount');
        $indirim = $tutar($toplam.'AllowanceTotalAmount');
        $vergiHaric = $tutar($toplam.'TaxExclusiveAmount');
        $vergiDahil = $tutar($toplam.'TaxInclusiveAmount');

        $kalemler = [];
        foreach ([EFaturaVergiKalemi::TUR_VERGI => '/*/cac:TaxTotal/cac:TaxSubtotal', EFaturaVergiKalemi::TUR_TEVKIFAT => '/*/cac:WithholdingTaxTotal/cac:TaxSubtotal'] as $tur => $yol) {
            foreach ($xpath->query($yol) ?: [] as $kalem) {
                $kod = $this->metin($xpath, 'cac:TaxCategory/cac:TaxScheme/cbc:TaxTypeCode', $kalem);
                $istisnaKodu = $this->metin($xpath, 'cac:TaxCategory/cbc:TaxExemptionReasonCode', $kalem);
                $oran = $this->ondalik($this->metin($xpath, 'cbc:Percent', $kalem), 4, self::ORAN_TAM_BASAMAK);
                $kalemTutari = $tutar('cbc:TaxAmount', $kalem);

                if ($oran === false || $kalemTutari === null || mb_strlen((string) $kod) > self::KOD_UZUNLUGU || mb_strlen((string) $istisnaKodu) > self::KOD_UZUNLUGU) {
                    return null;
                }

                $kalemler[] = [
                    'tur' => $tur,
                    'vergi_kodu' => $kod,
                    'vergi_adi' => $this->metin($xpath, 'cac:TaxCategory/cac:TaxScheme/cbc:Name', $kalem),
                    'oran' => $oran,
                    'matrah' => $tutar('cbc:TaxableAmount', $kalem),
                    'tutar' => $kalemTutari,
                    'istisna_kodu' => $istisnaKodu,
                    'istisna_adi' => $this->metin($xpath, 'cac:TaxCategory/cbc:TaxExemptionReason', $kalem),
                ];
            }
        }

        $kur = $this->kur($xpath);
        if ($gecersiz || $kur === false) {
            return null;
        }

        return new UblVerisi(
            ettn: mb_strtolower($ettn),
            satirToplami: $satirToplami,
            indirimTutari: $indirim,
            vergiHaricTutar: $vergiHaric,
            vergiDahilTutar: $vergiDahil,
            kur: $kur,
            saticiVergiDairesi: $this->metin($xpath, '/*/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cac:TaxScheme/cbc:Name'),
            kalemler: $kalemler,
            istisnaKodlari: $this->istisnaKodlari($xpath),
        );
    }

    /**
     * Dövizli faturanın kuru (belge para birimi → TRY). TRY faturada alan ya
     * yoktur ya da `0.000000` gelir: null. Kaynak para birimi belgeninkinden ya
     * da hedef TRY'den farklıysa (ters yönlü kur) null — yanlış kurla çarpılmış
     * TL tutarından boş tutar iyidir; alanlar yoksa kur kabul edilir (C-7).
     * Geçersiz değer false.
     */
    private function kur(DOMXPath $xpath): string|false|null
    {
        $kur = $this->ondalik($this->metin($xpath, '/*/cac:PricingExchangeRate/cbc:CalculationRate'), 6, self::KUR_TAM_BASAMAK);

        if ($kur === false || $kur === null) {
            return $kur;
        }

        $belgeParaBirimi = $this->metin($xpath, '/*/cbc:DocumentCurrencyCode');
        $kaynak = $this->metin($xpath, '/*/cac:PricingExchangeRate/cbc:SourceCurrencyCode');
        $hedef = $this->metin($xpath, '/*/cac:PricingExchangeRate/cbc:TargetCurrencyCode');

        if (($hedef !== null && strtoupper($hedef) !== 'TRY')
            || ($kaynak !== null && $belgeParaBirimi !== null && strtoupper($kaynak) !== strtoupper($belgeParaBirimi))) {
            return null;
        }

        return bccomp($kur, '0', 6) > 0 ? $kur : null;
    }

    /**
     * Belgedeki bütün istisna kodları (satır düzeyindekiler dahil), virgülle.
     * Kolona sığmayan liste son TAM koddan kesilir; kod ortasından bölünmez.
     */
    private function istisnaKodlari(DOMXPath $xpath): ?string
    {
        $kodlar = [];
        foreach ($xpath->query('//cac:TaxCategory/cbc:TaxExemptionReasonCode') ?: [] as $dugum) {
            $kod = trim($dugum->textContent);
            if ($kod !== '') {
                $kodlar[$kod] = true;
            }
        }

        $metin = null;
        foreach (array_keys($kodlar) as $kod) {
            $aday = $metin === null ? (string) $kod : $metin.','.$kod;
            if (mb_strlen($aday) > self::ISTISNA_KODLARI_UZUNLUGU) {
                break;
            }
            $metin = $aday;
        }

        return $metin;
    }

    private function metin(DOMXPath $xpath, string $yol, ?DOMNode $baglam = null): ?string
    {
        $deger = trim((string) $xpath->evaluate("string({$yol})", $baglam));

        return $deger === '' ? null : $deger;
    }

    /**
     * xs:decimal metnini `basamak` kesir basamağına yuvarlar (yarım yukarı,
     * bcmath; float yok). Boş: null. Biçim dışı ya da tam kısmı sınırı aşan: false.
     */
    private function ondalik(?string $deger, int $basamak, int $tamBasamak): string|false|null
    {
        if ($deger === null) {
            return null;
        }

        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/D', $deger, $parca) !== 1 || ($parca[2] === '' && ($parca[3] ?? '') === '')) {
            return false;
        }

        $isaret = $parca[1] === '-' ? '-' : '';
        $mutlak = ($parca[2] === '' ? '0' : $parca[2]).'.'.(($parca[3] ?? '') === '' ? '0' : $parca[3]);
        $yuvarlanmis = bcadd($mutlak, '0.'.str_repeat('0', $basamak).'5', $basamak);

        if (strlen(ltrim(explode('.', $yuvarlanmis)[0], '0')) > $tamBasamak) {
            return false;
        }

        return bccomp($yuvarlanmis, '0', $basamak) === 0 ? bcadd('0', '0', $basamak) : $isaret.$yuvarlanmis;
    }
}
