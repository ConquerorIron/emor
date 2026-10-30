<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Services\ErpOkumaHatasi;

/**
 * ERP'deki taşeron firmalar — üretimde canlı ERP'ye giden ErpFirmaSorgusu,
 * testlerde sahte uygulama bağlanır (AppServiceProvider).
 */
interface ErpFirmaKaynagi
{
    /**
     * Ham satırlar, yalnız kırpılmış. Boş, uzun ya da tekrarlanan değerler
     * aktarımda elenir (ErpFirmaAktarimi).
     *
     * @return list<array{kod: string, ad: string, guid: string, vergi_kimlik_no: string|null, vergi_dairesi: string|null, eposta: string|null, gsm_no: string|null}>
     *
     * @throws ErpOkumaHatasi ERP okunamazsa
     */
    public function firmalar(): array;
}
