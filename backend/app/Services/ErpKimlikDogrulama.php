<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ERP kullanıcılarıyla giriş: kullanıcı adı/şifre, aktif ortamın MSSQL'indeki
 * VOHOM_ARAMA_KULLANICI view'ında doğrulanır; başarıda kullanıcı yerel tabloya
 * yansıtılır (LoginRequest::authenticate).
 *
 * View alanları: KULLANICI_ID, KOD, UNVAN, KULLANICI_ADI, SIFRE (düz metin),
 * PARTI_TURU, MOBIL_KULLANICI… — KULLANICI_ADI benzersizdir (keşif 2026-08-05).
 */
final class ErpKimlikDogrulama implements ErpKimlikDogrulayici
{
    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    /** ERP doğrulaması kullanılabilir mi? */
    public function yapilandirildi(): bool
    {
        return $this->mssql->aktif() !== null;
    }

    /**
     * Başarıda ERP kullanıcı bilgisi, eşleşmezse null döner.
     * Aktif ortam seçilmemişse ValidationException fırlar (baglan).
     *
     * @return array{ad: string, kullanici_adi: string, erp_kullanici_id: int}|null
     */
    public function dogrula(string $kullaniciAdi, string $sifre): ?array
    {
        /** @var object{KULLANICI_ID: int, UNVAN: string|null, KULLANICI_ADI: string, SIFRE: string|null}|null $satir */
        $satir = $this->mssql->baglan()->selectOne(
            'SELECT KULLANICI_ID, UNVAN, KULLANICI_ADI, SIFRE FROM VOHOM_ARAMA_KULLANICI WHERE KULLANICI_ADI = ?',
            [$kullaniciAdi],
        );

        if ($satir === null) {
            return null;
        }

        // Şifre karşılaştırması PHP tarafında ve büyük/küçük harfe duyarlı yapılır
        // (SQL Server collation'ı çoğunlukla case-insensitive'dir); hash_equals
        // zamanlama saldırısına karşı sabit süreli karşılaştırır.
        $erpSifre = (string) $satir->SIFRE;
        if ($erpSifre === '' || ! hash_equals($erpSifre, $sifre)) {
            return null;
        }

        return [
            'ad' => trim((string) $satir->UNVAN) !== '' ? trim((string) $satir->UNVAN) : $satir->KULLANICI_ADI,
            'kullanici_adi' => $satir->KULLANICI_ADI,
            'erp_kullanici_id' => (int) $satir->KULLANICI_ID,
        ];
    }
}
