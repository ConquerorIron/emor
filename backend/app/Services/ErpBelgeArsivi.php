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
}
