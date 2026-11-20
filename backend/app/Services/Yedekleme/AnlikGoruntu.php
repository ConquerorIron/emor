<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Yedekle AYNI anlık görüntüden okunan bilgiler (README §4.1, §6.1):
 * sonradan canlıdan sayılsaydı arada gelen kayıtlar sağlam yedeği bozuk
 * gösterirdi. `pg_dump --snapshot=<kimlik>` aynı görüntüyle çalışır.
 */
final readonly class AnlikGoruntu
{
    /**
     * @param  array<string, int>  $satirSayilari  Tablo => satır sayısı; verisi alınmayan tablolar 0
     * @param  list<string>  $migrationlar
     * @param  array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null}  $ozellikler
     */
    public function __construct(
        public string $kimlik,
        public array $satirSayilari,
        public array $migrationlar,
        public array $ozellikler,
        public ?string $sahipRol,
        public string $sunucuSurumu,
    ) {}
}
