<?php

declare(strict_types=1);

namespace App\Services;

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
