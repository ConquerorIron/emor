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
     * Kullanıcı adı ERP'de var mı? Lokal kullanıcı açılırken çakışma denetimi
     * için (EFAT-18): giriş önce lokal kullanıcıya bakar, aynı adlı ERP
     * kullanıcısı bir daha giremezdi. ERP'ye ulaşılamıyorsa "yok" DEMEZ,
     * ValidationException fırlatır.
     */
    public function kullaniciVarMi(string $kullaniciAdi): bool;
}
