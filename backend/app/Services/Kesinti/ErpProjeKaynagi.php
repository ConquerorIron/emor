<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Services\ErpOkumaHatasi;

/**
 * ERP'deki projeler — üretimde canlı ERP'ye giden ErpProjeSorgusu, testlerde
 * sahte uygulama bağlanır (AppServiceProvider).
 */
interface ErpProjeKaynagi
{
    /**
     * Ham satırlar, yalnız kırpılmış. Boş, uzun ya da tekrarlanan değerler
     * aktarımda elenir (ErpProjeAktarimi).
     *
     * @return list<array{kod: string, ad: string, guid: string}>
     *
     * @throws ErpOkumaHatasi ERP okunamazsa
     */
    public function projeler(): array;
}
