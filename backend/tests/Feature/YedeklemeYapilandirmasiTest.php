<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\YapilandirmaHatasi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * config/yedekleme.php: `.env`'de BOŞ bırakılan değişken de varsayılanı
 * kullanmalı (`.env.example` satırı açılıp boş bırakılabilir). Laravel'in
 * env() okuması gerçek ortam dizileri üzerinden sınanır (BY-004-R2).
 */
final class YedeklemeYapilandirmasiTest extends TestCase
{
    private const DEGISKENLER = [
        'YEDEKLEME_KLASORU',
        'YEDEKLEME_PG_DUMP',
        'YEDEKLEME_PG_RESTORE',
        'YEDEKLEME_PSQL',
        'YEDEKLEME_YEDEK_SURESI',
        'YEDEKLEME_GERI_YUKLEME_SURESI',
    ];

    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $onceki = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::DEGISKENLER as $ad) {
            $this->onceki[$ad] = ['server' => $_SERVER[$ad] ?? null, 'env' => $_ENV[$ad] ?? null, 'putenv' => getenv($ad)];
            $this->ortam($ad, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->onceki as $ad => $deger) {
            $this->ortam($ad, null);
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
     * @return array<string, array{string|null}>
     */
    public static function bosKlasorDegerleri(): array
    {
        return [
            'tanımsız' => [null],
            'boş' => [''],
        ];
    }

    #[DataProvider('bosKlasorDegerleri')]
    public function test_yedek_klasoru_verilmezse_storage_altindaki_varsayilan_kullanilir(?string $deger): void
    {
        $this->ortam('YEDEKLEME_KLASORU', $deger);

        $this->assertSame(storage_path('app/yedekler'), $this->yapilandirma()['klasor']);
    }

    public function test_yedek_klasoru_verilince_o_yol_kullanilir(): void
    {
        $this->ortam('YEDEKLEME_KLASORU', '/var/lib/emor-yedek/yedekler');

        $this->assertSame('/var/lib/emor-yedek/yedekler', $this->yapilandirma()['klasor']);
    }

    public function test_bos_birakilan_arac_yollari_ve_sure_sinirlari_varsayilana_doner(): void
    {
        foreach (['YEDEKLEME_PG_DUMP', 'YEDEKLEME_PG_RESTORE', 'YEDEKLEME_PSQL', 'YEDEKLEME_YEDEK_SURESI', 'YEDEKLEME_GERI_YUKLEME_SURESI'] as $ad) {
            $this->ortam($ad, '');
        }

        $yapilandirma = $this->yapilandirma();

        $this->assertSame(
            ['pg_dump' => '/usr/bin/pg_dump', 'pg_restore' => '/usr/bin/pg_restore', 'psql' => '/usr/bin/psql'],
            $yapilandirma['araclar'],
        );
        $this->assertSame(1800, $yapilandirma['sure_siniri']['yedek']);
        $this->assertSame(3600, $yapilandirma['sure_siniri']['geri_yukleme']);
    }

    public function test_verilen_arac_yolu_ve_sure_siniri_kullanilir(): void
    {
        $this->ortam('YEDEKLEME_PG_DUMP', '/usr/lib/postgresql/18/bin/pg_dump');
        $this->ortam('YEDEKLEME_YEDEK_SURESI', '900');

        $yapilandirma = $this->yapilandirma();

        $this->assertSame('/usr/lib/postgresql/18/bin/pg_dump', $yapilandirma['araclar']['pg_dump']);
        // Ham değer tüketen sınıfta doğrulanır ve tam sayıya çevrilir (BY-007, G9)
        config(['yedekleme.sure_siniri.yedek' => $yapilandirma['sure_siniri']['yedek']]);
        $this->assertSame(900, app(YedeklemeYapilandirmasi::class)->sureSiniri('yedek'));
    }

    public function test_istek_gondereni_uid_ayari_bos_gecerli_ve_gecersiz_degerde(): void
    {
        $yapilandirma = app(YedeklemeYapilandirmasi::class);
        $surec = stat('/proc/self')['uid'];

        // Boş: web = bu süreç (geçiş kipi), yayın tanımsız (yayın isteği kabul edilmez)
        config(['yedekleme.web_uid' => null, 'yedekleme.yayin_uid' => null]);
        $this->assertSame($surec, $yapilandirma->webUid());
        $this->assertNull($yapilandirma->yayinUid());

        config(['yedekleme.web_uid' => '33', 'yedekleme.yayin_uid' => '1000']);
        $this->assertSame(33, $yapilandirma->webUid());
        $this->assertSame(1000, $yapilandirma->yayinUid());

        // Geçersiz değer sessizce dönüştürülmez (G9); yayın = web istisnayı taklit ettirirdi
        foreach ([['web_uid', 'abc'], ['web_uid', '-1'], ['yayin_uid', '1.5'], ['yayin_uid', '33']] as [$anahtar, $deger]) {
            config(['yedekleme.web_uid' => '33', 'yedekleme.yayin_uid' => '1000', "yedekleme.{$anahtar}" => $deger]);
            try {
                $anahtar === 'web_uid' ? $yapilandirma->webUid() : $yapilandirma->yayinUid();
                $this->fail("{$anahtar}={$deger} kabul edildi");
            } catch (YapilandirmaHatasi) {
                // beklenen
            }
        }
    }

    /**
     * YF-02, YF-43: üstüne geri yükleme isteğinin ömrü ve bekleyen kopya
     * sınırı — tanımsız / boş varsayılana döner (G6), verilen kullanılır,
     * geçersiz sessizce dönüştürülmez (G9).
     */
    public function test_ustune_istek_omru_ve_bekleyen_kopya_siniri_bos_gecerli_ve_gecersiz_degerde(): void
    {
        $ayarlar = ['YEDEKLEME_USTUNE_ISTEK_OMRU_DK' => ['ustune_istek_omru_dk', 'ustuneIstekOmruDakika', 15], 'YEDEKLEME_BEKLEYEN_KOPYA_SINIRI' => ['bekleyen_kopya_siniri', 'bekleyenKopyaSiniri', 3]];
        $okuyucu = app(YedeklemeYapilandirmasi::class);

        try {
            foreach ($ayarlar as $degisken => [$anahtar, $yontem, $varsayilan]) {
                foreach ([null, ''] as $bos) {
                    $this->ortam($degisken, $bos);
                    config(["yedekleme.{$anahtar}" => $this->yapilandirma()[$anahtar]]);
                    $this->assertSame($varsayilan, $okuyucu->{$yontem}(), "{$degisken} boş");
                }

                $this->ortam($degisken, '45');
                config(["yedekleme.{$anahtar}" => $this->yapilandirma()[$anahtar]]);
                $this->assertSame(45, $okuyucu->{$yontem}());

                // '0' boş sayılır (config'in `?:` kalıbı): varsayılana döner, sınırsıza değil
                foreach (['abc', '-5', '1.5'] as $gecersiz) {
                    $this->ortam($degisken, $gecersiz);
                    config(["yedekleme.{$anahtar}" => $this->yapilandirma()[$anahtar]]);
                    try {
                        $okuyucu->{$yontem}();
                        $this->fail("{$degisken}={$gecersiz} kabul edildi");
                    } catch (YapilandirmaHatasi) {
                        // beklenen
                    }
                }
            }
        } finally {
            foreach (array_keys($ayarlar) as $degisken) {
                $this->ortam($degisken, null);
            }
        }
    }

    /**
     * YF-28: büyük geçici dosyaların kökü — boşsa yedek klasörünün diskinde
     * (`<klasor>/.calisma`; /tmp sunucuda bellektir), verilince o mutlak yol;
     * göreli yol açık hatadır (G9).
     */
    public function test_calisma_klasoru_bossa_yedek_klasorunun_altinda_verilince_o_yol_goreli_yol_hata(): void
    {
        $okuyucu = new YedeklemeYapilandirmasi;
        config(['yedekleme.klasor' => '/var/lib/emor-yedek/yedekler']);

        foreach ([null, ''] as $bos) {
            config(['yedekleme.calisma_klasoru' => $bos]);
            $this->assertSame('/var/lib/emor-yedek/yedekler/.calisma', $okuyucu->calismaKlasoru());
        }

        config(['yedekleme.calisma_klasoru' => '/srv/yedek-gecici/']);
        $this->assertSame('/srv/yedek-gecici', $okuyucu->calismaKlasoru());

        config(['yedekleme.calisma_klasoru' => 'gecici']);
        $this->expectException(YapilandirmaHatasi::class);
        $okuyucu->calismaKlasoru();
    }

    /**
     * Yapılandırma dosyasını o anki ortamla yeniden değerlendirir.
     *
     * @return array<string, mixed>
     */
    private function yapilandirma(): array
    {
        return require config_path('yedekleme.php');
    }

    /** Değişkeni Laravel'in okuduğu bütün kaynaklarda ayarlar (null: tanımsız) */
    private function ortam(string $ad, ?string $deger): void
    {
        if ($deger === null) {
            unset($_SERVER[$ad], $_ENV[$ad]);
            putenv($ad);

            return;
        }

        $_SERVER[$ad] = $deger;
        $_ENV[$ad] = $deger;
        putenv("{$ad}={$deger}");
    }
}
