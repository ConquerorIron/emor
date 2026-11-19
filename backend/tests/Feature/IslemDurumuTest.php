<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\IslemDurumu;
use App\Services\Yedekleme\IslemDurumuBozuk;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\YedekKilidi;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * İşlem durumu dosyası (BY-005): veritabanına dayanmayan, atomik yazılan JSON.
 * Şema: Backup-Jira.md BY-005 "İşlem durumu dosyası şeması". Yazan, yedek
 * kilidini tutan süreçtir (tek yazar).
 *
 * İzin kaldırarak sınayan testler root altında atlanır; alt süreç doğrulama
 * düşse de tearDown'da öldürülür (Dikkat_Edilecekler T11).
 */
final class IslemDurumuTest extends TestCase
{
    private const KIMLIK = '5b0f6f2e-3c1d-4b8a-9f77-1d2e3f4a5b6c';

    private const YEDEK_KIMLIGI = '0d4c1a2b-7e6f-4a3b-8c9d-0e1f2a3b4c5d';

    /**
     * Kurulumun kalıcı kıldığı kök (KaliciKlasor). Sistem geçici klasörü:
     * test klasörü onun altında açılır, izni kaldırılan test klasörü de
     * eşitleme zincirinde kalır (BY-005-R9).
     */
    private string $kaliciKok;

    private string $klasor;

    private string $yol;

    /** Durum dosyasına AİT OLMAYAN bir kilit dosyası (tek yazar kuralı sınanır) */
    private string $baskaKilitYolu;

    private ?InvokedProcess $surec = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kaliciKok = sys_get_temp_dir();
        $this->klasor = $this->kaliciKok.'/yedek-durum-'.bin2hex(random_bytes(4));
        $this->yol = $this->klasor.'/islem.json';
        $this->baskaKilitYolu = $this->klasor.'.baska-kilit';
    }

    protected function tearDown(): void
    {
        if ($this->surec?->running()) {
            $this->surec->signal(9);
            $this->surec->wait();
        }

        if (is_dir($this->klasor)) {
            chmod($this->klasor, 0700);
            File::deleteDirectory($this->klasor);
        }
        File::delete($this->baskaKilitYolu);

        parent::tearDown();
    }

    /** Durum dosyasının kendi kilidi (`<durum klasörü>/kilit`), alınmış hâlde */
    private function kilit(): YedekKilidi
    {
        $kilit = (new IslemDurumu($this->yol, $this->kaliciKok))->kilit();
        $this->assertTrue($kilit->al());

        return $kilit;
    }

    private function rootDegilGerekli(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root dosya izinlerini aşar; izin kaldırarak sınanamaz.');
        }
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

    public function test_yazilan_kayit_kurtarma_baglamiyla_aynen_okunur_dosya_baskalarina_kapali(): void
    {
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $yazilan = new IslemKaydi(
            kimlik: self::KIMLIK,
            tur: IslemTuru::GeriYuklemeUstune,
            adim: 'onay_bekliyor',
            acik: true,
            baslangic: CarbonImmutable::parse('2026-11-18 10:00:00', 'UTC'),
            sonGuncelleme: CarbonImmutable::parse('2026-11-18 10:05:30.250', 'UTC'),
            hata: ['kod' => 'SAYIM_FARKI', 'mesaj' => 'efatura_faturalari: 10 ≠ 11'],
            erisimAnahtariOzeti: hash('sha256', 'erisim-anahtari'),
            erisimAnahtariBitis: CarbonImmutable::parse('2026-11-18 14:00:00', 'UTC'),
            yedekKimligi: self::YEDEK_KIMLIGI,
            hedefVeritabani: 'erp_geri_20261118_100000',
            oncekiVeritabani: 'erp_onceki_20261118_100000',
            isteyenKullaniciId: 7,
        );

        $durum->yaz($yazilan, $this->kilit());
        $okunan = $durum->oku();

        $this->assertEquals($yazilan, $okunan);
        $this->assertSame('640', substr(sprintf('%o', fileperms($this->yol)), -3));
        $this->assertSame(['islem.json', 'kilit'], array_values(array_diff(scandir($this->klasor), ['.', '..'])));
    }

    /**
     * Tek yazar kuralı (Fable incelemesi F16): iki süreç oku-değiştir-yaz
     * yaparsa biri diğerinin kaydını ezer; yazan kilidi tutmak zorundadır.
     */
    public function test_kilidi_tutmayan_yazamaz_onceki_durum_baytlariyla_korunur(): void
    {
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $kilit = $this->kilit();
        $durum->yaz($this->kayit(adim: 'once'), $kilit);
        $onceki = file_get_contents($this->yol);
        $kilit->birak();

        try {
            $durum->yaz($this->kayit(adim: 'sonra'), $kilit);
            $this->fail('Kilidi tutmayan süreç durumu yazdı.');
        } catch (LogicException) {
        }

        $this->assertSame($onceki, file_get_contents($this->yol));
    }

    /**
     * Kilit, durum dosyasının KENDİ kilidi olmalı (BY-005-R6): başka bir
     * dosyanın kilidini tutan yazar, ortak kilidi tutan yazarla aynı anda
     * yazabilirdi.
     */
    public function test_baska_dosyanin_kilidiyle_yazilamaz_onceki_durum_korunur(): void
    {
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $durum->yaz($this->kayit(adim: 'once'), $this->kilit());
        $onceki = file_get_contents($this->yol);
        $baskaKilit = new YedekKilidi($this->baskaKilitYolu, $this->kaliciKok);
        $this->assertTrue($baskaKilit->al());

        try {
            $durum->yaz($this->kayit(adim: 'sonra'), $baskaKilit);
            $this->fail('Başka dosyanın kilidiyle durum yazıldı.');
        } catch (LogicException) {
        }

        $this->assertSame($onceki, file_get_contents($this->yol));
    }

    /**
     * Durum klasörü zaten var (kilit açtı) ama üst klasörü eşitlenemiyor:
     * yazım başarı dönmemeli — klasörün daha önce eşitlendiği varsayılmaz
     * (BY-005-R9, D3).
     */
    public function test_durum_klasorunun_ustu_esitlenemezse_yazim_basari_donmez(): void
    {
        $this->rootDegilGerekli();
        $durum = new IslemDurumu($this->klasor.'/durum/islem.json', $this->kaliciKok);
        $kilit = $durum->kilit();
        $this->assertTrue($kilit->al());
        chmod($this->klasor, 0300);

        try {
            $durum->yaz($this->kayit(), $kilit);
            $this->fail('Üst klasör eşitlenemezken durum yazımı başarılı döndü.');
        } catch (RuntimeException $hata) {
            $this->assertStringContainsString('eşitlenemedi', $hata->getMessage());
        } finally {
            chmod($this->klasor, 0700);
        }

        $durum->yaz($this->kayit(), $kilit);
        $this->assertSame('pg_dump', $durum->oku()?->adim);
    }

    /**
     * Eşitleme kalıcı kökte durur (BY-005-R11): kökün üstünü kurulum kalıcı
     * kılar ve orası yalnız geçilebilir olabilir (BY-003 okumaya kapalı üst
     * klasörü kabul eder). Kendi durum klasörüne yazabilen çalıştırıcı,
     * önceden kurulmuş durum dosyasını güncelleyebilmeli.
     */
    public function test_kalici_kokun_ustunde_yalniz_gecis_izni_varken_durum_yazilir(): void
    {
        $this->rootDegilGerekli();
        $kok = $this->klasor.'/kok';
        File::ensureDirectoryExists($kok.'/durum', 0750);
        $durum = new IslemDurumu($kok.'/durum/islem.json', $kok);
        $kilit = $durum->kilit();
        $this->assertTrue($kilit->al());
        $durum->yaz($this->kayit(adim: 'once'), $kilit);
        chmod($this->klasor, 0100);

        try {
            $durum->yaz($this->kayit(adim: 'sonra'), $kilit);
            $okunan = $durum->oku()?->adim;
        } finally {
            chmod($this->klasor, 0700);
        }

        $this->assertSame('sonra', $okunan);
    }

    public function test_dosya_yokken_islem_yok_doner(): void
    {
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);

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
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $durum->yaz($this->kayit($tur, $acik), $this->kilit());

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
            'hata' => null,
            'erisim_anahtari_ozeti' => hash('sha256', 'erisim-anahtari'), 'erisim_anahtari_bitis' => '2026-11-18T14:00:00.000000+00:00',
            'yedek_kimligi' => self::YEDEK_KIMLIGI, 'hedef_veritabani' => 'erp_geri_20261118_100000',
            'onceki_veritabani' => null, 'isteyen_kullanici_id' => 7,
        ];
    }

    public function test_semaya_uyan_dosya_okunur(): void
    {
        File::ensureDirectoryExists($this->klasor);
        file_put_contents($this->yol, (string) json_encode(self::gecerliIcerik()));

        $kayit = (new IslemDurumu($this->yol, $this->kaliciKok))->oku();

        $this->assertSame(IslemTuru::GeriYuklemeUstune, $kayit?->tur);
        $this->assertSame('2026-11-18T10:05:30+00:00', $kayit->sonGuncelleme->toIso8601String());
        $this->assertSame('2026-11-18T14:00:00+00:00', $kayit->erisimAnahtariBitis?->toIso8601String());
        $this->assertSame('erp_geri_20261118_100000', $kayit->hedefVeritabani);
        $this->assertSame(7, $kayit->isteyenKullaniciId);
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
        $eksikBaglam = $gecerli;
        unset($eksikBaglam['hedef_veritabani']);

        return [
            'boş dosya' => [''],
            'yarım JSON' => ['{"surum": 1, "kimlik": "'.self::KIMLIK.'", "tur": "geri_yu'],
            'nesne değil' => ['[1, 2, 3]'],
            'bilinmeyen sürüm' => [$degistir(['surum' => 2])],
            'eksik alan' => [(string) json_encode($eksik)],
            'eksik bağlam alanı' => [(string) json_encode($eksikBaglam)],
            'geçersiz tür' => [$degistir(['tur' => 'bilinmeyen'])],
            'açık alanı metin' => [$degistir(['acik' => 'true'])],
            'geçersiz kimlik' => [$degistir(['kimlik' => 'abc'])],
            'geçersiz tarih' => [$degistir(['baslangic' => 'dün'])],
            'boş adım' => [$degistir(['adim' => ''])],
            'hata biçimi yanlış' => [$degistir(['hata' => 'patladı'])],
            'hata alanında fazladan anahtar' => [$degistir(['hata' => ['kod' => 'X', 'mesaj' => 'y', 'ayrinti' => 'z']])],
            'özet biçimi yanlış' => [$degistir(['erisim_anahtari_ozeti' => 'kisa'])],
            'olmayan gün (30 Şubat)' => [$degistir(['baslangic' => '2026-02-30T10:00:00.000000+00:00'])],
            'UTC dışı saat farkı' => [$degistir(['baslangic' => '2026-11-18T13:00:00.000000+03:00'])],
            'kısa mikrosaniye' => [$degistir(['baslangic' => '2026-11-18T10:00:00.5+00:00'])],
            'kimliğin sonunda satır sonu' => [$degistir(['kimlik' => self::KIMLIK."\n"])],
            'özetin sonunda satır sonu' => [$degistir(['erisim_anahtari_ozeti' => hash('sha256', 'x')."\n"])],
            // Süresiz anahtar olmaz (README §7.2); bitişsiz özet ya da özetsiz bitiş bozuktur
            'özet var bitiş yok' => [$degistir(['erisim_anahtari_bitis' => null])],
            'bitiş var özet yok' => [$degistir(['erisim_anahtari_ozeti' => null])],
            'bitiş tarihi geçersiz' => [$degistir(['erisim_anahtari_bitis' => '2026-11-18 14:00'])],
            'yedek kimliği UUID değil' => [$degistir(['yedek_kimligi' => 'abc'])],
            'veritabanı adı tırnak gerektiriyor' => [$degistir(['hedef_veritabani' => 'erp-geri'])],
            'veritabanı adı büyük harf' => [$degistir(['onceki_veritabani' => 'ERP_onceki'])],
            'veritabanı adı 63 karakterden uzun' => [$degistir(['hedef_veritabani' => 'erp_'.str_repeat('a', 60)])],
            'kullanıcı kimliği sıfır' => [$degistir(['isteyen_kullanici_id' => 0])],
            'kullanıcı kimliği metin' => [$degistir(['isteyen_kullanici_id' => '7'])],
        ];
    }

    public function test_klasore_erisilemiyorsa_islem_yok_sanilmaz_hata_verir(): void
    {
        $this->rootDegilGerekli();
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $durum->yaz($this->kayit(IslemTuru::GeriYuklemeUstune, true), $this->kilit());
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
        $this->rootDegilGerekli();
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $durum->yaz($this->kayit(IslemTuru::GeriYuklemeUstune, true), $this->kilit());
        chmod($this->yol, 0000);

        $this->expectException(IslemDurumuBozuk::class);

        $durum->oku();
    }

    public function test_klasor_hic_acilmamisken_ilk_calismada_islem_yok_doner(): void
    {
        // Geçiş düzeni: durum klasörü ilk yazımda açılır
        $durum = new IslemDurumu($this->klasor.'/alt/islem.json', $this->kaliciKok);

        $this->assertNull($durum->oku());
        $this->assertFalse($durum->acikGeriYuklemeVarMi());
    }

    /**
     * Yokluk yalnız kalıcı kökün içinde doğrulanır: kök kuruluysa henüz
     * açılmamış durum klasörü "işlem yok"tur; kök yoksa ya da okunamıyorsa
     * durum bilinmez ve BY-029 bunu çalışmaya izin sanmamalı (BY-005-R12).
     */
    public function test_yokluk_kalici_kokte_durur_kurulmamis_ya_da_okunamayan_kok_hata_verir(): void
    {
        $durum = new IslemDurumu($this->klasor.'/durum/islem.json', $this->klasor);

        // Kurulmamış kök: üstündeki sistem geçici klasörü okunabilir olsa da "yok" denmez
        try {
            $durum->acikGeriYuklemeVarMi();
            $this->fail('Kurulmamış kalıcı kök "açık geri yükleme yok" sayıldı.');
        } catch (IslemDurumuBozuk $hata) {
            $this->assertStringContainsString('kalıcı kökü', $hata->getMessage());
        }

        // Kurulu, boş kök: durum klasörü henüz açılmamış → işlem yok
        mkdir($this->klasor, 0750);
        $this->assertNull($durum->oku());

        $this->rootDegilGerekli();
        chmod($this->klasor, 0000);
        try {
            $durum->acikGeriYuklemeVarMi();
            $this->fail('Okunamayan kalıcı kök "açık geri yükleme yok" sayıldı.');
        } catch (IslemDurumuBozuk $hata) {
            $this->assertStringContainsString('erişilemiyor', $hata->getMessage());
        } finally {
            chmod($this->klasor, 0750);
        }
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
            'özet var bitiş yok' => [$kayit(['erisimAnahtariOzeti' => hash('sha256', 'x')])],
            'veritabanı adı geçersiz' => [$kayit(['hedefVeritabani' => 'erp; drop database erp'])],
            // Okuyucu fazladan alanı taşımazdı ama yazıcı ham diziyi yazıyordu;
            // derin iç içe alan dosyayı okunamaz bırakıyordu (BY-005-R7)
            'hata alanında fazladan derin anahtar' => [$kayit(['hata' => ['kod' => 'X', 'mesaj' => 'y', 'ic' => array_reduce(range(1, 20), fn (array $ic): array => [$ic], [])]])],
        ];
    }

    #[DataProvider('gecersizKayitlar')]
    public function test_gecersiz_kayit_yazilmaz_onceki_durum_baytlariyla_korunur(IslemKaydi $gecersiz): void
    {
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $kilit = $this->kilit();
        $durum->yaz($this->kayit(adim: 'once'), $kilit);
        $onceki = file_get_contents($this->yol);

        try {
            $durum->yaz($gecersiz, $kilit);
            $this->fail('Geçersiz kayıt yazıldı.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame($onceki, file_get_contents($this->yol));
        $this->assertSame(['islem.json', 'kilit'], array_values(array_diff(scandir($this->klasor), ['.', '..'])));
    }

    #[DataProvider('bozukIcerikler')]
    public function test_bozuk_dosya_anlasilir_hata_verir_islem_yok_sayilmaz(string $icerik): void
    {
        File::ensureDirectoryExists($this->klasor);
        file_put_contents($this->yol, $icerik);
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);

        try {
            $durum->acikGeriYuklemeVarMi();
            $this->fail('Bozuk durum dosyası hatasız okundu.');
        } catch (IslemDurumuBozuk $hata) {
            $this->assertStringContainsString($this->yol, $hata->getMessage());
        }
    }

    public function test_eszamanli_okuyucu_yarim_ya_da_karisik_dosya_gormez(): void
    {
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $kilit = $this->kilit();
        $durum->yaz($this->kayit(), $kilit);
        // Yazıcı ayrı süreç: kilidi o alır (tek yazar)
        $kilit->birak();
        $yazici = <<<'PHP'
            require $argv[1];
            $durum = new App\Services\Yedekleme\IslemDurumu($argv[2], $argv[3]);
            $kilit = $durum->kilit();
            $kilit->al() || exit(3);
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
                ), $kilit);
            }
            echo $i;
            PHP;
        $this->surec = Process::timeout(30)->start([PHP_BINARY, '-r', $yazici, base_path('vendor/autoload.php'), $this->yol, $this->kaliciKok]);

        $okuma = 0;
        $karisik = 0;
        while ($this->surec->running()) {
            $mesaj = $durum->oku()?->hata['mesaj'] ?? '';
            $okuma++;
            if ($mesaj !== '' && strlen(count_chars($mesaj, 3)) !== 1) {
                $karisik++;
            }
        }
        $sonuc = $this->surec->wait();

        $this->assertTrue($sonuc->successful(), $sonuc->errorOutput());
        $this->assertGreaterThan(10, (int) $sonuc->output(), 'Yazıcı yeterince yazmadı');
        $this->assertGreaterThan(10, $okuma);
        $this->assertSame(0, $karisik);
    }

    public function test_yazilamazsa_onceki_durum_korunur_gecici_dosya_kalmaz(): void
    {
        $this->rootDegilGerekli();
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);
        $kilit = $this->kilit();
        $durum->yaz($this->kayit(adim: 'once'), $kilit);
        chmod($this->klasor, 0500);

        try {
            $durum->yaz($this->kayit(adim: 'sonra'), $kilit);
            $this->fail('Yazılamayan klasöre yazım hatasız geçti.');
        } catch (RuntimeException) {
            chmod($this->klasor, 0700);
        }

        $this->assertSame('once', $durum->oku()?->adim);
        $this->assertSame(['islem.json', 'kilit'], array_values(array_diff(scandir($this->klasor), ['.', '..'])));
    }

    public function test_veritabani_ve_onbellek_yokken_kilit_ve_durum_calisir(): void
    {
        config(['database.default' => 'olmayan_baglanti', 'cache.default' => 'olmayan_depo']);
        $kilit = new YedekKilidi($this->klasor.'/kilit', $this->kaliciKok);
        $durum = new IslemDurumu($this->yol, $this->kaliciKok);

        $this->assertTrue($kilit->al());
        $durum->yaz($this->kayit(IslemTuru::GeriYuklemeUstune, true), $kilit);

        $this->assertTrue($durum->acikGeriYuklemeVarMi());
        $kilit->birak();
    }
}
