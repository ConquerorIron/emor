<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Services\CanliErp;

/**
 * Canlı ERP taşeronları (CanliErp: aktif ortamdan bağımsız). Kaynak, ERP'de
 * bu uygulama için açılmış WEB_ERP_TASERON_LISTESI view'ıdır (kullanıcı
 * kararı 2026-10-30).
 */
final class ErpFirmaSorgusu implements ErpFirmaKaynagi
{
    public function __construct(
        private readonly CanliErp $erp,
    ) {}

    public function firmalar(): array
    {
        /** @var list<object{FIRMA_KODU: string|null, FIRMA_ADI: string|null, FIRMA_GUID: string|null, VERGI_KIMLIK_NO: string|null, VERGI_DAIRESI_ADI: string|null, EPOSTA_ADRESI: string|null, GSM_NO: string|null}> $satirlar */
        $satirlar = $this->erp->oku(
            'SELECT FIRMA_KODU, FIRMA_ADI, CAST(FIRMA_GUID AS nvarchar(64)) AS FIRMA_GUID,
                    VERGI_KIMLIK_NO, VERGI_DAIRESI_ADI, EPOSTA_ADRESI, GSM_NO
             FROM WEB_ERP_TASERON_LISTESI',
        );

        $kirp = fn (?string $deger): ?string => $deger === null ? null : trim($deger);

        return array_map(fn (object $satir): array => [
            'kod' => trim((string) $satir->FIRMA_KODU),
            'ad' => trim((string) $satir->FIRMA_ADI),
            'guid' => trim((string) $satir->FIRMA_GUID),
            'vergi_kimlik_no' => $kirp($satir->VERGI_KIMLIK_NO),
            'vergi_dairesi' => $kirp($satir->VERGI_DAIRESI_ADI),
            'eposta' => $kirp($satir->EPOSTA_ADRESI),
            'gsm_no' => $kirp($satir->GSM_NO),
        ], $satirlar);
    }
}
