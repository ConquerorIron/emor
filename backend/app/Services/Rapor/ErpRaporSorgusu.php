<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Services\ErpOkumaHatasi;
use App\Services\MssqlBaglantiServisi;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * ERP rapor proc'larını aktif ortamda çalıştırır. Kullanıcı kimliği proc'un
 * güvenlik süzgecidir (-1 → satır yok; canlı ölçüm 2026-11-03). Test
 * ortamında `erp` kullanıcısının bu proc'ları çalıştırma yetkisi yok.
 */
final class ErpRaporSorgusu implements ErpRaporKaynagi
{
    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    public function satirlar(string $proc, int $erpKullaniciId, array $parametreler): array
    {
        try {
            $satirlar = $this->mssql->baglan()->select(
                'EXEC '.$proc.' @KullaniciId = ?, @IlkTarih = ?, @SonTarih = ?, @ProjeKodu = ?, @FirmaAdi = ?',
                [
                    $erpKullaniciId,
                    $parametreler['ilk_tarih'],
                    $parametreler['son_tarih'],
                    $parametreler['proje_kodu'],
                    $parametreler['firma_adi'],
                ],
            );
        } catch (QueryException|PDOException $hata) {
            throw ErpOkumaHatasi::sorgudan($hata);
        }

        return array_map(fn (object $satir): array => (array) $satir, $satirlar);
    }
}
