<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\YedeklemeMaili;
use App\Models\GeriYukleme;
use App\Models\User;
use App\Models\Yedek;
use App\Services\Yedekleme\BakimModu;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\OnayBilgisi;
use App\Services\Yedekleme\UstuneGeriYuklemeAdimi;
use App\Services\Yedekleme\UstuneGeriYukleyici;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YeniVeritabanindaMigration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\SahtePostgres;
use Tests\Yedekleme\UstuneGeriYuklemeOrtami;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-028: üstüne geri yükleme çekirdeği (README §6.4 1–9, hata kuralı,
 * yarıda kalan işlem). PostgreSQL ve dış komutlar sahte; gerçek PostgreSQL'de
 * süreç öldürme denemesi teslim günlüğünde.
 */
final class UstuneGeriYukleyiciTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SahtePostgres;
    use UstuneGeriYuklemeOrtami;
    use YedeklemeOrtami;

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ustuneOrtaminiKur();
        $this->yonetici = User::factory()->yonetici()->create();
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    public function test_adimlar_sirayla_yurur_canli_yer_degistirir_ve_onay_bekler(): void
    {
        $yedek = $this->ustuneYedekAl();

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $kayit->adim);
        $this->assertTrue($kayit->acik);
        $this->assertNull($kayit->hata);
        $hedef = (string) $kayit->hedefVeritabani;
        $onceki = (string) $kayit->oncekiVeritabani;
        $this->assertMatchesRegularExpression('/^erp_geri_\d{8}_\d{6}$/D', $hedef);
        $this->assertMatchesRegularExpression('/^erp_onceki_\d{8}_\d{6}$/D', $onceki);

        // Sıra: kuyruk durur → öncesi yedek → yeni veritabanı → migration → kapat → yer değiştir
        $this->assertSame([
            'systemctl stop emor-queue', 'pg_dump', "olustur {$hedef}", 'migrate', 'kapat erp', "yer erp>{$onceki} {$hedef}>erp",
        ], $this->komutlar);
        // Geri yükleme öncesi yedek bakım modundan SONRA alındı (P3)
        $this->assertSame([true], $this->pgDumpBakimda);

        // Eski veritabanı önceki adıyla duruyor ve bağlantıya kapalı; yeni canlı açık
        $this->assertContains($onceki, $this->veritabanlari);
        $this->assertNotContains($hedef, $this->veritabanlari);
        $this->assertSame([$onceki => true], $this->baglantiyaKapali);

        // Bakım modu sürüyor, kuyruk başlatılmadı
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));
        $this->assertNotContains('systemctl start emor-queue', $this->komutlar);

        // Geçmiş kaydı dosyadan yazıldı; öncesi yedek bağlı
        $geri = GeriYukleme::query()->where('kimlik', $kayit->kimlik)->firstOrFail();
        $this->assertSame(GeriYuklemeTuru::Ustune, $geri->tur);
        $this->assertSame(GeriYuklemeDurumu::OnayBekliyor, $geri->durum);
        $this->assertSame($onceki, $geri->onceki_veritabani);
        $oncesi = Yedek::query()->findOrFail($geri->getAttribute('oncesi_yedek_id'));
        $this->assertSame(YedekKaynagi::GeriYuklemeOncesi, $oncesi->kaynak);

        $this->assertIsArray(app(OnayBilgisi::class)->bilgiOku($kayit->kimlik));
        Mail::assertSent(YedeklemeMaili::class, 2);
    }

    public function test_ilk_yazim_kurtarma_baglamini_tasir_ve_adimlar_enumdan(): void
    {
        $yedek = $this->ustuneYedekAl();
        $okunanlar = [];
        $this->sahteUstune(['*artisan*migrate*' => function () use (&$okunanlar) {
            $okunanlar[] = $this->durumOku();

            return '';
        }]);

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        // Migration sırasında (ara yazım) bağlam tam: yedek, hedef, önceki, isteyen
        $ara = $okunanlar[0];
        $this->assertSame(UstuneGeriYuklemeAdimi::Migration->value, $ara->adim);
        $this->assertSame($yedek->kimlik, $ara->yedekKimligi);
        $this->assertSame($kayit->hedefVeritabani, $ara->hedefVeritabani);
        $this->assertSame($kayit->oncekiVeritabani, $ara->oncekiVeritabani);
        $this->assertSame($this->yonetici->id, $ara->isteyenKullaniciId);
        $this->assertNotNull(UstuneGeriYuklemeAdimi::tryFrom($ara->adim));
    }

    public function test_migration_ayri_surecte_yalniz_yeni_veritabanini_gorur(): void
    {
        $yedek = $this->ustuneYedekAl();

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        Process::assertRan(fn ($p): bool => in_array('migrate', (array) $p->command, true)
            && in_array('--database=yedek_test', (array) $p->command, true)
            && isset($p->environment['APP_CONFIG_CACHE']));

        $ayar = $this->migrationYapilandirmasi;
        $this->assertIsArray($ayar);
        $this->assertSame('yedek_test', $ayar['database']['default']);
        $this->assertSame($kayit->hedefVeritabani, $ayar['database']['connections']['yedek_test']['database']);
        $this->assertSame('array', $ayar['cache']['default']);
        $this->assertSame('array', $ayar['session']['driver']);
        $this->assertSame('sync', $ayar['queue']['default']);
        // Canlıyı gösteren hiçbir bağlantı kalmadı
        foreach ($ayar['database']['connections'] as $tanim) {
            $this->assertNotSame('erp', $tanim['database'] ?? null);
        }
        // Yapılandırma dosyası (parola, APP_KEY) ve klasörü silindi (D2, T8)
        $this->assertNotNull($this->migrationYapilandirmaDosyasi);
        $this->assertFileDoesNotExist((string) $this->migrationYapilandirmaDosyasi);
        $this->assertDirectoryDoesNotExist(dirname((string) $this->migrationYapilandirmaDosyasi));
    }

    public function test_migration_yapilandirmasi_gercek_cocuk_surece_yeni_veritabani_olarak_ulasir(): void
    {
        $klasor = $this->yedekKoku.'/migration-deneme';
        mkdir($klasor, 0700);
        $dosya = app(YeniVeritabanindaMigration::class)->yapilandirmaDosyasiYaz('erp_geri_20261121_103000', $klasor);

        // Gerçek alt süreç (sahte değil): Laravel yapılandırmayı bu dosyadan okur, .env'den değil
        $sonuc = (new \Symfony\Component\Process\Process(
            [PHP_BINARY, base_path('artisan'), 'tinker', '--execute', 'echo json_encode([config("database.default"), config("database.connections.yedek_test.database"), config("cache.default"), config("queue.default")]);'],
            base_path(),
            ['APP_CONFIG_CACHE' => $dosya, 'DB_DATABASE' => 'erp', 'DB_CONNECTION' => 'pgsql'],
        ));
        $sonuc->setTimeout(60)->run();

        $this->assertTrue($sonuc->isSuccessful(), $sonuc->getErrorOutput());
        $this->assertStringContainsString('["yedek_test","erp_geri_20261121_103000","array","sync"]', $sonuc->getOutput());
    }

    public function test_yedekte_olup_kodda_olmayan_migration_varsa_baslamaz(): void
    {
        $this->kaynakVeritabani->migrationlar = [...$this->kaynakVeritabani->migrationlar, '2099_01_01_000000_baska_daldan'];
        $yedek = $this->ustuneYedekAl();

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertSame(UstuneGeriYuklemeAdimi::Basarisiz->value, $kayit->adim);
        $this->assertSame(GeriYuklemeHatasi::SEMA_UYUMSUZ, $kayit->hata['kod']);
        $this->assertFalse($kayit->acik);
        $this->assertSame([], $this->komutlar);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
    }

    public function test_ice_alinmis_yedek_ustune_yuklenmez(): void
    {
        $yedek = $this->ustuneYedekAl();
        $yedek->update(['ice_alindi' => true]);

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertSame(GeriYuklemeHatasi::SEMA_BILINMIYOR, $kayit->hata['kod']);
        $this->assertSame([], $this->komutlar);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function onDenetimReddi(): array
    {
        return [
            'onay metni canlının adı değil' => ['onay', GeriYuklemeHatasi::ONAY_METNI_TUTMUYOR],
            'servis çalıştırıcısı yok' => ['servis', GeriYuklemeHatasi::SERVIS_GEREKLI],
            'uygulama zaten bakım modunda' => ['bakim', GeriYuklemeHatasi::BAKIM_MODU],
            'hedef ad zaten var' => ['hedef', GeriYuklemeHatasi::HEDEF_VAR],
        ];
    }

    #[DataProvider('onDenetimReddi')]
    public function test_on_denetim_reddinde_hicbir_sey_degismez(string $durum, string $kod): void
    {
        $yedek = $this->ustuneYedekAl();
        $onay = 'erp';
        match ($durum) {
            'onay' => $onay = 'ERP',
            'servis' => config(['yedekleme.calistirici' => 'dogrudan']),
            'bakim' => file_put_contents(storage_path('framework/down'), '{"status":503}'),
            'hedef' => $this->veritabanlari[] = 'erp_onceki_'.now('Europe/Istanbul')->format('Ymd_His'),
        };

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id, $onay);

        $this->assertSame(UstuneGeriYuklemeAdimi::Basarisiz->value, $kayit->adim);
        $this->assertSame($kod, $kayit->hata['kod']);
        $this->assertFalse($kayit->acik);
        $this->assertSame([], $this->komutlar);
        $this->assertSame(0, GeriYukleme::query()->count());
    }

    /**
     * Yer değiştirmeden önceki her adımda hata: canlı aynen durur, yeni
     * veritabanı silinir, servisler açılır (README §6.4 hata kuralı).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function yerDegistirmedenOnceHata(): array
    {
        return [
            'bakım modu (kuyruk durmadı)' => ['kuyruk', GeriYuklemeHatasi::KUYRUK_SERVISI],
            'geri yükleme öncesi yedek' => ['oncesi', GeriYuklemeHatasi::ONCESI_YEDEK],
            'veritabanı açılamadı' => ['olustur', GeriYuklemeHatasi::VERITABANI_OLUSTURULAMADI],
            'pg_restore' => ['pg_restore', GeriYuklemeHatasi::PG_RESTORE],
            'manifestle karşılaştırma' => ['karsilastirma', GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI],
            'migration' => ['migration', GeriYuklemeHatasi::MIGRATION],
            'sağlık denetimi' => ['saglik', GeriYuklemeHatasi::MIGRATION],
            'bağlantıya kapatma' => ['kapatma', GeriYuklemeHatasi::BAGLANTI_KAPATILAMADI],
            'yer değiştirme (adlar değişmedi)' => ['yer', GeriYuklemeHatasi::YER_DEGISTIRILEMEDI],
        ];
    }

    #[DataProvider('yerDegistirmedenOnceHata')]
    public function test_yer_degistirmeden_once_hata_canliyi_korur_ve_servisleri_acar(string $hata, string $kod): void
    {
        $yedek = $this->ustuneYedekAl();
        match ($hata) {
            'kuyruk' => $this->sudoHatasi = 'sudo: a password is required',
            'oncesi' => $this->sahteUstune(['*pg_dump*' => fn ($p) => in_array('--version', (array) $p->command, true) ? 'pg_dump (PostgreSQL) 18.6' : Process::result(errorOutput: 'pg_dump: error', exitCode: 1)]),
            'olustur' => $this->olusturmaHatasi = 'ERROR:  permission denied to create database',
            'pg_restore' => $this->pgRestoreHatasi = 'pg_restore: error: could not execute query',
            'karsilastirma' => $this->geriYuklenenIcerik = ['tablolar' => ['users' => 99], 'migrationlar' => $this->kaynakVeritabani->migrationlar, 'sahipler' => ['erp']],
            'migration' => $this->migrationHatasi = 'SQLSTATE[42P07]: Duplicate table',
            'saglik' => $this->sahteUstune(['*artisan*migrate*' => function () {
                $this->komutlar[] = 'migrate';
                // Migration "başarılı" ama sonrasında yedeğin listesi yanlış çıksın
                $this->geriYuklenenIcerik = ['tablolar' => $this->kaynakVeritabani->satirSayilari, 'migrationlar' => array_slice($this->kaynakVeritabani->migrationlar, 0, 2), 'sahipler' => ['erp']];

                return '';
            }]),
            'kapatma' => $this->kapatmaHatasi = 'ERROR:  permission denied to terminate process',
            'yer' => $this->yerDegistirmeHatasi = 'ERROR:  database "erp" is being accessed by other users',
        };

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertSame(UstuneGeriYuklemeAdimi::Basarisiz->value, $kayit->adim, (string) json_encode($kayit->hata));
        $this->assertSame($kod, $kayit->hata['kod']);
        $this->assertFalse($kayit->acik);
        // Canlı yerinde, adı değişmedi; önceki ad hiç oluşmadı; yeni veritabanı kalmadı
        $this->assertContains('erp', $this->veritabanlari);
        $this->assertNotContains($kayit->oncekiVeritabani, $this->veritabanlari);
        $this->assertNotContains($kayit->hedefVeritabani, $this->veritabanlari);
        $this->assertSame([], $this->baglantiyaKapali);
        // Servisler açıldı: bakım modu kalktı, kuyruk başlatıldı
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertContains('systemctl start emor-queue', $this->komutlar);
        $this->assertSame(GeriYuklemeDurumu::Basarisiz, GeriYukleme::query()->where('kimlik', $kayit->kimlik)->firstOrFail()->durum);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: UstuneGeriYuklemeAdimi}>
     */
    public static function yerDegistirmedenSonraHata(): array
    {
        return [
            'dizin kurulamadı' => ['dizin', GeriYuklemeHatasi::DIZIN_KURULAMADI, UstuneGeriYuklemeAdimi::DizinKurma],
            'onay bilgisi hesaplanamadı' => ['ayar', GeriYuklemeHatasi::AYAR_UZLASTIRILAMADI, UstuneGeriYuklemeAdimi::AyarUzlastirma],
        ];
    }

    #[DataProvider('yerDegistirmedenSonraHata')]
    public function test_yer_degistirmeden_sonra_hata_bakim_modunu_korur_karar_yoneticinin(string $hata, string $kod, UstuneGeriYuklemeAdimi $adim): void
    {
        if ($hata === 'dizin' && function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('root, izni kaldırılmış klasörü yine okur (T11).');
        }

        $yedek = $this->ustuneYedekAl();
        $klasor = (string) config('yedekleme.klasor');
        $this->yerDegistirmeSonrasi = match ($hata) {
            'dizin' => fn () => chmod($klasor, 0),
            // Onay bilgisinin okuduğu, başka tablonun bağlı olmadığı tablo: PostgreSQL
            // bağımlı yabancı anahtarlı tabloyu düşürmez (SQLite düşürür — T6)
            'ayar' => fn () => Schema::drop('rol_izinleri'),
        };

        try {
            [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);
        } finally {
            @chmod($klasor, 0750);
        }

        $this->assertNull($this->kancaHatasi, 'Hata üreten kanca çalışmadı: '.$this->kancaHatasi?->getMessage());
        $this->assertSame($adim->value, $kayit->adim);
        $this->assertSame($kod, $kayit->hata['kod']);
        $this->assertTrue($kayit->acik);
        // Yer değişti, uygulama AÇILMADI
        $this->assertContains($kayit->oncekiVeritabani, $this->veritabanlari);
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));
        $this->assertNotContains('systemctl start emor-queue', $this->komutlar);
    }

    public function test_yer_degistirme_hata_dondu_ama_adlar_degismisse_geri_sarilmaz_devam_edilir(): void
    {
        $yedek = $this->ustuneYedekAl();
        // Ad değişikliği oldu ama yanıt hata gibi döndü (ör. bağlantı COMMIT'ten sonra koptu)
        $this->sahteUstune(['*psql*' => function ($p) {
            $sonuc = $this->psqlUstune($p);
            if (str_contains((string) $p->input, 'RENAME TO')) {
                return Process::result(errorOutput: 'server closed the connection unexpectedly', exitCode: 2);
            }

            return $sonuc;
        }]);

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $kayit->adim);
        $this->assertNotContains('sil '.$kayit->hedefVeritabani, $this->komutlar);
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));
    }

    // ------------------------------------------------- yarıda kalan işlem

    /**
     * @return array<string, array{0: UstuneGeriYuklemeAdimi, 1: bool}>
     */
    public static function yaridaKalanOnce(): array
    {
        return [
            'bakım modu' => [UstuneGeriYuklemeAdimi::BakimModu, false],
            'öncesi yedek' => [UstuneGeriYuklemeAdimi::OncesiYedek, false],
            'veritabanı açılırken' => [UstuneGeriYuklemeAdimi::Veritabani, true],
            'pg_restore' => [UstuneGeriYuklemeAdimi::PgRestore, true],
            'migration' => [UstuneGeriYuklemeAdimi::Migration, true],
            'doğrulama' => [UstuneGeriYuklemeAdimi::Dogrulama, true],
            'bağlantı kapatma' => [UstuneGeriYuklemeAdimi::BaglantiKapatma, true],
        ];
    }

    #[DataProvider('yaridaKalanOnce')]
    public function test_yer_degistirmeden_once_olen_islem_geri_sarilir(UstuneGeriYuklemeAdimi $adim, bool $hedefVar): void
    {
        $kayit = $this->durumYaz($adim, true);
        $this->bakimDosyasiYaz($kayit->kimlik);
        if ($hedefVar) {
            $this->veritabanlari[] = (string) $kayit->hedefVeritabani;
        }
        if ($adim === UstuneGeriYuklemeAdimi::BaglantiKapatma) {
            $this->baglantiyaKapali['erp'] = true;
        }

        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());

        $son = $this->durumOku();
        $this->assertSame(UstuneGeriYuklemeAdimi::Basarisiz->value, $son?->adim);
        $this->assertSame(GeriYuklemeHatasi::YARIDA_KALDI, $son->hata['kod']);
        $this->assertFalse($son->acik);
        $this->assertSame(['erp', 'postgres'], $this->veritabanlari);
        $this->assertSame([], $this->baglantiyaKapali);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertContains('systemctl start emor-queue', $this->komutlar);
    }

    public function test_yer_degistirmede_olen_islem_adlara_gore_karar_verir(): void
    {
        $yedek = $this->ustuneYedekAl();

        // a) Ad değişikliği olmuş: 8. adımdan devam
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::YerDegistirme, true, yedekKimligi: $yedek->kimlik, isteyen: $this->yonetici->id);
        $this->bakimDosyasiYaz($kayit->kimlik);
        $this->veritabanlari = ['erp', 'postgres', (string) $kayit->oncekiVeritabani];
        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));

        // b) Ad değişikliği olmamış: geri sarılır
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::YerDegistirme, true, kimlik: '8e1d5a4f-3c2b-4d6e-9f0a-1b2c3d4e5f60');
        $this->bakimDosyasiYaz($kayit->kimlik);
        $this->veritabanlari = ['erp', 'postgres', (string) $kayit->hedefVeritabani];
        $this->baglantiyaKapali = ['erp' => true];
        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());
        $this->assertSame(UstuneGeriYuklemeAdimi::Basarisiz->value, $this->durumOku()?->adim);
        $this->assertSame(['erp', 'postgres'], $this->veritabanlari);
        $this->assertSame([], $this->baglantiyaKapali);
    }

    public function test_adlar_tutarsizsa_hicbir_sey_yapilmaz_yoneticiye_birakilir(): void
    {
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::YerDegistirme, true);
        $this->bakimDosyasiYaz($kayit->kimlik);
        // Hem hedef hem önceki ad var: hangi tarafta kalındığı bilinmiyor
        $this->veritabanlari = ['erp', 'postgres', (string) $kayit->hedefVeritabani, (string) $kayit->oncekiVeritabani];

        app(UstuneGeriYukleyici::class)->yaridaKalaniIsle();
        $son = $this->durumOku();

        $this->assertSame(UstuneGeriYuklemeAdimi::YerDegistirme->value, $son?->adim);
        $this->assertSame(GeriYuklemeHatasi::ADLAR_TUTARSIZ, $son->hata['kod']);
        $this->assertTrue($son->acik);
        $this->assertCount(4, $this->veritabanlari);
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));

        // Kararı yöneticiye bırakılmış işlem bir daha otomatik sürdürülmez
        $this->komutlar = [];
        $this->assertFalse(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());
        $this->assertSame([], $this->komutlar);
    }

    /**
     * @return array<string, array{0: string, 1: array{kod: string, mesaj: string}|null}>
     */
    public static function surdurulmeyenler(): array
    {
        return [
            'onay bekliyor' => [UstuneGeriYuklemeAdimi::OnayBekliyor->value, null],
            'yer değiştirmeden sonra hata' => [UstuneGeriYuklemeAdimi::AyarUzlastirma->value, ['kod' => GeriYuklemeHatasi::AYAR_UZLASTIRILAMADI, 'mesaj' => 'x']],
            'kapanmış işlem' => [UstuneGeriYuklemeAdimi::Onaylandi->value, null],
            'bu sürümün bilmediği adım' => ['ileride_eklenen_adim', null],
        ];
    }

    /**
     * @param  array{kod: string, mesaj: string}|null  $hata
     */
    #[DataProvider('surdurulmeyenler')]
    public function test_onay_bekleyen_ya_da_karari_yoneticide_olan_islem_surdurulmez(string $adim, ?array $hata): void
    {
        $this->durumYaz($adim, $adim !== UstuneGeriYuklemeAdimi::Onaylandi->value, $hata);

        $this->assertFalse(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());
        $this->assertSame([], $this->komutlar);
        $this->assertSame($adim, $this->durumOku()?->adim);
    }

    public function test_dizin_kurmada_olen_islem_devam_eder_onaya_gelir(): void
    {
        $yedek = $this->ustuneYedekAl();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::DizinKurma, true, yedekKimligi: $yedek->kimlik, isteyen: $this->yonetici->id);
        $this->bakimDosyasiYaz($kayit->kimlik);
        $this->veritabanlari = ['erp', 'postgres', (string) $kayit->oncekiVeritabani];

        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());

        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        $this->assertSame(GeriYuklemeDurumu::OnayBekliyor, GeriYukleme::query()->where('kimlik', $kayit->kimlik)->firstOrFail()->durum);
        $this->assertNotContains('systemctl start emor-queue', $this->komutlar);
    }

    public function test_bakim_modu_dosyasi_baglantiysa_yazilmaz_hedef_dosya_degismez(): void
    {
        // Web kullanıcısının koyduğu bağlantı servis hesabının dosyasını göstersin (D5)
        $hedef = $this->yedekKoku.'/servisin-dosyasi';
        file_put_contents($hedef, 'GIZLI');
        symlink($hedef, storage_path('framework/down'));

        try {
            app(BakimModu::class)->gir('7d0c4f3e-2b1a-4c5d-8e9f-0a1b2c3d4e5f');
            $this->fail('Bağlantı olan bakım modu yoluna yazılmamalıydı.');
        } catch (GeriYuklemeHatasi $hata) {
            $this->assertSame(GeriYuklemeHatasi::BAKIM_MODU, $hata->kod);
        }

        $this->assertSame('GIZLI', file_get_contents($hedef));
        $this->assertTrue(is_link(storage_path('framework/down')));

        // Çıkarken de bağlantı izlenmez, silinmez
        try {
            app(BakimModu::class)->cik('7d0c4f3e-2b1a-4c5d-8e9f-0a1b2c3d4e5f');
            $this->fail('Bağlantı olan bakım modu yolu silinmemeliydi.');
        } catch (GeriYuklemeHatasi) {
            $this->assertSame('GIZLI', file_get_contents($hedef));
        }
    }

    public function test_bakim_modu_laravelin_okudugu_dosya_ve_baskasinin_dosyasi_kaldirilmaz(): void
    {
        $bakim = app(BakimModu::class);
        $bakim->gir('7d0c4f3e-2b1a-4c5d-8e9f-0a1b2c3d4e5f');

        $this->assertTrue($this->app->maintenanceMode()->active());
        $this->assertSame(503, $this->app->maintenanceMode()->data()['status']);
        $this->assertFileDoesNotExist(storage_path('framework/maintenance.php'));

        // Başka işlemin kimliğiyle kaldırılmaz
        $this->assertFalse($bakim->cik('8e1d5a4f-3c2b-4d6e-9f0a-1b2c3d4e5f60'));
        $this->assertTrue($this->app->maintenanceMode()->active());
        $this->assertTrue($bakim->cik('7d0c4f3e-2b1a-4c5d-8e9f-0a1b2c3d4e5f'));
        $this->assertFalse($this->app->maintenanceMode()->active());
    }
}
