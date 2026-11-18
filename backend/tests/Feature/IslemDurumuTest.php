<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\IslemDurumu;
use App\Services\Yedekleme\IslemDurumuBozuk;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\YedekKilidi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * İşlem durumu dosyası (BY-005): veritabanına dayanmayan, atomik yazılan JSON.
 * Şema: Backup-Jira.md BY-005 "İşlem durumu dosyası şeması".
 */
final class IslemDurumuTest extends TestCase
{
    private const KIMLIK = '5b0f6f2e-3c1d-4b8a-9f77-1d2e3f4a5b6c';

    private string $klasor;

    private string $yol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->klasor = sys_get_temp_dir().'/yedek-durum-'.bin2hex(random_bytes(4));
        $this->yol = $this->klasor.'/islem.json';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->klasor)) {
            chmod($this->klasor, 0700);
            File::deleteDirectory($this->klasor);
        }

        parent::tearDown();
    }

    /**
     * @param  array{kod: string, mesaj: string}|null  $hata
     */
    private function kayit(IslemTuru $tur = IslemTuru::Yedek, bool $acik = false, string $adim = 'pg_dump', ?array $hata = null): IslemKaydi
    {
        return new IslemKaydi(
            kimlik: self::KIMLIK,
            tur: $tur,
            adim: $adim,
            acik: $acik,
            baslangic: CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'),
            sonGuncelleme: CarbonImmutable::parse('2026-11-18 10:05:30', 'UTC'),
            hata: $hata,
            erisimAnahtariOzeti: null,
        );
    }

    public function test_yazilan_kayit_aynen_okunur_dosya_baskalarina_kapali(): void
    {
        $durum = new IslemDurumu($this->yol);
        $yazilan = new IslemKaydi(
            kimlik: self::KIMLIK,
            tur: IslemTuru::GeriYuklemeUstune,
            adim: 'onay_bekliyor',
            acik: true,
            baslangic: CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'),
            sonGuncelleme: CarbonImmutable::parse('2026-11-18 10:05:30.250', 'UTC'),
            hata: ['kod' => 'SAYIM_FARKI', 'mesaj' => 'efatura_faturalari: 10 ≠ 11'],
            erisimAnahtariOzeti: hash('sha256', 'erisim-anahtari'),
        );

        $durum->yaz($yazilan);
        $okunan = $durum->oku();

        $this->assertEquals($yazilan, $okunan);
        $this->assertSame('640', substr(sprintf('%o', fileperms($this->yol)), -3));
        $this->assertSame(['islem.json'], array_values(array_diff(scandir($this->klasor), ['.', '..'])));
    }

    public function test_dosya_yokken_islem_yok_doner(): void
    {
        $durum = new IslemDurumu($this->yol);

        $this->assertNull($durum->oku());
        $this->assertFalse($durum->acikGeriYuklemeVarMi());
    }

    /**
     * @return array<string, array{IslemTuru, bool, bool}>
     */
    public static function acikGeriYuklemeDurumlari(): array
    {
        return [
            'üstüne geri yükleme, açık' => [IslemTuru::GeriYuklemeUstune, true, true],
            'üstüne geri yükleme, kapandı' => [IslemTuru::GeriYuklemeUstune, false, false],
            'kopya geri yükleme, açık' => [IslemTuru::GeriYuklemeKopya, true, false],
            'sınama geri yüklemesi, açık' => [IslemTuru::SinamaGeriYuklemesi, true, false],
            'yedek, açık' => [IslemTuru::Yedek, true, false],
        ];
    }

    #[DataProvider('acikGeriYuklemeDurumlari')]
    public function test_acik_geri_yukleme_yalniz_acik_ustune_geri_yuklemede_var_sayilir(IslemTuru $tur, bool $acik, bool $beklenen): void
    {
        $durum = new IslemDurumu($this->yol);
        $durum->yaz($this->kayit($tur, $acik));

        $this->assertSame($beklenen, $durum->acikGeriYuklemeVarMi());
    }

    /**
     * Şemaya uyan dosya içeriği (başka bir araç — ör. acil durum komutu — da
     * bu biçimde yazar). Bozuk içerikler bunun TEK alanını bozar.
     *
     * @return array<string, mixed>
     */
    private static function gecerliIcerik(): array
    {
        return [
            'surum' => 1, 'kimlik' => self::KIMLIK, 'tur' => 'geri_yukleme_ustune', 'adim' => 'pg_restore',
            'acik' => true, 'baslangic' => '2026-11-18T10:00:00.000000+00:00', 'son_guncelleme' => '2026-11-18T10:05:30.000000+00:00',
            'hata' => null, 'erisim_anahtari_ozeti' => null,
        ];
    }

    public function test_semaya_uyan_dosya_okunur(): void
    {
        File::ensureDirectoryExists($this->klasor);
        file_put_contents($this->yol, (string) json_encode(self::gecerliIcerik()));

        $kayit = (new IslemDurumu($this->yol))->oku();

        $this->assertSame(IslemTuru::GeriYuklemeUstune, $kayit?->tur);
        $this->assertSame('2026-11-18T10:05:30+00:00', $kayit->sonGuncelleme->toIso8601String());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function bozukIcerikler(): array
    {
        $gecerli = self::gecerliIcerik();
        $degistir = fn (array $fark): string => (string) json_encode(array_merge($gecerli, $fark));
        $eksik = $gecerli;
        unset($eksik['acik']);

        return [
            'boş dosya' => [''],
            'yarım JSON' => ['{"surum": 1, "kimlik": "'.self::KIMLIK.'", "tur": "geri_yu'],
            'nesne değil' => ['[1, 2, 3]'],
            'bilinmeyen sürüm' => [$degistir(['surum' => 2])],
            'eksik alan' => [(string) json_encode($eksik)],
            'geçersiz tür' => [$degistir(['tur' => 'bilinmeyen'])],
            'açık alanı metin' => [$degistir(['acik' => 'true'])],
            'geçersiz kimlik' => [$degistir(['kimlik' => 'abc'])],
            'geçersiz tarih' => [$degistir(['baslangic' => 'dün'])],
            'boş adım' => [$degistir(['adim' => ''])],
            'hata biçimi yanlış' => [$degistir(['hata' => 'patladı'])],
            'özet biçimi yanlış' => [$degistir(['erisim_anahtari_ozeti' => 'kisa'])],
            'olmayan gün (30 Şubat)' => [$degistir(['baslangic' => '2026-02-30T10:00:00.000000+00:00'])],
            'UTC dışı saat farkı' => [$degistir(['baslangic' => '2026-11-18T13:00:00.000000+03:00'])],
            'kısa mikrosaniye' => [$degistir(['baslangic' => '2026-11-18T10:00:00.5+00:00'])],
            'kimliğin sonunda satır sonu' => [$degistir(['kimlik' => self::KIMLIK."\n"])],
            'özetin sonunda satır sonu' => [$degistir(['erisim_anahtari_ozeti' => hash('sha256', 'x')."\n"])],
        ];
    }

    public function test_klasore_erisilemiyorsa_islem_yok_sanilmaz_hata_verir(): void
    {
        $durum = new IslemDurumu($this->yol);
        $durum->yaz($this->kayit(IslemTuru::GeriYuklemeUstune, true));
        chmod($this->klasor, 0000);

        try {
            $durum->acikGeriYuklemeVarMi();
            $this->fail('Erişilemeyen durum dosyası "işlem yok" sayıldı.');
        } catch (IslemDurumuBozuk $hata) {
            $this->assertStringContainsString($this->yol, $hata->getMessage());
        } finally {
            chmod($this->klasor, 0750);
        }
    }

    public function test_dosya_okunamiyorsa_hata_verir(): void
    {
        $durum = new IslemDurumu($this->yol);
        $durum->yaz($this->kayit(IslemTuru::GeriYuklemeUstune, true));
        chmod($this->yol, 0000);

        $this->expectException(IslemDurumuBozuk::class);

        $durum->oku();
    }

    public function test_klasor_hic_acilmamisken_ilk_calismada_islem_yok_doner(): void
    {
        // Geçiş düzeni: durum klasörü ilk yazımda açılır
        $durum = new IslemDurumu($this->klasor.'/alt/islem.json');

        $this->assertNull($durum->oku());
        $this->assertFalse($durum->acikGeriYuklemeVarMi());
    }

    /**
     * @return array<string, array{IslemKaydi}>
     */
    public static function gecersizKayitlar(): array
    {
        $kayit = fn (array $fark): IslemKaydi => new IslemKaydi(...array_merge([
            'kimlik' => self::KIMLIK,
            'tur' => IslemTuru::Yedek,
            'adim' => 'pg_dump',
            'acik' => false,
            'baslangic' => CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'),
            'sonGuncelleme' => CarbonImmutable::parse('2026-11-18 10:05:30', 'UTC'),
            'hata' => null,
            'erisimAnahtariOzeti' => null,
        ], $fark));

        return [
            'boş adım' => [$kayit(['adim' => ' '])],
            'hata biçimi yanlış' => [$kayit(['hata' => ['kod' => 'X']])],
            'kimlik UUID değil' => [$kayit(['kimlik' => 'abc'])],
            'özet SHA-256 değil' => [$kayit(['erisimAnahtariOzeti' => 'kisa'])],
        ];
    }

    #[DataProvider('gecersizKayitlar')]
    public function test_gecersiz_kayit_yazilmaz_onceki_durum_baytlariyla_korunur(IslemKaydi $gecersiz): void
    {
        $durum = new IslemDurumu($this->yol);
        $durum->yaz($this->kayit(adim: 'once'));
        $onceki = file_get_contents($this->yol);

        try {
            $durum->yaz($gecersiz);
            $this->fail('Geçersiz kayıt yazıldı.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($onceki, file_get_contents($this->yol));
        $this->assertSame(['islem.json'], array_values(array_diff(scandir($this->klasor), ['.', '..'])));
    }

    #[DataProvider('bozukIcerikler')]
    public function test_bozuk_dosya_anlasilir_hata_verir_islem_yok_sayilmaz(string $icerik): void
    {
        File::ensureDirectoryExists($this->klasor);
        file_put_contents($this->yol, $icerik);
        $durum = new IslemDurumu($this->yol);

        try {
            $durum->acikGeriYuklemeVarMi();
            $this->fail('Bozuk durum dosyası hatasız okundu.');
        } catch (IslemDurumuBozuk $hata) {
            $this->assertStringContainsString($this->yol, $hata->getMessage());
        }
    }

    public function test_eszamanli_okuyucu_yarim_ya_da_karisik_dosya_gormez(): void
    {
        $durum = new IslemDurumu($this->yol);
        $durum->yaz($this->kayit());
        $yazici = <<<'PHP'
            require $argv[1];
            $durum = new App\Services\Yedekleme\IslemDurumu($argv[2]);
            $bitis = microtime(true) + 1.5;
            for ($i = 0; microtime(true) < $bitis; $i++) {
                $durum->yaz(new App\Services\Yedekleme\IslemKaydi(
                    kimlik: '5b0f6f2e-3c1d-4b8a-9f77-1d2e3f4a5b6c',
                    tur: App\Services\Yedekleme\IslemTuru::Yedek,
                    adim: 'adim-'.$i,
                    acik: false,
                    baslangic: Carbon\CarbonImmutable::now(),
                    sonGuncelleme: Carbon\CarbonImmutable::now(),
                    hata: ['kod' => 'DENEME', 'mesaj' => str_repeat($i % 2 === 0 ? 'a' : 'b', 200000)],
                    erisimAnahtariOzeti: null,
                ));
            }
            echo $i;
            PHP;
        $surec = Process::timeout(30)->start([PHP_BINARY, '-r', $yazici, base_path('vendor/autoload.php'), $this->yol]);

        $okuma = 0;
        $karisik = 0;
        while ($surec->running()) {
            $mesaj = $durum->oku()?->hata['mesaj'] ?? '';
            $okuma++;
            if ($mesaj !== '' && strlen(count_chars($mesaj, 3)) !== 1) {
                $karisik++;
            }
        }
        $sonuc = $surec->wait();

        $this->assertTrue($sonuc->successful(), $sonuc->errorOutput());
        $this->assertGreaterThan(10, (int) $sonuc->output(), 'Yazıcı yeterince yazmadı');
        $this->assertGreaterThan(10, $okuma);
        $this->assertSame(0, $karisik);
    }

    public function test_yazilamazsa_onceki_durum_korunur_gecici_dosya_kalmaz(): void
    {
        $durum = new IslemDurumu($this->yol);
        $durum->yaz($this->kayit(adim: 'once'));
        chmod($this->klasor, 0500);

        try {
            $durum->yaz($this->kayit(adim: 'sonra'));
            $this->fail('Yazılamayan klasöre yazım hatasız geçti.');
        } catch (RuntimeException) {
            chmod($this->klasor, 0700);
        }

        $this->assertSame('once', $durum->oku()?->adim);
        $this->assertSame(['islem.json'], array_values(array_diff(scandir($this->klasor), ['.', '..'])));
    }

    public function test_veritabani_ve_onbellek_yokken_kilit_ve_durum_calisir(): void
    {
        config(['database.default' => 'olmayan_baglanti', 'cache.default' => 'olmayan_depo']);
        $kilit = new YedekKilidi($this->klasor.'/kilit');
        $durum = new IslemDurumu($this->yol);

        $this->assertTrue($kilit->al());
        $durum->yaz($this->kayit(IslemTuru::GeriYuklemeUstune, true));

        $this->assertTrue($durum->acikGeriYuklemeVarMi());
        $kilit->birak();
    }
}
