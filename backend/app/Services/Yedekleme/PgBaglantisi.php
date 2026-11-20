<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * PostgreSQL araçlarının bağlanacağı yer. `kullanici` bağlanan rol (servis
 * hesabında emor_yedek, geçiş döneminde uygulamanın kullanıcısı);
 * `uygulamaRolu` geri yüklenen nesnelerin sahibi olacak rol (README §5.2).
 * Parola bu nesnede yoktur: yalnız parola dosyasında durur (ParolaDosyasi).
 */
final readonly class PgBaglantisi
{
    public function __construct(
        public string $host,
        public int $port,
        public string $veritabani,
        public string $kullanici,
        public string $uygulamaRolu,
    ) {}

    /**
     * @return list<string>
     */
    public function argumanlar(?string $veritabani = null): array
    {
        return [
            '--host='.$this->host,
            '--port='.$this->port,
            '--username='.$this->kullanici,
            '--dbname='.($veritabani ?? $this->veritabani),
            '--no-password',
        ];
    }
}
