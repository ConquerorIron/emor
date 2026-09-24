<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ERP kimlik doğrulama sözleşmesi — üretimde MSSQL'e giden ErpKimlikDogrulama,
 * testlerde sahte uygulama bağlanır (AppServiceProvider::register).
 */
interface ErpKimlikDogrulayici
{
    /** ERP doğrulaması kullanılabilir mi? (aktif ortam seçili mi) */
    public function yapilandirildi(): bool;

    /**
     * Başarıda ERP kullanıcı bilgisi, eşleşmezse null döner.
     *
     * @return array{ad: string, kullanici_adi: string, erp_kullanici_id: int, sistem_yoneticisi: bool}|null
     */
    public function dogrula(string $kullaniciAdi, string $sifre): ?array;

    /**
     * ERP'deki kullanıcılar (şifre OKUNMAZ) — Kullanıcılar ekranı bunlardan
     * hangilerinin uygulamaya girebileceğini tanımlar. ERP'ye ulaşılamazsa
     * istisna fırlatır; boş liste "ERP'de kullanıcı yok" demektir.
     *
     * @return list<array{erp_kullanici_id: int, kullanici_adi: string, ad: string, sistem_yoneticisi: bool}>
     */
    public function kullanicilar(): array;
}
