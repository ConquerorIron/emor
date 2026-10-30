<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * ERP'den veri okunamadı. bootstrap/app.php `kod` + çevrilmiş mesajla
 * döndürür; ayrıntı (ör. yetkisi olmayan tablo) önceki istisnada ve logdadır.
 */
final class ErpOkumaHatasi extends RuntimeException
{
    /** Okuma canlı ERP'ye gider ama canlı bağlantı tanımlanmamış */
    public const CANLI_TANIMSIZ = 'ERP_CANLI_TANIMSIZ';

    /** ERP veritabanı kullanıcısının tabloyu okuma (SELECT) yetkisi yok */
    public const YETKI_YOK = 'ERP_OKUMA_YETKISI_YOK';

    /** Bağlantı ya da sorgu hatası */
    public const OKUNAMADI = 'ERP_OKUMA_HATASI';

    /** SQL Server 229: "The SELECT permission was denied on the object …" */
    private const SQLSRV_YETKI_HATASI = 229;

    private function __construct(
        public readonly string $kod,
        public readonly int $httpDurumu,
        ?Throwable $onceki = null,
    ) {
        parent::__construct(__('hata.'.mb_strtolower($kod)), 0, $onceki);
    }

    public static function canliTanimsiz(): self
    {
        return new self(self::CANLI_TANIMSIZ, 422);
    }

    public static function sorgudan(Throwable $hata): self
    {
        $yetkiYok = $hata instanceof QueryException
            && (int) ($hata->errorInfo[1] ?? 0) === self::SQLSRV_YETKI_HATASI;

        return new self($yetkiYok ? self::YETKI_YOK : self::OKUNAMADI, 502, $hata);
    }
}
