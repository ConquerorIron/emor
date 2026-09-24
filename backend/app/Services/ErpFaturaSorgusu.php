<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aktif ERP ortamının TOHOM_FATURA tablosundan işlenmiş gelen faturalar.
 *
 * TIP = 0 alış faturası, IADE_FATURASI_TIPI dolu olanlar iade (kullanıcı
 * tanımı 2026-09-24). E_FATURA_ETTN, İzibiz'deki fatura ETTN'idir; keşifte
 * (canlı hesap) 3.697 ETTN'in 3.696'sı eşleşti. Yalnız tek kolon okunur.
 */
final class ErpFaturaSorgusu implements ErpFaturaKaynagi
{
    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    public function islenmisGelenEttnler(): array
    {
        /** @var list<object{E_FATURA_ETTN: string}> $satirlar */
        $satirlar = $this->mssql->baglan()->select(
            'SELECT DISTINCT E_FATURA_ETTN
             FROM TOHOM_FATURA
             WHERE TIP = 0 AND IADE_FATURASI_TIPI IS NULL AND E_FATURA_ETTN IS NOT NULL',
        );

        return array_map(fn (object $satir): string => $satir->E_FATURA_ETTN, $satirlar);
    }

    /**
     * Yalnız iki kolon okunur (tabloda PDF/XSLT/XML kodları var — SELECT * yok).
     */
    public function gelenIstisnaKodlari(): array
    {
        /** @var list<object{UUID: string, KOD: string}> $satirlar */
        $satirlar = $this->mssql->baglan()->select(
            "SELECT CAST(UUID AS nvarchar(64)) AS UUID, LTRIM(RTRIM(VERGI_ISTISNA_KODU)) AS KOD
             FROM TOHOM_E_FATURA
             WHERE UUID IS NOT NULL AND LTRIM(RTRIM(ISNULL(VERGI_ISTISNA_KODU, ''))) <> ''",
        );

        $kodlar = [];
        foreach ($satirlar as $satir) {
            $kodlar[$satir->UUID] = $satir->KOD;
        }

        return $kodlar;
    }

    /**
     * ERP_GONDERILEN_E_FATURA_LISTESI (parametresiz; tanımı yalnız SELECT —
     * 2026-09-24'te okunarak doğrulandı). nchar alanlar boşlukla dolu gelir.
     */
    public function gonderilenFaturalar(): array
    {
        /** @var list<object{E_FATURA_ETTN: string|null, BELGE_NO: string|null, VERGI_KIMLIK_NO: string|null}> $satirlar */
        $satirlar = $this->mssql->baglan()->select('EXEC ERP_GONDERILEN_E_FATURA_LISTESI');

        return array_map(fn (object $satir): array => [
            'ettn' => ($ettn = trim((string) $satir->E_FATURA_ETTN)) !== '' ? $ettn : null,
            'belge_no' => trim((string) $satir->BELGE_NO),
            'vkn' => trim((string) $satir->VERGI_KIMLIK_NO),
        ], $satirlar);
    }
}
