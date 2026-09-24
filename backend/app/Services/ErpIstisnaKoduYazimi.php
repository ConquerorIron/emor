<?php

declare(strict_types=1);

namespace App\Services;

/**
 * TOHOM_E_FATURA.VERGI_ISTISNA_KODU (nchar(50), KOD tipi) doğrudan UPDATE ile
 * yazılır — kullanıcı kararı 2026-09-24 (bu alan için ERP proc'u gerekmiyor).
 * Koşul sorgunun içindedir: yalnız kodu boş (trim'li) satır güncellenir, dolu
 * koda eşzamanlı bir yazım olsa bile dokunulmaz.
 *
 * Yetki: `erp` kullanıcısına yalnız bu kolon için UPDATE verilmesi yeterli
 * (GRANT UPDATE (VERGI_ISTISNA_KODU) ON dbo.TOHOM_E_FATURA TO erp).
 */
final class ErpIstisnaKoduYazimi implements ErpIstisnaKoduYazici
{
    private const KOD_UZUNLUGU = 50;

    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    public function yaz(string $ettn, string $kod): bool
    {
        $kod = trim($kod);

        // Alan nchar(50): kesilmiş kod yazılmaz
        if ($kod === '' || mb_strlen($kod) > self::KOD_UZUNLUGU) {
            return false;
        }

        $etkilenen = $this->mssql->baglan()->update(
            "UPDATE TOHOM_E_FATURA
             SET VERGI_ISTISNA_KODU = LTRIM(RTRIM(?))
             WHERE UUID = ? AND LTRIM(RTRIM(ISNULL(VERGI_ISTISNA_KODU, ''))) = ''",
            [$kod, $ettn],
        );

        return $etkilenen > 0;
    }
}
