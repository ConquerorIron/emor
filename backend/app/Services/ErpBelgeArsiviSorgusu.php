<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * TOHOM_E_FATURA'dan tek faturanın PDF_KODU (varbinary) ya da XML_KODU
 * (varchar, sürücü UTF-8'e çevirir) kolonu okunur — yalnız istenen kolon,
 * yalnız o satır (kolonlar ortalama ~55 KB / ~320 KB; 2026-09-24 ölçümü).
 */
final class ErpBelgeArsiviSorgusu implements ErpBelgeArsivi
{
    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    public function pdf(string $ettn): ?string
    {
        return $this->oku('PDF_KODU', $ettn);
    }

    public function xml(string $ettn): ?string
    {
        return $this->oku('XML_KODU', $ettn);
    }

    /**
     * Etiket aranması SQL'de yapılır: istisnası olmayan faturanın XML'i
     * (~320 KB) ağdan hiç gelmez.
     */
    public function istisnaXmlleri(array $ettnler): array
    {
        if ($ettnler === []) {
            return [];
        }

        if (count($ettnler) > 1000) {
            throw new InvalidArgumentException('Tek sorguda en çok 1000 ETTN.');
        }

        /** @var list<object{UUID: string, XML: string|null}> $satirlar */
        $satirlar = $this->mssql->baglan()->select(
            sprintf(
                "SELECT CAST(UUID AS nvarchar(64)) AS UUID,
                        CASE WHEN CHARINDEX('TaxExemptionReasonCode', XML_KODU) > 0 THEN XML_KODU END AS XML
                 FROM TOHOM_E_FATURA
                 WHERE UUID IN (%s) AND DATALENGTH(XML_KODU) > 0",
                implode(',', array_fill(0, count($ettnler), '?')),
            ),
            $ettnler,
        );

        $sonuc = [];
        foreach ($satirlar as $satir) {
            $sonuc[mb_strtolower($satir->UUID)] = $satir->XML;
        }

        return $sonuc;
    }

    /**
     * @param  'PDF_KODU'|'XML_KODU'  $kolon  sabit liste — sorguya kullanıcı girdisi girmez
     */
    private function oku(string $kolon, string $ettn): ?string
    {
        /** @var object{ICERIK: string|null}|null $satir */
        $satir = $this->mssql->baglan()->selectOne(
            "SELECT TOP 1 {$kolon} AS ICERIK FROM TOHOM_E_FATURA WHERE UUID = ? ORDER BY E_FATURA_ID DESC",
            [$ettn],
        );

        $icerik = $satir?->ICERIK;

        return is_string($icerik) && $icerik !== '' ? $icerik : null;
    }
}
