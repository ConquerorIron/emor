<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\YedekImzasi;
use Closure;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BY-024-R2: kurtarmada SQL çalıştırmadan önce manifest imzası ve yedek
 * dosyasının özeti doğrulanır (kurtarma.md §4.6, README §5.2).
 */
final class YedekManifestDogrulaTest extends TestCase
{
    private const DUMP_ADI = 'erp_elle_2026-11-21_101500+0300.dump';

    private string $klasor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->klasor = sys_get_temp_dir().'/yedek-manifest-dogrula-'.bin2hex(random_bytes(4));
        mkdir($this->klasor, 0750);
        config([
            'yedekleme.klasor' => $this->klasor,
            'yedekleme.kalici_kok' => sys_get_temp_dir(),
            'yedekleme.imza_anahtari' => $this->klasor.'/anahtar/imza.key',
        ]);
        app(YedekImzasi::class)->anahtarUret();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->klasor);

        parent::tearDown();
    }

    /** Kurtarmadaki gibi: çözülmüş dump ve imzalı manifesti yan yana */
    private function yedek(string $icerik = 'dump!'): string
    {
        $dump = $this->klasor.'/'.self::DUMP_ADI;
        file_put_contents($dump, $icerik);
        app(ManifestDeposu::class)->yaz(YedekManifestiTest::manifest(dosyaAdi: self::DUMP_ADI), $this->klasor);

        return $dump;
    }

    public function test_imzasi_ve_ozeti_tutan_yedek_dogrulanir_createdb_degerleri_yazilir(): void
    {
        $this->artisan('yedek:manifest-dogrula', ['dump' => $this->yedek()])
            ->expectsOutputToContain('DOGRULANDI')
            ->expectsOutputToContain('--encoding=UTF8 --lc-collate=en_US.UTF-8 --lc-ctype=en_US.UTF-8')
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{Closure(self, string): void}>
     */
    public static function guvenilmeyenManifestler(): array
    {
        return [
            // Özet de değiştirilse imza tutmaz: bozuk dump'a uydurulmuş manifest geçmez
            'özeti değiştirilmiş manifest' => [function (self $test, string $manifest): void {
                file_put_contents($manifest, str_replace(hash('sha256', 'dump!'), hash('sha256', 'dumP!'), (string) file_get_contents($manifest)));
            }],
            'yanlış imza anahtarı' => [function (self $test, string $manifest): void {
                unlink(config('yedekleme.imza_anahtari'));
                $test->app->forgetInstance(YedekImzasi::class);
                app(YedekImzasi::class)->anahtarUret();
            }],
            'imza anahtarı yok' => [function (self $test, string $manifest): void {
                unlink(config('yedekleme.imza_anahtari'));
                $test->app->forgetInstance(YedekImzasi::class);
            }],
        ];
    }

    /**
     * @param  Closure(self, string): void  $boz
     */
    #[DataProvider('guvenilmeyenManifestler')]
    public function test_imzasi_dogrulanamayan_manifestte_basarisiz_doner(Closure $boz): void
    {
        $dump = $this->yedek();
        $boz($this, ManifestDeposu::jsonYolu($dump));

        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump])
            ->expectsOutputToContain('GERI_YUKLEME_IMZA_GECERSIZ')
            ->doesntExpectOutputToContain('DOGRULANDI')
            ->assertFailed();
    }

    public function test_ozeti_manifestle_tutmayan_yedek_dosyasi_reddedilir(): void
    {
        $dump = $this->yedek();
        // Aynı boyutta tek bayt farkı (bozuk indirme)
        file_put_contents($dump, 'dumP!');

        $this->artisan('yedek:manifest-dogrula', ['dump' => $dump])
            ->expectsOutputToContain('GERI_YUKLEME_OZET_TUTMUYOR')
            ->assertFailed();
    }

    public function test_baska_yedegin_imzali_manifesti_kabul_edilmez(): void
    {
        $dump = $this->yedek();
        $baska = $this->klasor.'/erp_elle_2026-11-22_101500+0300.dump';
        copy($dump, $baska);

        $this->artisan('yedek:manifest-dogrula', ['dump' => $baska, '--manifest' => ManifestDeposu::jsonYolu($dump)])
            ->expectsOutputToContain('başka bir yedeğin')
            ->assertFailed();
    }
}
