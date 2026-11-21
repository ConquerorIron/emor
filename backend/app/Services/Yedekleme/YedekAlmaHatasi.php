<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Yedek alınamadı. `kod` makine okunurdur (yedekler.hata_kodu, API sözleşmesi);
 * mesaj sır taşımaz.
 */
final class YedekAlmaHatasi extends RuntimeException
{
    public const KILIT_MESGUL = 'YEDEK_KILIT_MESGUL';

    public const ACIK_GERI_YUKLEME = 'YEDEK_ACIK_GERI_YUKLEME';

    public const DISK_DOLU = 'YEDEK_DISK_DOLU';

    public const PG_DUMP = 'YEDEK_PG_DUMP_HATASI';

    public const DOGRULAMA = 'YEDEK_DOGRULAMA_HATASI';

    public const DOSYA = 'YEDEK_DOSYA_HATASI';

    public const YAPILANDIRMA = 'YEDEK_YAPILANDIRMA_HATASI';

    /** Yedek klasörü depo olarak doğrulanamadı: bağlanmamış disk (YedekDeposu; BY-011-R3) */
    public const DEPO = 'YEDEK_DEPO_ISARETI_YOK';

    public function __construct(public readonly string $kod, string $mesaj)
    {
        parent::__construct($mesaj);
    }
}
