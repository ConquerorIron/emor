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
     * ERP'ye işlenmiş gelen faturaların e-Fatura ETTN'leri — alış faturası
     * (TOHOM_FATURA) ya da harcama belgesi (TOHOM_HARCAMA_BELGESI); harf duyarsız karşılaştırılır.
     * ERP'ye ulaşılamazsa istisna fırlatır; boş liste "hiçbiri işlenmedi" demektir.
     *
     * @return list<string>
     */
    public function islenmisGelenEttnler(): array;

    /**
     * ERP'ye işlenmiş gelen belgelerin fatura no + VKN'si (TOHOM_FATURA ve
     * TOHOM_HARCAMA_BELGESI). E-fatura eşleştirilmeden elle girilmiş (ETTN'siz)
     * faturaları yakalamak için; yalnız ikisi birlikte eşleşme sayılır.
     * ERP'ye ulaşılamazsa istisna fırlatır.
     *
     * @return list<array{belge_no: string, vkn: string}>
     */
    public function islenmisGelenBelgeler(): array;

    /**
     * ERP'nin entegratörden çektiği gelen e-faturalar (TOHOM_E_FATURA havuzu),
     * ETTN => ERP'deki bilgileri (boşlar null). Havuzdaki her fatura döner.
     *
     * @return array<string, array{istisna_kodu: string|null, gonderici_etiketi: string|null, alici_etiketi: string|null}>
     */
    public function havuzdakiGelenler(): array;

    /**
     * ERP'nin gönderilen e-fatura listesi (fatura + gider yansıtma). ETTN
     * gider yansıtmada boş olabilir; o zaman belge no + alıcı VKN'si kullanılır.
     * ERP'ye ulaşılamazsa istisna fırlatır.
     *
     * @return list<array{ettn: string|null, belge_no: string, vkn: string}>
     */
    public function gonderilenFaturalar(): array;
}
