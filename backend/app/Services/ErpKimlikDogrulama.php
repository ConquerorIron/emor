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
     * @return array{ad: string, kullanici_adi: string, erp_kullanici_id: int, sistem_yoneticisi: bool}|null
     */
    public function dogrula(string $kullaniciAdi, string $sifre): ?array
    {
        // SISTEM_YONETICISI ana kullanıcı tablosunda; LEFT JOIN — o satır
        // bulunmasa da giriş engellenmez, yalnız yönetici yetkisi kapalı kalır
        /** @var object{KULLANICI_ID: int, UNVAN: string|null, KULLANICI_ADI: string, SIFRE: string|null, SISTEM_YONETICISI: int|bool|null}|null $satir */
        $satir = $this->mssql->baglan()->selectOne(
            'SELECT K.KULLANICI_ID, K.UNVAN, K.KULLANICI_ADI, K.SIFRE, TK.SISTEM_YONETICISI
             FROM VOHOM_ARAMA_KULLANICI K
                  LEFT JOIN TOHOM_KULLANICI TK ON TK.KULLANICI_ID = K.KULLANICI_ID
             WHERE K.KULLANICI_ADI = ?',
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
            'ad' => $this->ad($satir->UNVAN, $satir->KULLANICI_ADI),
            'kullanici_adi' => $satir->KULLANICI_ADI,
            'erp_kullanici_id' => (int) $satir->KULLANICI_ID,
            'sistem_yoneticisi' => (bool) ($satir->SISTEM_YONETICISI ?? false),
        ];
    }

    /**
     * Ad soyad, doğrulamadakiyle aynı kuraldan gelir (UNVAN; boşsa kullanıcı
     * adı — kullanıcı kararı 2026-09-24). SIFRE kolonu seçilmez.
     */
    public function kullanicilar(): array
    {
        /** @var list<object{KULLANICI_ID: int, UNVAN: string|null, KULLANICI_ADI: string, SISTEM_YONETICISI: int|bool|null}> $satirlar */
        $satirlar = $this->mssql->baglan()->select(
            'SELECT K.KULLANICI_ID, K.UNVAN, K.KULLANICI_ADI, TK.SISTEM_YONETICISI
             FROM VOHOM_ARAMA_KULLANICI K
                  LEFT JOIN TOHOM_KULLANICI TK ON TK.KULLANICI_ID = K.KULLANICI_ID
             ORDER BY K.UNVAN',
        );

        return array_map(fn (object $satir): array => [
            'erp_kullanici_id' => (int) $satir->KULLANICI_ID,
            'kullanici_adi' => $satir->KULLANICI_ADI,
            'ad' => $this->ad($satir->UNVAN, $satir->KULLANICI_ADI),
            'sistem_yoneticisi' => (bool) ($satir->SISTEM_YONETICISI ?? false),
        ], $satirlar);
    }

    private function ad(?string $unvan, string $kullaniciAdi): string
    {
        return trim((string) $unvan) !== '' ? trim((string) $unvan) : $kullaniciAdi;
    }
}
