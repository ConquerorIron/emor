<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

use App\Services\Yedekleme\AnlikGoruntu;
use App\Services\Yedekleme\KaynakVeritabani;
use Closure;

/**
 * Testlerin anlık görüntüsü (testler SQLite ile çalışır; gerçek görüntü
 * PostgreSQL'de — PostgresKaynakVeritabani). Kaç kez açıldığını sayar.
 */
final class SahteKaynakVeritabani implements KaynakVeritabani
{
    public int $acilma = 0;

    /**
     * @param  array<string, int>  $satirSayilari
     * @param  list<string>  $migrationlar
     * @param  array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null}  $ozellikler  Manifestin veritabanı özellikleri
     */
    public function __construct(
        public array $satirSayilari = ['users' => 3, 'migrations' => 2, 'sessions' => 0],
        public array $migrationlar = ['0001_01_01_000000_create_users_table', '2026_11_18_120043_create_yedekleme_tables'],
        public array $ozellikler = ['kodlama' => 'UTF8', 'siralama' => 'en_US.UTF-8', 'karakter_turu' => 'en_US.UTF-8', 'ayarlar' => [], 'baglanma_yetkileri' => null],
    ) {}

    public function anlikGoruntuyle(Closure $is): mixed
    {
        $this->acilma++;

        return $is(new AnlikGoruntu(
            kimlik: '00000003-0000001B-1',
            satirSayilari: $this->satirSayilari,
            migrationlar: $this->migrationlar,
            ozellikler: $this->ozellikler,
            sahipRol: 'erp',
            sunucuSurumu: '18.6',
        ));
    }
}
