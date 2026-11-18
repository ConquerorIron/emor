<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dosya günlüklerinin klasörü (config/logging.php, BY-003-R6). Yedekleme
 * servisi web'in yazabildiği storage/logs'a yazmaz: servis tanımı LOG_KLASORU
 * verir. Adıyla çağrılan (denetim) ve acil durum kanalı dahil HİÇBİR dosya
 * kanalı ortak klasörde kalmamalı. Laravel'in env() okuması gerçek ortam
 * dizileri üzerinden sınanır.
 */
final class GunlukKlasoruTest extends TestCase
{
    private const DEGISKEN = 'LOG_KLASORU';

    /** @var array{server: mixed, env: mixed, putenv: string|false} */
    private array $onceki;

    protected function setUp(): void
    {
        parent::setUp();

        $this->onceki = ['server' => $_SERVER[self::DEGISKEN] ?? null, 'env' => $_ENV[self::DEGISKEN] ?? null, 'putenv' => getenv(self::DEGISKEN)];
    }

    protected function tearDown(): void
    {
        $this->ortam(null);
        if ($this->onceki['server'] !== null) {
            $_SERVER[self::DEGISKEN] = $this->onceki['server'];
        }
        if ($this->onceki['env'] !== null) {
            $_ENV[self::DEGISKEN] = $this->onceki['env'];
        }
        if ($this->onceki['putenv'] !== false) {
            putenv(self::DEGISKEN.'='.$this->onceki['putenv']);
        }

        parent::tearDown();
    }

    public function test_gunluk_klasoru_verilince_butun_dosya_kanallari_oraya_yazar(): void
    {
        $this->ortam('/var/lib/emor-yedek/gunluk');

        $yollar = $this->dosyaKanallari();

        $this->assertSame([
            'single' => '/var/lib/emor-yedek/gunluk/laravel.log',
            'daily' => '/var/lib/emor-yedek/gunluk/laravel.log',
            'monthly' => '/var/lib/emor-yedek/gunluk/laravel.log',
            'browser' => '/var/lib/emor-yedek/gunluk/browser.log',
            'denetim' => '/var/lib/emor-yedek/gunluk/denetim.log',
            'emergency' => '/var/lib/emor-yedek/gunluk/laravel.log',
        ], $yollar);
    }

    /**
     * Çerçevenin varsayılan config'i ve paketler kanal ekleyebilir (ör. Laravel
     * 13'ün `monthly`'si, Boost'un `browser`'ı); onların yolu LOG_KLASORU'nu
     * bilmez. Çalışma zamanındaki her dosya kanalı uygulama config'inde
     * tanımlı olmalı.
     */
    public function test_calisma_zamanindaki_her_dosya_kanali_uygulama_configinde_tanimli(): void
    {
        $calismaZamani = collect(config('logging.channels'))
            ->filter(fn (array $kanal): bool => isset($kanal['path']))
            ->keys()
            ->all();

        $this->assertSame([], array_values(array_diff($calismaZamani, array_keys($this->dosyaKanallari()))));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function bosDegerler(): array
    {
        return ['tanımsız' => [null], 'boş' => ['']];
    }

    #[DataProvider('bosDegerler')]
    public function test_gunluk_klasoru_verilmezse_uygulamanin_storage_logs_klasoru_kullanilir(?string $deger): void
    {
        $this->ortam($deger);

        $yollar = $this->dosyaKanallari();

        $this->assertSame(storage_path('logs/denetim.log'), $yollar['denetim']);
        $this->assertSame(storage_path('logs/laravel.log'), $yollar['emergency']);
    }

    /**
     * Yapılandırma dosyasını o anki ortamla yeniden değerlendirir; yolu olan
     * her kanalı döndürür (yeni eklenen dosya kanalı da listeye girer).
     *
     * @return array<string, string>
     */
    private function dosyaKanallari(): array
    {
        $yapilandirma = require config_path('logging.php');

        return collect($yapilandirma['channels'])
            ->filter(fn (array $kanal): bool => isset($kanal['path']))
            ->map(fn (array $kanal): string => $kanal['path'])
            ->all();
    }

    private function ortam(?string $deger): void
    {
        unset($_SERVER[self::DEGISKEN], $_ENV[self::DEGISKEN]);
        putenv(self::DEGISKEN);

        if ($deger !== null) {
            $_SERVER[self::DEGISKEN] = $_ENV[self::DEGISKEN] = $deger;
            putenv(self::DEGISKEN.'='.$deger);
        }
    }
}
