<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ERP'ye işlenmiş faturaların okunması — üretimde MSSQL'e giden
 * ErpFaturaSorgusu, testlerde sahte uygulama bağlanır (AppServiceProvider).
 */
interface ErpFaturaKaynagi
{
    /**
     * ERP'ye işlenmiş gelen faturaların e-Fatura ETTN'leri (harf duyarsız karşılaştırılır).
     * ERP'ye ulaşılamazsa istisna fırlatır; boş liste "hiçbiri işlenmedi" demektir.
     *
     * @return list<string>
     */
    public function islenmisGelenEttnler(): array;
}
