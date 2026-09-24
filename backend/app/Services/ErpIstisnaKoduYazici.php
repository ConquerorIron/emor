<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ERP'nin gelen e-fatura havuzuna (TOHOM_E_FATURA) vergi istisna kodu yazar —
 * üretimde MSSQL'e giden ErpIstisnaKoduYazimi, testlerde sahte uygulama
 * bağlanır (AppServiceProvider).
 */
interface ErpIstisnaKoduYazici
{
    /**
     * ETTN'nin satırındaki kod BOŞSA yazar; yazıldıysa true. Satır yoksa ya da
     * kod doluysa false (dolu koda asla dokunulmaz). Yetki/erişim hatası istisna.
     */
    public function yaz(string $ettn, string $kod): bool;
}
