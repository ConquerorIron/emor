<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\SqlBaglanti;
use App\Services\ErpOkumaHatasi;
use App\Services\MssqlBaglantiServisi;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Projeler CANLI ERP'den okunur, global aktif ortamdan değil (kullanıcı
 * kararı 2026-10-30): kesinti verisi ortamdan bağımsız tek PostgreSQL'dedir,
 * bağ kurulan GUID'ler canlı ERP'ninkiler olmalıdır. Sorgu kullanıcıdan.
 */
final class ErpProjeSorgusu implements ErpProjeKaynagi
{
    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    public function projeler(): array
    {
        $canli = $this->mssql->listele()[SqlBaglanti::ORTAM_CANLI] ?? null;

        if ($canli === null) {
            throw ErpOkumaHatasi::canliTanimsiz();
        }

        try {
            /** @var list<object{PROJE_KODU: string|null, PROJE_ADI: string|null, PROJE_GUID: string|null}> $satirlar */
            $satirlar = $this->mssql->baglan($canli)->select(
                'SELECT P.KOD AS PROJE_KODU, P.UNVAN AS PROJE_ADI, CAST(PY.GUID AS nvarchar(64)) AS PROJE_GUID
                 FROM TOHOM_PARTI P
                     INNER JOIN TOHOM_PARTI_YAMASI PY ON PY.PARTI_ID = P.PARTI_ID
                     INNER JOIN TOHOM_PROJEMIZ PRJ ON PRJ.PROJEMIZ_ID = PY.PARTI_YAMASI_ID',
            );
        } catch (QueryException|PDOException $hata) {
            throw ErpOkumaHatasi::sorgudan($hata);
        }

        return array_map(fn (object $satir): array => [
            'kod' => trim((string) $satir->PROJE_KODU),
            'ad' => trim((string) $satir->PROJE_ADI),
            'guid' => trim((string) $satir->PROJE_GUID),
        ], $satirlar);
    }
}
