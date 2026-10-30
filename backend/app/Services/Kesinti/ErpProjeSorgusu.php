<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Services\CanliErp;

/**
 * Canlı ERP projeleri (CanliErp: aktif ortamdan bağımsız). Kaynak, ERP'de bu
 * uygulama için açılmış WEB_ERP_PROJE_LISTESI view'ıdır (`erp` kullanıcısı
 * ham TOHOM_* tablolarını okuyamaz; kullanıcı kararı 2026-10-30). Kod
 * nchar olduğundan sonu boşlukla dolu gelir.
 */
final class ErpProjeSorgusu implements ErpProjeKaynagi
{
    public function __construct(
        private readonly CanliErp $erp,
    ) {}

    public function projeler(): array
    {
        /** @var list<object{PROJE_KODU: string|null, PROJE_ADI: string|null, PROJE_GUID: string|null}> $satirlar */
        $satirlar = $this->erp->oku(
            'SELECT PROJE_KODU, PROJE_ADI, CAST(PROJE_GUID AS nvarchar(64)) AS PROJE_GUID
             FROM WEB_ERP_PROJE_LISTESI',
        );

        return array_map(fn (object $satir): array => [
            'kod' => trim((string) $satir->PROJE_KODU),
            'ad' => trim((string) $satir->PROJE_ADI),
            'guid' => trim((string) $satir->PROJE_GUID),
        ], $satirlar);
    }
}
