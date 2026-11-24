<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailAyari;
use App\Models\Rol;
use App\Models\User;
use App\Models\YedekAyari;
use App\Models\YedekIslemKaydi;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\AyarSecimi;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-018: Yedekleme Ayarları ve İşlem Günlüğü API'si (README §4.9, §4.3, §4.8).
 */
final class YedekAyariApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const K = '/api/v1/ayarlar';

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true, 'yedekleme.calistirici' => 'dogrudan']);
        $this->yonetici = User::factory()->yonetici()->create(['ad' => 'Ayşe Yönetici']);
        $this->actingAs($this->yonetici);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $degisen
     * @return array<string, mixed>
     */
    private function govde(array $degisen = []): array
    {
        return [
            'yayin_oncesi_son_adet' => 20, 'yayin_oncesi_gun' => 30, 'elle_gun' => 30, 'geri_yukleme_oncesi_gun' => 30,
            'enaz_adet' => 3, 'enaz_bos_alan_yuzde' => 15, 'bildirim_alicilari' => ['bt@ornek.test'],
            'sinyal_yerel_adresi' => 'https://izleme.ornek.test/ping/yerel', 'sinyal_uzak_adresi' => null, 'sinyal_sinama_adresi' => null,
            ...$degisen,
        ];
    }

    public function test_ayar_degisikligi_denetim_kaydina_farkla_ve_ayar_dosya_kopyasina_yazilir(): void
    {
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))->assertOk()->assertJsonPath('data.elle_gun', 45);

        $kayit = YedekIslemKaydi::query()->sole();
        $this->assertSame('ayar_guncelle', $kayit->islem);
        $this->assertSame($this->yonetici->id, $kayit->kullanici_id);
        $this->assertSame(['once' => 30, 'sonra' => 45], $kayit->ayrinti['elle_gun']);
        $this->assertArrayNotHasKey('yayin_oncesi_gun', $kayit->ayrinti);

        $kopya = app(AyarKopyasi::class)->oku();
        $this->assertNotNull($kopya);
        $this->assertSame(45, YedekAyari::guncel()->elle_gun);
        $this->assertStringContainsString('"elle_gun":45', (string) json_encode($kopya));
    }

    /**
     * YF-12 (T6): denetim kaydının veritabanı yazımı GERÇEK bir sorgu hatasıyla
     * düşerse ayar yine kaydedilir (denetim dosyada kalır). PostgreSQL'de hata
     * savepoint'siz işlemi zehirleyip değişikliği sessizce geri alırdı; bu yüzden
     * test PostgreSQL'de de koşulmalı (SQLite hatalı ifadeden sonra işlemi sürdürür).
     */
    public function test_denetim_kaydi_veritabanina_yazilamazsa_ayar_yine_kaydedilir(): void
    {
        Schema::rename('yedek_islem_kayitlari', 'yedek_islem_kayitlari_gecici');

        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))->assertOk();

        $this->assertSame(45, DB::table('yedek_ayarlari')->value('elle_gun'));
    }

    public function test_servis_kipinde_web_kopyayi_yazmaz_calistirici_turu_degisince_yazar(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        $kopya = app(AyarKopyasi::class);

        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))->assertOk();
        $this->assertNull($kopya->oku());

        $this->artisan('yedek:calistir', ['--tek-tur' => true])->assertSuccessful();
        $this->assertSame(45, $kopya->oku()['yedek_ayarlari']['elle_gun']);

        // Değişiklik yoksa yeniden yazılmaz; mail parolası değişince yazılır
        $this->assertFalse($kopya->guncelDegilseYaz());
        MailAyari::query()->create(['anahtar' => MailAyari::ANAHTAR, 'sunucu' => 'smtp.ornek.test', 'port' => 587, 'sifreleme' => 'tls', 'kullanici_adi' => 'u', 'sifre' => 'eski', 'gonderen_adres' => 'a@ornek.test', 'gonderen_ad' => 'eMOR']);
        $this->assertTrue($kopya->guncelDegilseYaz());
        MailAyari::query()->firstOrFail()->update(['sifre' => 'yeni']);
        $this->assertTrue($kopya->guncelDegilseYaz());
        $this->assertSame('yeni', $kopya->mailAyari()?->sifre);
        $this->assertFalse($kopya->guncelDegilseYaz());
    }

    public function test_acik_ustune_geri_yuklemede_calistirici_ayar_kopyasini_ezmez(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        $kilit = $this->kilitAl();
        app(YedeklemeYapilandirmasi::class)->islemDurumu()->yaz(new IslemKaydi(
            kimlik: (string) Str::uuid(), tur: IslemTuru::GeriYuklemeUstune, adim: 'onay_bekliyor', acik: true,
            baslangic: CarbonImmutable::now('UTC'), sonGuncelleme: CarbonImmutable::now('UTC'), hata: null, erisimAnahtariOzeti: null,
        ), $kilit);
        $kilit->birak();
        $this->tutulanKilit = null;
        YedekAyari::query()->create(['elle_gun' => 99]);

        $this->artisan('yedek:calistir', ['--tek-tur' => true]);

        $this->assertNull(app(AyarKopyasi::class)->oku());
    }

    public function test_imza_anahtari_yoksa_ayar_veritabanina_ve_kopyaya_yazilmaz_409(): void
    {
        YedekAyari::query()->create(['elle_gun' => 30]);
        @mkdir(dirname(config('yedekleme.ayar_kopyasi')), 0750, true);
        file_put_contents(config('yedekleme.ayar_kopyasi'), 'ESKI-KOPYA');
        unlink(config('yedekleme.imza_anahtari'));

        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))
            ->assertStatus(409)->assertJsonPath('kod', 'YEDEK_IMZA_ANAHTARI_YOK');

        $this->assertSame(30, YedekAyari::guncel()->elle_gun);
        $this->assertSame('ESKI-KOPYA', file_get_contents(config('yedekleme.ayar_kopyasi')));
        $this->assertSame(0, YedekIslemKaydi::query()->count());
    }

    private function rootDegilse(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root klasör iznini aşar (T11).');
        }
    }

    /**
     * BY-018-R2: dosya yalnız commit olmuş ayarlardan. Commit anında dosya
     * hâlâ eski; commit düşerse hiç yazılmaz, eski baytlarıyla kalır.
     * (Test işlemi iç içe olduğundan gerçek geri alma burada kurulamaz:
     * commit anı `TransactionCommitted` olayıdır, hata oradan fırlatılır.)
     */
    public function test_commit_aninda_ayar_dosyasi_eski_commit_duserse_eski_baytlariyla_kalir(): void
    {
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 40]))->assertOk();
        $yol = config('yedekleme.ayar_kopyasi');
        $onceki = (string) file_get_contents($yol);
        $commitAnindaki = null;
        Event::listen(TransactionCommitted::class, function () use ($yol, &$commitAnindaki): void {
            $commitAnindaki = (string) file_get_contents($yol);

            throw new RuntimeException('commit düştü');
        });

        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))->assertStatus(500);

        $this->assertSame($onceki, $commitAnindaki, 'dosya commit\'ten önce yazılmamalı');
        $this->assertSame($onceki, file_get_contents($yol));
    }

    /**
     * BY-018-R2: commit oldu, dosya yazılamadı (taşımadan önce): ayar
     * kaydedilmiştir, kopyanın geride olduğu izlenir; çalıştırıcının sonraki
     * turu dosyayı veritabanına getirir.
     */
    public function test_commit_sonrasi_kopya_yazilamazsa_ayar_kaydedilir_durum_izlenir_calistirici_uzlastirir(): void
    {
        $this->rootDegilse();
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 40]))->assertOk()->assertJsonPath('data.ayar_kopyasi_guncel', true);
        $yol = config('yedekleme.ayar_kopyasi');
        $onceki = (string) file_get_contents($yol);
        $klasor = dirname($yol);
        chmod($klasor, 0555);

        try {
            $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))
                ->assertOk()->assertJsonPath('data.elle_gun', 45)->assertJsonPath('data.ayar_kopyasi_guncel', false);
        } finally {
            chmod($klasor, 0750);
        }

        $this->assertSame(45, YedekAyari::guncel()->elle_gun);
        $this->assertSame($onceki, file_get_contents($yol));
        $this->assertSame([], array_values(array_diff(scandir($klasor) ?: [], ['.', '..', basename($yol), 'kilit'])), 'geçici dosya kalmamalı');
        $son = YedekIslemKaydi::query()->latest('id')->firstOrFail();
        $this->assertSame(['ayar_kopyasi', IslemSonucu::Basarisiz, 'AYAR_KOPYASI_YAZILAMADI', 'ayar_guncelle'], [$son->islem, $son->sonuc, $son->ayrinti['kod'], $son->ayrinti['tetikleyen']]);
        $this->getJson(self::K.'/yedek-ayarlari')->assertOk()->assertJsonPath('data.ayar_kopyasi_guncel', false);

        $this->artisan('yedek:calistir', ['--tek-tur' => true])->assertSuccessful();

        $this->assertSame(45, app(AyarKopyasi::class)->oku()['yedek_ayarlari']['elle_gun'] ?? null);
        $this->getJson(self::K.'/yedek-ayarlari')->assertOk()->assertJsonPath('data.ayar_kopyasi_guncel', true);
    }

    /**
     * BY-018-R2: taşımadan sonra klasör eşitlenemezse dosya veritabanıyla
     * aynıdır; kayıt başarılı, kalıcılığın doğrulanamadığı denetimde.
     */
    public function test_ayar_dosyasi_tasindiktan_sonra_klasor_esitlenemezse_kayit_basarili_kopya_guncel_not_dusulur(): void
    {
        $this->rootDegilse();
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 40]))->assertOk();
        $klasor = dirname(config('yedekleme.ayar_kopyasi'));
        // Yazılabilir, geçilebilir, okunamaz: taşıma olur, klasör eşitlemek için açılamaz
        chmod($klasor, 0300);

        try {
            $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))
                ->assertOk()->assertJsonPath('data.ayar_kopyasi_guncel', true);
        } finally {
            chmod($klasor, 0750);
        }

        $this->assertSame(45, app(AyarKopyasi::class)->oku()['yedek_ayarlari']['elle_gun'] ?? null);
        $son = YedekIslemKaydi::query()->latest('id')->firstOrFail();
        $this->assertSame(['ayar_kopyasi', IslemSonucu::Basarili, 'AYAR_KOPYASI_KALICILIGI_DOGRULANAMADI'], [$son->islem, $son->sonuc, $son->ayrinti['kod']]);
    }

    /**
     * BY-018-R3: veritabanındaki şifreli SMTP parolası çözülemiyor (bozuk değer,
     * APP_KEY değişmiş). Ayar kaydedilir; kopya üretilemez, bu denetimde ve
     * ekranda görünür; okuma ucu ve çalıştırıcı turu düşmez.
     */
    public function test_smtp_parolasi_cozulemezse_ayar_kaydedilir_kopya_guncel_degil_okuma_ve_calistirici_dusmez(): void
    {
        MailAyari::query()->create(['anahtar' => MailAyari::ANAHTAR, 'sunucu' => 'smtp.ornek.test', 'port' => 587, 'sifreleme' => 'tls', 'kullanici_adi' => 'u', 'sifre' => 'gizli', 'gonderen_adres' => 'a@ornek.test', 'gonderen_ad' => 'eMOR']);
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 40]))->assertOk()->assertJsonPath('data.ayar_kopyasi_guncel', true);
        $yol = config('yedekleme.ayar_kopyasi');
        $onceki = (string) file_get_contents($yol);
        MailAyari::query()->toBase()->update(['sifre' => 'bozuk-sifreli-deger']);

        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))
            ->assertOk()->assertJsonPath('data.elle_gun', 45)->assertJsonPath('data.ayar_kopyasi_guncel', false);

        $this->assertSame(45, YedekAyari::guncel()->elle_gun);
        $this->assertSame($onceki, file_get_contents($yol));
        $son = YedekIslemKaydi::query()->latest('id')->firstOrFail();
        $this->assertSame(['ayar_kopyasi', IslemSonucu::Basarisiz, 'AYAR_KOPYASI_AYAR_COZULEMEDI', 'ayar_guncelle'], [$son->islem, $son->sonuc, $son->ayrinti['kod'], $son->ayrinti['tetikleyen']]);
        $this->getJson(self::K.'/yedek-ayarlari')->assertOk()->assertJsonPath('data.ayar_kopyasi_guncel', false);

        $this->artisan('yedek:calistir', ['--tek-tur' => true])->assertSuccessful();
        $this->assertSame($onceki, file_get_contents($yol));

        // Onay ekranının karşılaştırması da düşmez: çözülemeyen parola "farklı" sayılır
        $mailGrubu = array_values(array_filter(app(AyarSecimi::class)->karsilastir()['gruplar'], fn (array $g): bool => $g['grup'] === 'mail'));
        $this->assertSame([['grup' => 'mail', 'farkli' => true]], $mailGrubu);
    }

    public function test_en_az_sayi_1in_altina_indirilemez_https_olmayan_sinyal_reddedilir(): void
    {
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['enaz_adet' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('enaz_adet', 'hatalar');
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['sinyal_uzak_adresi' => 'http://izleme.ornek.test/x']))
            ->assertStatus(422)->assertJsonValidationErrors('sinyal_uzak_adresi', 'hatalar');
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['bildirim_alicilari' => ['gecersiz']]))
            ->assertStatus(422)->assertJsonValidationErrors('bildirim_alicilari.0', 'hatalar');

        $this->assertSame(0, YedekIslemKaydi::query()->count());
    }

    public function test_ozel_anahtar_iceren_metin_reddedilir_kaydedilmez(): void
    {
        $this->putJson(self::K.'/yedek-ayarlari/sifreleme-anahtari', ['acik_anahtar' => "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nlQOYBF...\n-----END PGP PRIVATE KEY BLOCK-----"])
            ->assertStatus(422)->assertJsonPath('kod', 'YEDEK_ANAHTAR_OZEL');

        $this->assertNull(YedekAyari::guncel()->sifreleme_acik_anahtari);
    }

    public function test_islem_gunlugu_salt_okunur_suzgecle_listelenir(): void
    {
        $denetim = app(YedekDenetimi::class);
        $denetim->kaydet('yedek', IslemSonucu::Basarili, null, $this->yonetici->id, 1200);
        $denetim->kaydet('kopya', IslemSonucu::Basarisiz, null, null, 50, ['hedef' => 'FTP'], 'ERISILEMEDI');

        $this->getJson(self::K.'/yedek-islem-kayitlari?sonuc=basarisiz')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.islem', 'kopya')->assertJsonPath('data.0.ayrinti.hedef', 'FTP');
        $this->getJson(self::K.'/yedek-islem-kayitlari?kullanici_id='.$this->yonetici->id)->assertOk()
            ->assertJsonPath('data.0.kullanici', 'Ayşe Yönetici')->assertJsonPath('meta.total', 1);

        // Değiştirme / silme ucu yok
        foreach (['postJson', 'putJson', 'deleteJson'] as $yontem) {
            $this->{$yontem}(self::K.'/yedek-islem-kayitlari')->assertStatus(405);
        }
        $this->assertSame(2, YedekIslemKaydi::query()->count());
    }

    /**
     * YF-30 (D2): serbest hata metni (pg_dump stderr: yol, rol) yalnız sistem
     * yöneticisine döner; yaşam sinyali adresi denetim farkına değerle yazılmaz.
     */
    public function test_islem_gunlugu_hata_metnini_yalniz_yoneticiye_verir_sinyal_adresini_yazmaz(): void
    {
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['sinyal_uzak_adresi' => 'https://izleme.ornek.test/ping/gizli-uzak']))->assertOk();
        app(YedekDenetimi::class)->kaydet('yedek_al', IslemSonucu::Basarisiz, null, null, null, ['kod' => 'YEDEK_PG_DUMP'], 'pg_dump: /var/lib/emor-yedek/yedekler: connection to emor_yedek@db failed');
        $izinli = User::factory()->create();
        $rol = Rol::query()->create(['ad' => 'Yedek izleyici']);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'yedekler.goruntule']);
        $izinli->roller()->attach($rol);

        $kayitlar = collect($this->actingAs($izinli)->getJson(self::K.'/yedek-islem-kayitlari')->assertOk()->json('data'))->keyBy('islem');

        $this->assertNull($kayitlar['yedek_al']['hata_mesaji']);
        $this->assertSame('YEDEK_PG_DUMP', $kayitlar['yedek_al']['ayrinti']['kod']);
        $this->assertSame(['degisti' => true], $kayitlar['ayar_guncelle']['ayrinti']['sinyal_uzak_adresi']);
        $this->actingAs($this->yonetici)->getJson(self::K.'/yedek-islem-kayitlari?sonuc=basarisiz')->assertOk()
            ->assertJsonPath('data.0.hata_mesaji', 'pg_dump: /var/lib/emor-yedek/yedekler: connection to emor_yedek@db failed');
    }
}
