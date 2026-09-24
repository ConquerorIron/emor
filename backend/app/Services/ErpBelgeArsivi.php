<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ERP'nin entegratörden çektiği fatura asılları (TOHOM_E_FATURA havuzu).
 * Üretimde MSSQL'e giden ErpBelgeArsiviSorgusu; testlerde sahte bağlanır.
 * Havuzda yalnız GELEN faturalar bulunur.
 */
interface ErpBelgeArsivi
{
    /**
     * Faturanın PDF'i (ham bayt); havuzda yoksa ya da boşsa null.
     * ERP'ye ulaşılamazsa istisna fırlatır.
     */
    public function pdf(string $ettn): ?string;

    /**
     * Faturanın UBL XML'i (UTF-8); havuzda yoksa ya da boşsa null.
     * ERP'ye ulaşılamazsa istisna fırlatır.
     */
    public function xml(string $ettn): ?string;

    /**
     * Havuzda XML'i olan faturalar, küçük harf ETTN => XML; XML'de istisna
     * kodu etiketi (TaxExemptionReasonCode) yoksa değer null (XML taşınmaz).
     * Havuzda olmayan ya da XML'i boş olan ETTN dönmez. En çok 1000 ETTN.
     * ERP'ye ulaşılamazsa istisna fırlatır.
     *
     * @param  list<string>  $ettnler
     * @return array<string, string|null>
     */
    public function istisnaXmlleri(array $ettnler): array;
}
