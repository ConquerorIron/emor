<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Aktif ERP ortamında işlenmiş gelen faturalar: TOHOM_FATURA (alış faturası)
 * ve TOHOM_HARCAMA_BELGESI (harcama belgesi).
 *
 * TOHOM_FATURA: TIP = 0 alış faturası, IADE_FATURASI_TIPI dolu olanlar iade.
 * TOHOM_HARCAMA_BELGESI: TIP = 0 alış, 1 gider yansıtma (satış) — kullanıcı
 * tanımları 2026-09-24. E_FATURA_ETTN, İzibiz'deki fatura ETTN'idir; keşifte
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
            'SELECT E_FATURA_ETTN
             FROM TOHOM_FATURA
             WHERE TIP = 0 AND IADE_FATURASI_TIPI IS NULL AND E_FATURA_ETTN IS NOT NULL
             UNION
             SELECT E_FATURA_ETTN
             FROM TOHOM_HARCAMA_BELGESI
             WHERE TIP = 0 AND E_FATURA_ETTN IS NOT NULL',
        );

        return array_map(fn (object $satir): string => $satir->E_FATURA_ETTN, $satirlar);
    }

    /**
     * Yalnız iki kolon okunur. nchar alanlar boşlukla dolu gelir.
     */
    public function islenmisGelenBelgeler(): array
    {
        /** @var list<object{BELGE_NO: string, VKN: string}> $satirlar */
        $satirlar = $this->mssql->baglan()->select(
            "SELECT LTRIM(RTRIM(FATURA_NO)) AS BELGE_NO, LTRIM(RTRIM(VERGI_KIMLIK_NO)) AS VKN
             FROM TOHOM_FATURA
             WHERE TIP = 0 AND IADE_FATURASI_TIPI IS NULL
               AND E_FATURA_ETTN IS NULL
               AND COALESCE(FATURA_NO, '') <> '' AND COALESCE(VERGI_KIMLIK_NO, '') <> ''
             UNION
             SELECT LTRIM(RTRIM(BELGE_NO)), LTRIM(RTRIM(VERGI_KIMLIK_NO))
             FROM TOHOM_HARCAMA_BELGESI
             WHERE TIP = 0
               AND E_FATURA_ETTN IS NULL
               AND COALESCE(BELGE_NO, '') <> '' AND COALESCE(VERGI_KIMLIK_NO, '') <> ''",
        );

        return array_map(fn (object $satir): array => [
            'belge_no' => (string) $satir->BELGE_NO,
            'vkn' => (string) $satir->VKN,
        ], $satirlar);
    }

    /**
     * Yalnız iki kolon okunur (tabloda PDF/XSLT/XML kodları var — SELECT * yok).
     */
    public function havuzdakiGelenler(): array
    {
        // Posta kutusu kolon adları ERP'de ters görünür ama değerler açık:
        // FIRMAMIZ = faturayı gönderenin GB'si, MUHATAP = bizim PK'mız (2026-09-24 keşfi)
        /** @var list<object{UUID: string, KOD: string|null, GB: string|null, PK: string|null}> $satirlar */
        $satirlar = $this->mssql->baglan()->select(
            'SELECT CAST(UUID AS nvarchar(64)) AS UUID,
                    LTRIM(RTRIM(VERGI_ISTISNA_KODU)) AS KOD,
                    LTRIM(RTRIM(GIB_FIRMAMIZ_POSTA_KUTUSU)) AS GB,
                    LTRIM(RTRIM(GIB_MUHATAP_POSTA_KUTUSU)) AS PK
             FROM TOHOM_E_FATURA
             WHERE UUID IS NOT NULL',
        );

        $bos = fn (?string $deger): ?string => ($deger ?? '') !== '' ? $deger : null;
        $havuz = [];
        foreach ($satirlar as $satir) {
            $havuz[$satir->UUID] = [
                'istisna_kodu' => $bos($satir->KOD),
                'gonderici_etiketi' => $bos($satir->GB),
                'alici_etiketi' => $bos($satir->PK),
            ];
        }

        return $havuz;
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
