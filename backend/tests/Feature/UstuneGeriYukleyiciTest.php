<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\YedeklemeMaili;
use App\Models\GeriYukleme;
use App\Models\User;
use App\Models\Yedek;
use App\Services\Yedekleme\AnlikGoruntu;
use App\Services\Yedekleme\BakimModu;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\KaynakVeritabani;
use App\Services\Yedekleme\ManifestDeposu;
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

    /** Canlının özgün bağlanma yetkileri: belirli bir role ayrıca CONNECT verilmiş */
    private const OZGUN = '{=c/erp,erp=CTc/erp,emor_inceleme=c/erp}';

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
        $this->veritabaniYetkileri['erp'] = self::OZGUN;
        // Geri yüklenen veritabanı yedeğin anındadır: bu işlemin geçmiş satırı da
        // öncesi yedeğin dizin kaydı da onda yoktur (SQLite'ta veritabanı aynıdır;
        // kanca canlıdaki gerçek durumu kurar — YF-15)
        $this->yerDegistirmeSonrasi = function (): void {
            GeriYukleme::query()->delete();
            Yedek::query()->where('kaynak', YedekKaynagi::GeriYuklemeOncesi->value)->delete();
        };

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertNull($this->kancaHatasi, 'Kanca çalışmadı: '.$this->kancaHatasi?->getMessage());
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $kayit->adim);
        $this->assertTrue($kayit->acik);
        $this->assertNull($kayit->hata);
        $hedef = (string) $kayit->hedefVeritabani;
        $onceki = (string) $kayit->oncekiVeritabani;
        $this->assertMatchesRegularExpression('/^erp_geri_\d{8}_\d{6}$/D', $hedef);
        $this->assertMatchesRegularExpression('/^erp_onceki_\d{8}_\d{6}$/D', $onceki);

        // Sıra: kuyruk durur → yazıcı bariyeri → öncesi yedek → yeni veritabanı → migration
        // → kapat → yer değiştir → eski veritabanına özgün yetkiler
        $this->assertSame([
            'systemctl stop emor-queue', 'bariyer erp', 'pg_dump', "olustur {$hedef}", 'migrate', 'kapat erp', "yer erp>{$onceki} {$hedef}>erp", "yetki {$onceki} ".self::OZGUN,
        ], $this->komutlar);
        // Geri yükleme öncesi yedek bakım modundan SONRA alındı (P3)
        $this->assertSame([true], $this->pgDumpBakimda);
        // Özgün yetkiler kurtarma bağlamıyla durum dosyasında (BY-028-R1)
        $this->assertSame(['baglanma_yetkileri' => self::OZGUN], $kayit->bariyer);

        // Eski veritabanı önceki adıyla, özgün yetkileriyle ve bağlantıya kapalı duruyor; yeni canlı açık
        $this->assertContains($onceki, $this->veritabanlari);
        $this->assertNotContains($hedef, $this->veritabanlari);
        $this->assertSame([$onceki => true], $this->baglantiyaKapali);
        $this->assertSame(self::OZGUN, $this->veritabaniYetkileri[$onceki]);

        // Bakım modu sürüyor, kuyruk başlatılmadı
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));
        $this->assertNotContains('systemctl start emor-queue', $this->komutlar);

        // Geçmiş kaydı dosyadan yeniden yazıldı (satır yoktu: ileri akışın adımları
        // dosyadan kurulur); öncesi yedek klasörden kurulan dizin kaydına bağlı
        $geri = GeriYukleme::query()->where('kimlik', $kayit->kimlik)->firstOrFail();
        $this->assertSame(GeriYuklemeTuru::Ustune, $geri->tur);
        $this->assertSame(GeriYuklemeDurumu::OnayBekliyor, $geri->durum);
        $this->assertSame($onceki, $geri->onceki_veritabani);
        $this->assertSame(['adim' => UstuneGeriYuklemeAdimi::OnDenetim->value, 'durum' => 'tamam'], array_intersect_key($geri->adimlar[0] ?? [], ['adim' => 1, 'durum' => 1]));
        $oncesi = Yedek::query()->findOrFail($geri->getAttribute('oncesi_yedek_id'));
        $this->assertSame(YedekKaynagi::GeriYuklemeOncesi, $oncesi->kaynak);
        $this->assertTrue($oncesi->imzali);

        // YF-40: bağlantı sayımı ve sonlandırması yalnız istemci oturumlarında
        // (otomatik vakum işçisi ve diğer arka uçlar sayılmaz, sinyallenmez)
        Process::assertDidntRun(fn ($p): bool => str_contains((string) $p->input, 'pg_stat_activity') && ! str_contains((string) $p->input, "backend_type = 'client backend'"));
        $this->assertTrue($this->psqlCalisti('ALLOW_CONNECTIONS false', 'erp'));

        $this->assertIsArray(app(OnayBilgisi::class)->bilgiOku($kayit->kimlik));
        Mail::assertSent(YedeklemeMaili::class, 2);
    }

    /**
     * YF-13: anahtar değiştirilip eskisi APP_PREVIOUS_KEYS'e alındıysa eski
     * yedekteki şifreli alanlar çözülür; parmak izi taşımayan manifest (anahtar
     * tanımsızken alınmış) denetlenemez, geri yüklemeyi engellemez.
     *
     * @return array<string, array{0: string}>
     */
    public static function appKeyKabulu(): array
    {
        return [
            'yedeği alan anahtar önceki anahtarlarda' => ['onceki'],
            'manifestte parmak izi yok' => ['izsiz'],
        ];
    }

    #[DataProvider('appKeyKabulu')]
    public function test_onceki_anahtarla_ya_da_parmak_izsiz_alinmis_yedek_on_denetimden_gecer(string $durum): void
    {
        $eski = (string) config('app.key');
        if ($durum === 'izsiz') {
            config(['app.key' => null]);
        }
        $yedek = $this->ustuneYedekAl();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => $durum === 'onceki' ? [$eski] : []]);

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $kayit->adim, (string) json_encode($kayit->hata));
    }

    /**
     * BY-028-R1: öncesi yedeğin anlık görüntüsü bariyerden sonra açılır —
     * CONNECT yalnız servis rolünde olduğu için bağlantı servis rolüyle ve
     * parola dosyasıyla; görüntü datacl'yi daraltılmış okur ama manifest
     * ÖZGÜN yetkileri taşır (o yedekten geri dönüş doğru yetkilerle açılsın).
     */
    public function test_oncesi_yedek_bariyerden_sonra_servis_roluyle_alinir_manifesti_ozgun_yetkileri_tasir(): void
    {
        $yedek = $this->ustuneYedekAl();
        $this->veritabaniYetkileri['erp'] = self::OZGUN;
        $gorulen = [];
        $goruntu = function () use (&$gorulen): AnlikGoruntu {
            $gorulen[] = [
                'kullanici' => config('database.connections.yedek_test.username'),
                'parola' => config('database.connections.yedek_test.password'),
                'pgpassfile' => getenv('PGPASSFILE'),
                'yetkiler' => $this->veritabaniYetkileri['erp'] ?? null,
            ];

            return new AnlikGoruntu(
                kimlik: '00000003-0000001B-1',
                satirSayilari: $this->kaynakVeritabani->satirSayilari,
                migrationlar: $this->kaynakVeritabani->migrationlar,
                // Gerçek görüntü datacl'yi o anki (daraltılmış) hâliyle okur
                ozellikler: ['kodlama' => 'UTF8', 'siralama' => 'en_US.UTF-8', 'karakter_turu' => 'en_US.UTF-8', 'ayarlar' => [], 'baglanma_yetkileri' => $this->veritabaniYetkileri['erp'] ?? null],
                sahipRol: 'erp',
                sunucuSurumu: '18.6',
            );
        };
        $this->app->instance(KaynakVeritabani::class, new class($goruntu) implements KaynakVeritabani
        {
            public function __construct(private readonly \Closure $goruntu) {}

            public function anlikGoruntuyle(\Closure $is): mixed
            {
                return $is(($this->goruntu)());
            }
        });
        $pgpassOnce = getenv('PGPASSFILE');

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $kayit->adim);
        $this->assertSame([[
            'kullanici' => 'emor_yedek', 'parola' => null, 'pgpassfile' => config('yedekleme.parola_dosyasi'), 'yetkiler' => '{emor_yedek=c/erp}',
        ]], $gorulen);
        // Bağlantı tanımı ve ortam eski hâline döndü
        $this->assertSame('erp', config('database.connections.yedek_test.username'));
        $this->assertSame('gizli:parola\\1', config('database.connections.yedek_test.password'));
        $this->assertSame($pgpassOnce, getenv('PGPASSFILE'));

        $oncesi = Yedek::query()->where('kaynak', YedekKaynagi::GeriYuklemeOncesi->value)->firstOrFail();
        $manifest = app(ManifestDeposu::class)->oku(ManifestDeposu::jsonYolu(config('yedekleme.klasor').'/'.$oncesi->dosya_adi))->manifest;
        $this->assertSame(self::OZGUN, $manifest?->veritabaniOzellikleri['baglanma_yetkileri']);
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
            'servis rolü uygulama rolüyle aynı (bariyer kurulamaz)' => ['rol', GeriYuklemeHatasi::SERVIS_ROLU_GEREKLI],
            'servis rolünün parola dosyası yok' => ['parola', GeriYuklemeHatasi::SERVIS_ROLU_GEREKLI],
            'uygulama zaten bakım modunda' => ['bakim', GeriYuklemeHatasi::BAKIM_MODU],
            'hedef ad zaten var' => ['hedef', GeriYuklemeHatasi::HEDEF_VAR],
            // YF-13: şifreli alanlar bugünkü anahtarlarla çözülemezdi
            'yedek bugünkü ve önceki APP_KEY dışındaki anahtarla alınmış' => ['app_key', GeriYuklemeHatasi::APP_KEY_UYUMSUZ],
            // YF-14: imzalı manifestten bilinen retler kesintiden önce
            'manifestte liste türü veritabanı ayarı' => ['liste_ayari', GeriYuklemeHatasi::VERITABANI_OZELLIGI],
            'yedek sunucudan yeni araçla alınmış' => ['sunucu_eski', GeriYuklemeHatasi::PG_SURUMU],
            'yedek pg_restore\'dan yeni araçla alınmış' => ['pg_restore_eski', GeriYuklemeHatasi::PG_SURUMU],
            'manifest yetkisindeki rol artık yok' => ['acl_rolu_yok', GeriYuklemeHatasi::VERITABANI_YETKILERI],
            // YF-45: canlının kimliğine bağlı özellikleri ad değiştirmeyle kaybolurdu
            'canlıda rol düzeyi veritabanı ayarı' => ['rol_ayari', GeriYuklemeHatasi::VERITABANI_OZELLIGI],
            'canlıda bağlantı sınırı' => ['baglanti_siniri', GeriYuklemeHatasi::VERITABANI_OZELLIGI],
            'canlıda libc dışı sıralama sağlayıcısı' => ['icu', GeriYuklemeHatasi::VERITABANI_OZELLIGI],
        ];
    }

    #[DataProvider('onDenetimReddi')]
    public function test_on_denetim_reddinde_hicbir_sey_degismez(string $durum, string $kod): void
    {
        // Manifeste yazılanlar yedekten önce kurulur
        match ($durum) {
            'liste_ayari' => $this->kaynakVeritabani->ozellikler['ayarlar'] = ['search_path=public'],
            'acl_rolu_yok' => $this->kaynakVeritabani->ozellikler['baglanma_yetkileri'] = '{erp=CTc/erp,emekli_rol=c/erp}',
            default => null,
        };
        $yedek = $this->ustuneYedekAl();
        $onay = 'erp';
        match ($durum) {
            'onay' => $onay = 'ERP',
            'servis' => config(['yedekleme.calistirici' => 'dogrudan']),
            'rol' => config(['yedekleme.rol' => 'erp']),
            'parola' => config(['yedekleme.parola_dosyasi' => null]),
            'bakim' => file_put_contents(storage_path('framework/down'), '{"status":503}'),
            'hedef' => $this->veritabanlari[] = 'erp_onceki_'.now('Europe/Istanbul')->format('Ymd_His'),
            'app_key' => config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => ['base64:'.base64_encode(random_bytes(32))]]),
            'sunucu_eski' => $this->sunucuSurumNo = '170004',
            'pg_restore_eski' => $this->sahteUstune(['*pg_restore*--version*' => 'pg_restore (PostgreSQL) 17.2']),
            'rol_ayari' => $this->canliOzellikleri = "1,-1,c\n",
            'baglanti_siniri' => $this->canliOzellikleri = "0,20,c\n",
            'icu' => $this->canliOzellikleri = "0,-1,i\n",
            default => null,
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
            'yazıcı bariyeri' => ['bariyer', GeriYuklemeHatasi::YAZICI_BARIYERI],
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
        $this->veritabaniYetkileri['erp'] = self::OZGUN;
        match ($hata) {
            'kuyruk' => $this->sudoHatasi = 'sudo: a password is required',
            'bariyer' => $this->bariyerHatasi = 'ERROR:  permission denied to terminate process',
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
        // Bariyer kurulduysa canlının özgün yetkileri aynen geri kondu (BY-028-R1)
        $this->assertSame(self::OZGUN, $this->veritabaniYetkileri['erp']);
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
            'yazıcı bariyeri' => [UstuneGeriYuklemeAdimi::YaziciBariyeri, false],
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
        // Bakım modu adımında bariyer henüz kurulmamıştır; sonrasında durum dosyası
        // özgün yetkileri taşır, canlı daraltılmış hâldedir
        $bariyerVar = $adim !== UstuneGeriYuklemeAdimi::BakimModu;
        $kayit = $this->durumYaz($adim, true, bariyer: $bariyerVar ? ['baglanma_yetkileri' => self::OZGUN] : null);
        $this->veritabaniYetkileri['erp'] = $bariyerVar ? '{emor_yedek=c/erp}' : self::OZGUN;
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
        $this->assertSame(self::OZGUN, $this->veritabaniYetkileri['erp']);
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
     * YF-41: 6. adımda bağlantılar kapatılamadı ve adlar da OKUNAMADI
     * (PostgreSQL o an erişilemiyor). Bu "adlar tutarsız" değildir: adım
     * değişmez, çalıştırıcı sonraki turda yeniden dener; PostgreSQL dönünce
     * geri sarılır ve uygulama açılır.
     */
    public function test_adlar_okunamazsa_karar_yoneticiye_kilitlenmez_sonraki_turda_geri_sarilir(): void
    {
        $yedek = $this->ustuneYedekAl();
        $this->kapatmaHatasi = 'psql: error: connection to server failed';
        $erisilemez = true;
        // Sunucu bağlantı kapatma denemesiyle birlikte erişilemez oldu (ön denetim okuyabilmişti)
        $this->sahteUstune(['*psql*' => function ($p) use (&$erisilemez) {
            if ($erisilemez && in_array('kapat erp', $this->komutlar, true) && str_contains((string) $p->input, 'datname IN')) {
                return Process::result(errorOutput: 'psql: error: connection to server failed', exitCode: 2);
            }

            return $this->psqlUstune($p);
        }]);

        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);

        $this->assertSame(UstuneGeriYuklemeAdimi::BaglantiKapatma->value, $kayit->adim);
        $this->assertSame(GeriYuklemeHatasi::ADLAR_OKUNAMADI, $kayit->hata['kod'] ?? null);
        $this->assertTrue($kayit->acik);
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));
        $this->assertContains($kayit->hedefVeritabani, $this->veritabanlari);

        // PostgreSQL döndü: çalıştırıcının sonraki turu geri sarar
        $erisilemez = false;
        $this->kapatmaHatasi = null;
        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());

        $son = $this->durumOku();
        $this->assertSame(UstuneGeriYuklemeAdimi::Basarisiz->value, $son?->adim);
        $this->assertFalse($son->acik);
        $this->assertSame(['erp', 'postgres'], $this->veritabanlari);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertContains('systemctl start emor-queue', $this->komutlar);
    }

    /**
     * YF-15: onay ya da geri dönüş sürerken ölen işlem çalıştırıcıda tamamlanır.
     */
    public function test_onaylanirken_olen_islem_onayi_tamamlar_uygulamayi_acar(): void
    {
        $yedek = $this->ustuneYedekAl();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::Onaylaniyor, true, yedekKimligi: $yedek->kimlik, isteyen: $this->yonetici->id, bariyer: ['baglanma_yetkileri' => self::OZGUN]);
        $this->bakimDosyasiYaz($kayit->kimlik);
        $this->veritabanlari = ['erp', 'postgres', (string) $kayit->oncekiVeritabani];
        $this->baglantiyaKapali = [(string) $kayit->oncekiVeritabani => true];

        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());

        $son = $this->durumOku();
        $this->assertSame(UstuneGeriYuklemeAdimi::Onaylandi->value, $son?->adim);
        $this->assertFalse($son->acik);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertContains('systemctl start emor-queue', $this->komutlar);
        // Adlara dokunulmadı; eski veritabanı özgün yetkileriyle ve kapalı bekliyor
        $this->assertSame(['erp', 'postgres', (string) $kayit->oncekiVeritabani], $this->veritabanlari);
        $this->assertSame([(string) $kayit->oncekiVeritabani => true], $this->baglantiyaKapali);
        $this->assertSame(self::OZGUN, $this->veritabaniYetkileri[(string) $kayit->oncekiVeritabani]);
        $this->assertSame(GeriYuklemeDurumu::Tamam, GeriYukleme::query()->where('kimlik', $kayit->kimlik)->firstOrFail()->durum);
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function geriDonusAdlari(): array
    {
        return [
            'adlar hâlâ yer değişmiş (ad değiştirmeden önce öldü)' => [true],
            'adlar geri çevrilmiş (açmadan önce öldü)' => [false],
        ];
    }

    #[DataProvider('geriDonusAdlari')]
    public function test_geri_donulurken_olen_islem_adlara_gore_geri_donusu_tamamlar(bool $yerDegismis): void
    {
        $yedek = $this->ustuneYedekAl();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::GeriDonuluyor, true, yedekKimligi: $yedek->kimlik, isteyen: $this->yonetici->id, bariyer: ['baglanma_yetkileri' => self::OZGUN]);
        $this->bakimDosyasiYaz($kayit->kimlik);
        $hedef = (string) $kayit->hedefVeritabani;
        $onceki = (string) $kayit->oncekiVeritabani;
        $this->veritabanlari = ['erp', 'postgres', $yerDegismis ? $onceki : $hedef];
        $this->baglantiyaKapali = $yerDegismis ? [$onceki => true] : ['erp' => true];

        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());

        $this->assertSame(UstuneGeriYuklemeAdimi::GeriDonuldu->value, $this->durumOku()?->adim);
        // erp yeniden eski veritabanı, açık ve özgün yetkileriyle; geri yüklenen kopya silinmeden kapalı
        $this->assertEqualsCanonicalizing(['erp', 'postgres', $hedef], $this->veritabanlari);
        $this->assertSame($yerDegismis ? [$hedef => true] : [], $this->baglantiyaKapali);
        $this->assertSame(self::OZGUN, $this->veritabaniYetkileri['erp']);
        $this->assertSame($yerDegismis, in_array("yer erp>{$hedef} {$onceki}>erp", $this->komutlar, true));
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertContains('systemctl start emor-queue', $this->komutlar);
    }

    /**
     * YF-15: geri sarmanın kendisi düşerse işlem açık kalır (bakım modu
     * sürer); sorun geçince çalıştırıcının sonraki turu geri sarmayı bitirir.
     */
    public function test_geri_sarma_hatasi_islemi_acik_birakir_sonraki_turda_tamamlanir(): void
    {
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::PgRestore, true, bariyer: ['baglanma_yetkileri' => self::OZGUN]);
        $this->veritabaniYetkileri['erp'] = '{emor_yedek=c/erp}';
        $this->bakimDosyasiYaz($kayit->kimlik);
        $this->veritabanlari[] = (string) $kayit->hedefVeritabani;
        $this->silmeHatasi = 'ERROR:  database is being accessed by other users';

        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());

        $ara = $this->durumOku();
        $this->assertSame(UstuneGeriYuklemeAdimi::GeriSariliyor->value, $ara?->adim);
        $this->assertSame(GeriYuklemeHatasi::VERITABANI_SILINEMEDI, $ara->hata['kod'] ?? null);
        $this->assertTrue($ara->acik);
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));
        $this->assertContains($kayit->hedefVeritabani, $this->veritabanlari);

        $this->silmeHatasi = null;
        $this->assertTrue(app(UstuneGeriYukleyici::class)->yaridaKalaniIsle());

        $son = $this->durumOku();
        $this->assertSame(UstuneGeriYuklemeAdimi::Basarisiz->value, $son?->adim);
        $this->assertFalse($son->acik);
        $this->assertSame(['erp', 'postgres'], $this->veritabanlari);
        $this->assertSame(self::OZGUN, $this->veritabaniYetkileri['erp']);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
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
