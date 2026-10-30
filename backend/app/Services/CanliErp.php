<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SqlBaglanti;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Canlı ERP'den okuma. Kesinti modülünün ERP tanımları (projeler, firmalar)
 * global aktif ortamdan bağımsız olarak HER ZAMAN canlıdan okunur (kullanıcı
 * kararı 2026-10-30): kesinti verisi ortamdan bağımsız tek PostgreSQL'dedir,
 * bağ kurulan GUID'ler canlı ERP'ninkiler olmalıdır.
 */
final class CanliErp
{
    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    /**
     * @return list<object>
     *
     * @throws ErpOkumaHatasi canlı tanımsızsa ya da sorgu çalışmazsa
     */
    public function oku(string $sorgu): array
    {
        $canli = $this->mssql->listele()[SqlBaglanti::ORTAM_CANLI] ?? null;

        if ($canli === null) {
            throw ErpOkumaHatasi::canliTanimsiz();
        }

        try {
            return $this->mssql->baglan($canli)->select($sorgu);
        } catch (QueryException|PDOException $hata) {
            throw ErpOkumaHatasi::sorgudan($hata);
        }
    }
}
