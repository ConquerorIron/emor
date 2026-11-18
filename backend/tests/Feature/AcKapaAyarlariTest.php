<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `.env`'deki aç/kapa ayarları (Dikkat_Edilecekler G6, G9; Fable incelemesi F9).
 * `(bool) 'off'` true'dur: "kapattım" sanılan modül açık kalırdı. Tanımsız ya
 * da boş değer varsayılana döner; tanınmayan değer KAPALI sayılır. Laravel'in
 * env() okuması gerçek ortam dizileri üzerinden sınanır.
 */
final class AcKapaAyarlariTest extends TestCase
{
    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $onceki = [];

    protected function tearDown(): void
    {
        foreach ($this->onceki as $ad => $deger) {
            unset($_SERVER[$ad], $_ENV[$ad]);
            putenv($ad);
            if ($deger['server'] !== null) {
                $_SERVER[$ad] = $deger['server'];
            }
            if ($deger['env'] !== null) {
                $_ENV[$ad] = $deger['env'];
            }
            if ($deger['putenv'] !== false) {
                putenv("{$ad}={$deger['putenv']}");
            }
        }

        parent::tearDown();
    }

    /**
     * Her ayar: değişken, config dosyası, dosyadaki anahtar yolu, varsayılan.
     *
     * @return array<string, array{string, string, string, bool}>
     */
    private static function ayarlar(): array
    {
        return [
            'yedekleme modülü' => ['YEDEKLEME_AKTIF', 'yedekleme', 'aktif', false],
            'e-Fatura senkronu' => ['EFATURA_SENKRON_AKTIF', 'entegrator', 'izibiz.senkron_aktif', true],
            'ERP istisna kodu yazımı' => ['EFATURA_ERP_ISTISNA_YAZ', 'efatura', 'erp_istisna_kodu_yaz', false],
            'hata ayıklama' => ['APP_DEBUG', 'app', 'debug', false],
        ];
    }

    /**
     * @return array<string, array{string, string, string, string|null, bool}>
     */
    public static function degerler(): array
    {
        $durumlar = [];
        foreach (self::ayarlar() as $ayar => [$degisken, $dosya, $yol, $varsayilan]) {
            foreach ([
                'tanımsız → varsayılan' => [null, $varsayilan],
                'boş → varsayılan' => ['', $varsayilan],
                'true' => ['true', true],
                'on' => ['on', true],
                'false' => ['false', false],
                'off' => ['off', false],
                'no' => ['no', false],
                'başında boşluk: " false"' => [' false', false],
                'tanınmayan: hayir' => ['hayir', false],
            ] as $durum => [$deger, $beklenen]) {
                $durumlar["{$ayar}, {$durum}"] = [$degisken, $dosya, $yol, $deger, $beklenen];
            }
        }

        return $durumlar;
    }

    #[DataProvider('degerler')]
    public function test_ac_kapa_ayari_yalniz_taninan_acik_degerde_acilir(string $degisken, string $dosya, string $yol, ?string $deger, bool $beklenen): void
    {
        $this->ortam($degisken, $deger);

        $yapilandirma = require config_path("{$dosya}.php");

        $this->assertSame($beklenen, data_get($yapilandirma, $yol));
    }

    /** Değişkeni Laravel'in okuduğu bütün kaynaklarda ayarlar (null: tanımsız) */
    private function ortam(string $ad, ?string $deger): void
    {
        $this->onceki[$ad] ??= ['server' => $_SERVER[$ad] ?? null, 'env' => $_ENV[$ad] ?? null, 'putenv' => getenv($ad)];

        unset($_SERVER[$ad], $_ENV[$ad]);
        putenv($ad);

        if ($deger !== null) {
            $_SERVER[$ad] = $_ENV[$ad] = $deger;
            putenv("{$ad}={$deger}");
        }
    }
}
