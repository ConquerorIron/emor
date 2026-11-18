<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Yedek hedefinin türü (README §4.6). Her tür ayrı bir bağlayıcıdır ve
 * ayrı görevde yazılır (yerel BY-008, FTP/FTPS BY-020, Drive BY-021,
 * SFTP BY-022, NAS/S3 BY-023); bağlayıcısı olmayan tür seçilemez.
 */
enum HedefTuru: string
{
    case Yerel = 'yerel';
    case Ftp = 'ftp';
    case Ftps = 'ftps';
    case Sftp = 'sftp';
    case GoogleDrive = 'google_drive';
    case Nas = 'nas';
    case S3 = 's3';
}
