<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Services\ErpOkumaHatasi;
use App\Services\MssqlBaglantiServisi;
use Illuminate\Database\QueryException;
use PDOException;

/**
 * Taşeron sözleşme hakedişleri ERP'nin rapor proc'undan, aktif ortamda okunur
 * (kullanıcı isteği 2026-11-03). Ölçüm (canlı, 2026-11-03): tüm tarihler
 * 638 satır ~9 sn; proje kodu (büyük/küçük harf duyarsız) ve firma adı TAM
 * eşleşir; kullanıcı kimliği güvenlik süzgecidir (-1 → satır yok). Test
 * ortamında `erp` kullanıcısının bu proc'u çalıştırma yetkisi yok.
 */
final class TaseronHakedisSorgusu implements TaseronHakedisKaynagi
{
    private const PROC = 'dbo.DASHBOARD_TASERON_SOZLESME_HAKEDISLERI';

    public function __construct(
        private readonly MssqlBaglantiServisi $mssql,
    ) {}

    public function hakedisler(int $erpKullaniciId, array $parametreler): array
    {
        try {
            $satirlar = $this->mssql->baglan()->select(
                'EXEC '.self::PROC.' @KullaniciId = ?, @IlkTarih = ?, @SonTarih = ?, @ProjeKodu = ?, @FirmaAdi = ?',
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
