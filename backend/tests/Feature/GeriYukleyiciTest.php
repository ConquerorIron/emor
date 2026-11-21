<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\YedeklemeMaili;
use App\Models\GeriYukleme;
use App\Models\MailAyari;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\GeriYuklemeAdimi;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\GeriYuklemeVeritabani;
use App\Services\Yedekleme\GeriYukleyici;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KopyaVeritabanlari;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use App\Services\Yedekleme\YedekManifesti;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\SahtePostgres;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-026: farklı ada geri yükleme ve sınama geri yüklemesi (README §4.4,
 * §4.5-A, §6.3). PostgreSQL araçları sahte (Process::fake); gerçek
 * PostgreSQL'deki deneme teslim günlüğünde.
 */
final class GeriYukleyiciTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SahtePostgres;
    use YedeklemeOrtami;

    private const AD = 'erp_geri_20261121_103000';

    private YedekKilidi $kilit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        Mail::fake();
        Http::preventStrayRequests();
        MailAyari::query()->create([
            'anahtar' => MailAyari::ANAHTAR, 'sunucu' => 'smtp.ornek.test', 'port' => 587, 'sifreleme' => MailAyari::SIFRELEME_TLS,
            'kullanici_adi' => 'yedek@ornek.test', 'sifre' => 'smtp-gizli', 'gonderen_adres' => 'erp@ornek.test', 'gonderen_ad' => 'eMOR',
        ]);
        YedekAyari::query()->create(['bildirim_alicilari' => ['bt@ornek.test']]);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 10:30:00', 'Europe/Istanbul'));
        $this->sahtePostgres();
        $this->kilit = $this->kilitAl();
    }

    protected function tearDown(): void
    {
        BellekHedefi::sifirla();
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function yedekAl(): Yedek
    {
        $yedek = app(YedekAlici::class)->al($this->kilit, YedekKaynagi::Elle);
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);

        return $yedek;
    }

    /**
     * Elle kurulan imzalı manifest (içe alınmış eski yedek, veritabanı ayarı).
     *
     * @param  array{kodlama: string, siralama: string, karakter_turu: string, ayarlar: list<string>, baglanma_yetkileri: string|null}|null  $ozellikler
     */
    private function manifestliYedek(?array $ozellikler, bool $iceAlindi = false): Yedek
    {
        $klasor = (string) config('yedekleme.klasor');
        @mkdir($klasor, 0750, true);
        $ad = 'erp_yayin_oncesi_2026-11-20_100000+0300.dump';
        file_put_contents($klasor.'/'.$ad, 'PGDMP-eski-yedek');
        $kimlik = (string) Str::uuid();

        app(ManifestDeposu::class)->yaz(new YedekManifesti(
            kimlik: $kimlik,
            alinmaZamani: CarbonImmutable::parse('2026-11-20 10:00:00', 'Europe/Istanbul'),
            kaynak: YedekKaynagi::YayinOncesi,
            planAdlari: [],
            veritabani: 'erp',
            dosyaAdi: $ad,
            boyut: (int) filesize($klasor.'/'.$ad),
            sha256: (string) hash_file('sha256', $klasor.'/'.$ad),
            pgSunucuSurumu: null,
            pgDumpSurumu: null,
            uygulamaSurumu: null,
            migrationlar: $iceAlindi ? null : $this->kaynakVeritabani->migrationlar,
            satirSayilari: $iceAlindi ? null : $this->kaynakVeritabani->satirSayilari,
            verisiAlinmayanTablolar: [],
            veritabaniOzellikleri: $ozellikler,
            sahipRol: 'erp',
            sifreli: false,
            appKeyParmakIzi: null,
            aciklama: null,
            alanKullaniciId: null,
            alanKullanici: null,
            iceAlindi: $iceAlindi,
        ), $klasor);

        return Yedek::factory()->create([
            'kimlik' => $kimlik, 'dosya_adi' => $ad, 'kaynak' => YedekKaynagi::YayinOncesi, 'ice_alindi' => $iceAlindi,
            'boyut' => filesize($klasor.'/'.$ad), 'sha256' => hash_file('sha256', $klasor.'/'.$ad),
        ]);
    }

    public function test_kopya_manifestteki_ozelliklerle_uygulama_rolune_acilir_yuklenir_ve_karsilastirilir(): void
    {
        $yedek = $this->yedekAl();

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeDurumu::Tamam, $geri->durum, (string) $geri->hata_mesaji);
        $this->assertSame(GeriYuklemeTuru::Kopya, $geri->tur);
        $this->assertSame(self::AD, $geri->hedef_veritabani);
        $this->assertNull($geri->veritabani_silinme_zamani);
        $this->assertContains(self::AD, $this->veritabanlari, 'Kopya beklemede kalır');

        // CREATE DATABASE: manifestteki kodlama / sıralama, sahibi uygulama rolü, yönetim veritabanından
        Process::assertRan(fn (PendingProcess $p): bool => str_contains((string) $p->input, "CREATE DATABASE :\"ad\" OWNER :\"sahip\" TEMPLATE template0 ENCODING :'kodlama' LC_COLLATE :'siralama' LC_CTYPE :'karakter_turu';")
            && in_array('--dbname=postgres', (array) $p->command, true)
            && in_array('--set=ON_ERROR_STOP=1', (array) $p->command, true)
            && $this->psqlDegiskenleri($p) === ['ad' => self::AD, 'sahip' => 'erp', 'kodlama' => 'UTF8', 'siralama' => 'en_US.UTF-8', 'karakter_turu' => 'en_US.UTF-8']);

        // pg_restore: tek işlem, ilk hatada dur, sahiplik uygulama rolünde; parola komut satırında değil
        $dosya = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        Process::assertRan(fn (PendingProcess $p): bool => $p->command === [
            '/usr/bin/pg_restore', '--exit-on-error', '--single-transaction', '--no-owner', '--role=erp',
            '--host=127.0.0.1', '--port=5432', '--username=erp', '--dbname='.self::AD, '--no-password', $dosya,
        ] && isset($p->environment['PGPASSFILE']) && ($p->environment['PGPASSWORD'] ?? null) === false);

        $this->assertTrue($this->psqlCalisti('query_to_xml'), 'Sayımlar geri yüklenen veritabanından okunur');
        $this->assertSame(['hazirlik', 'veritabani', 'pg_restore', 'karsilastirma'], array_values(array_unique(array_column($geri->adimlar ?? [], 'adim'))));
        $this->assertSame(1, YedekIslemKaydi::query()->where('islem', 'geri_yukle_kopya')->where('sonuc', 'basarili')->where('geri_yukleme_id', $geri->id)->count());

        $durum = app(YedeklemeYapilandirmasi::class)->islemDurumu()->oku();
        $this->assertSame(IslemTuru::GeriYuklemeKopya, $durum?->tur);
        $this->assertSame('bitti', $durum->adim);
        $this->assertSame(self::AD, $durum->hedefVeritabani);
        $this->assertSame($geri->kimlik, $durum->kimlik);
    }

    public function test_ek_kalip_disiysa_ad_uretilmez_islem_baslamaz(): void
    {
        $yedek = $this->yedekAl();

        foreach (['Buyuk', '../erp', 'a b', 'cok-uzun-bir-ek-12345', 'erp;drop', ''] as $ek) {
            try {
                app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, $ek, null);
                $this->fail("Kalıp dışı ek kabul edildi: {$ek}");
            } catch (GeriYuklemeHatasi $hata) {
                $this->assertSame(GeriYuklemeHatasi::HEDEF_ADI_GECERSIZ, $hata->kod);
            }
        }

        $this->assertSame(0, GeriYukleme::query()->count());
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));

        // Kalıba uyan ek adın sonuna eklenir
        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, 'inceleme1', null);
        $this->assertSame(self::AD.'_inceleme1', $geri->hedef_veritabani);
    }

    public function test_hedef_ad_mevcut_bir_veritabaniysa_islem_baslamaz_ona_dokunulmaz(): void
    {
        $yedek = $this->yedekAl();
        $this->veritabanlari[] = self::AD;

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeDurumu::Basarisiz, $geri->durum);
        $this->assertSame(GeriYuklemeHatasi::HEDEF_VAR, $geri->hata_kodu);
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));
        $this->assertFalse($this->psqlCalisti('DROP DATABASE'), 'Başkasının veritabanı silinmez');
        $this->assertContains(self::AD, $this->veritabanlari);
        $this->assertFalse($geri->veritabaniKalmisOlabilirMi(), 'Temizlik de ona dokunmaz');
    }

    public function test_imzasiz_yedek_geri_yuklenmez_ve_dogrulanmaz(): void
    {
        $yedek = Yedek::factory()->create(['imzali' => false]);

        foreach (['kopyaya', 'sina'] as $yontem) {
            try {
                app(GeriYukleyici::class)->{$yontem}($this->kilit, $yedek, ...($yontem === 'kopyaya' ? [null, null] : [null]));
                $this->fail("İmzasız yedek kabul edildi ({$yontem})");
            } catch (GeriYuklemeHatasi $hata) {
                $this->assertSame(GeriYuklemeHatasi::YEDEK_GERI_YUKLENEMEZ, $hata->kod);
            }
        }

        $this->assertSame(0, GeriYukleme::query()->count());
        Process::assertDidntRun(fn (PendingProcess $p): bool => str_contains(implode(' ', (array) $p->command), 'psql'));
    }

    public function test_dizinde_imzali_gorunse_de_manifest_imzasi_tutmayan_yedek_geri_yuklenmez(): void
    {
        $yedek = $this->yedekAl();
        // Web süreci veritabanını değiştirebilir, imza anahtarını okuyamaz: manifest dosyası belirleyicidir
        $json = ManifestDeposu::jsonYolu(config('yedekleme.klasor').'/'.$yedek->dosya_adi);
        $veri = json_decode((string) file_get_contents($json), true);
        $veri['manifest']['aciklama'] = 'değiştirildi';
        file_put_contents($json, json_encode($veri));

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertTrue($yedek->refresh()->imzali);
        $this->assertSame(GeriYuklemeHatasi::IMZA_GECERSIZ, $geri->hata_kodu);
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));
        $this->assertFalse($this->pgRestoreGeriYukledi());
    }

    public function test_yerel_dosya_manifestteki_ozetle_ayni_degilse_geri_yuklenmez(): void
    {
        $yedek = $this->yedekAl();
        file_put_contents(config('yedekleme.klasor').'/'.$yedek->dosya_adi, 'X', FILE_APPEND);

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeHatasi::OZET_TUTMUYOR, $geri->hata_kodu);
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));
    }

    public function test_pg_restore_hatasinda_yarim_veritabani_silinir_bildirim_gider_satir_verisi_mesaja_girmez(): void
    {
        $yedek = $this->yedekAl();
        $this->pgRestoreHatasi = "pg_restore: error: COPY failed for table \"users\": ERROR:  invalid input\nCONTEXT:  COPY users, line 1: \"kisisel-veri-ornegi\"";

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeDurumu::Basarisiz, $geri->durum);
        $this->assertSame(GeriYuklemeHatasi::PG_RESTORE, $geri->hata_kodu);
        $this->assertStringNotContainsString('kisisel-veri-ornegi', (string) $geri->hata_mesaji);
        $this->assertTrue($this->psqlCalisti('DROP DATABASE IF EXISTS :"ad" WITH (FORCE);', self::AD));
        $this->assertNotContains(self::AD, $this->veritabanlari, 'Geride geçici veritabanı kalmaz');
        $this->assertNotNull($geri->veritabani_silinme_zamani);
        $this->assertFalse($geri->veritabaniKalmisOlabilirMi());
        $this->assertSame('basarisiz', app(YedeklemeYapilandirmasi::class)->islemDurumu()->oku()?->adim);
        Mail::assertSent(YedeklemeMaili::class, fn (YedeklemeMaili $m): bool => $m->baslik === 'Geri yükleme başarısız');
    }

    public function test_yerel_dosya_yoksa_sifresiz_kopyadan_indirilir_ozeti_dogrulanir_gecici_dosya_kalmaz(): void
    {
        $yedek = $this->yedekAl();
        $yerel = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
        $hedef = YedekHedefi::factory()->create(['ad' => 'İkinci disk', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        BellekHedefi::$dosyalar[$hedef->id.'/'.$yedek->dosya_adi] = (string) file_get_contents($yerel);
        YedekKopyasi::factory()->plansiz()->create([
            'yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam,
            'uzak_kimlik' => $hedef->id.'/'.$yedek->dosya_adi, 'sifreli' => false,
        ]);
        unlink($yerel);
        $geciciOnce = glob(sys_get_temp_dir().'/emor-geri-*') ?: [];
        $kullanilan = null;
        $this->sahtePostgres(['*pg_restore*--single-transaction*' => function (PendingProcess $p) use (&$kullanilan): string {
            $komut = (array) $p->command;
            $kullanilan = (string) end($komut);
            $this->assertFileExists($kullanilan);

            return '';
        }]);

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeDurumu::Tamam, $geri->durum, (string) $geri->hata_mesaji);
        $this->assertNotNull($kullanilan);
        $this->assertStringStartsWith(sys_get_temp_dir().'/emor-geri-', $kullanilan);
        $this->assertFileDoesNotExist($kullanilan);
        $this->assertSame($geciciOnce, glob(sys_get_temp_dir().'/emor-geri-*') ?: []);
    }

    public function test_kopyadan_inen_dosya_bozuksa_kullanilmaz(): void
    {
        $yedek = $this->yedekAl();
        $yerel = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        BellekHedefi::$dosyalar['k/1'] = (string) file_get_contents($yerel);
        BellekHedefi::$bozukOku = true;
        YedekKopyasi::factory()->plansiz()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'uzak_kimlik' => 'k/1', 'sifreli' => false]);
        unlink($yerel);

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeHatasi::YEDEK_DOSYASI_YOK, $geri->hata_kodu);
        $this->assertFalse($this->pgRestoreGeriYukledi());
    }

    public function test_yalniz_sifreli_kopya_varsa_ozel_anahtar_gerekir(): void
    {
        $yedek = $this->yedekAl();
        $hedef = YedekHedefi::factory()->create();
        YedekKopyasi::factory()->plansiz()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'uzak_kimlik' => 'uzak/1.gpg', 'sifreli' => true]);
        unlink(config('yedekleme.klasor').'/'.$yedek->dosya_adi);

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeHatasi::OZEL_ANAHTAR_GEREKLI, $geri->hata_kodu);
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));
    }

    public function test_sinama_gecince_dogrulama_durumu_olay_gunlugu_ve_yasam_sinyali_yazilir_veritabani_silinir(): void
    {
        $yedek = $this->yedekAl();
        YedekAyari::query()->update(['sinyal_sinama_adresi' => 'https://izleyici.ornek.test/sinama']);
        Http::fake(['https://izleyici.ornek.test/*' => Http::response('ok')]);

        $kod = app(GeriYukleyici::class)->sina($this->kilit, $yedek, null);

        $this->assertNull($kod);
        $this->assertSame(DogrulamaDurumu::SinamaGecti, $yedek->refresh()->dogrulama_durumu);
        $this->assertTrue($this->psqlCalisti('CREATE DATABASE'));
        $this->assertTrue($this->psqlCalisti('DROP DATABASE'));
        $this->assertSame([], array_values(array_filter($this->veritabanlari, fn (string $v): bool => str_starts_with($v, 'erp_sinama_'))));
        $olaylar = app(OlayGunlugu::class)->durumlar();
        $this->assertSame(DogrulamaDurumu::SinamaGecti, $olaylar[$yedek->kimlik]->dogrulama);
        Http::assertSent(fn ($istek): bool => $istek->url() === 'https://izleyici.ornek.test/sinama');
        $this->assertSame(IslemTuru::SinamaGeriYuklemesi, app(YedeklemeYapilandirmasi::class)->islemDurumu()->oku()?->tur);
        $this->assertSame(0, GeriYukleme::query()->count(), 'Sınama geri yükleme geçmişine girmez');
    }

    public function test_sayim_uyusmazligi_yedegi_dogrulama_basarisiz_yapar_bildirim_uretir_veritabani_silinir(): void
    {
        $yedek = $this->yedekAl();
        $this->geriYuklenenIcerik = [
            'tablolar' => ['users' => 4, 'migrations' => 2, 'sessions' => 0],
            'migrationlar' => $this->kaynakVeritabani->migrationlar,
            'sahipler' => ['erp'],
        ];

        $kod = app(GeriYukleyici::class)->sina($this->kilit, $yedek, null);

        $this->assertSame(GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI, $kod);
        $this->assertSame(DogrulamaDurumu::Basarisiz, $yedek->refresh()->dogrulama_durumu);
        $this->assertSame(DogrulamaDurumu::Basarisiz, app(OlayGunlugu::class)->durumlar()[$yedek->kimlik]->dogrulama);
        Mail::assertSent(YedeklemeMaili::class, fn (YedeklemeMaili $m): bool => $m->baslik === 'Yedek doğrulaması başarısız');
        $this->assertSame([], array_values(array_filter($this->veritabanlari, fn (string $v): bool => str_starts_with($v, 'erp_sinama_'))));
    }

    public function test_migration_listesi_ya_da_tablo_sahibi_farkliysa_karsilastirma_tutmaz(): void
    {
        $yedek = $this->yedekAl();

        foreach ([
            ['migrationlar' => [$this->kaynakVeritabani->migrationlar[0]], 'sahipler' => ['erp']],
            ['migrationlar' => $this->kaynakVeritabani->migrationlar, 'sahipler' => ['erp', 'emor_yedek']],
        ] as $fark) {
            $this->geriYuklenenIcerik = ['tablolar' => $this->kaynakVeritabani->satirSayilari, ...$fark];

            $this->assertSame(GeriYuklemeHatasi::KARSILASTIRMA_TUTMADI, app(GeriYukleyici::class)->sina($this->kilit, $yedek->refresh(), null));
        }
    }

    public function test_veritabani_acilamazsa_yedegin_dogrulama_durumu_degismez_ama_bildirilir(): void
    {
        $yedek = $this->yedekAl();
        $this->olusturmaHatasi = 'ERROR:  permission denied to create database';

        $kod = app(GeriYukleyici::class)->sina($this->kilit, $yedek, null);

        $this->assertSame(GeriYuklemeHatasi::VERITABANI_OLUSTURULAMADI, $kod);
        $this->assertSame(DogrulamaDurumu::OzetTamam, $yedek->refresh()->dogrulama_durumu);
        $this->assertFalse($this->psqlCalisti('DROP DATABASE IF EXISTS :"ad"'), 'Açılmayan veritabanı silinmeye çalışılmaz');
        Mail::assertSent(YedeklemeMaili::class, fn (YedeklemeMaili $m): bool => $m->baslik === 'Yedek doğrulaması başarısız');
    }

    public function test_sinama_acikken_her_basarili_yedekten_sonra_sinama_geri_yuklemesi_calisir(): void
    {
        config(['yedekleme.sinama_geri_yukleme' => true]);

        $yedek = $this->yedekAl();

        $this->assertSame(DogrulamaDurumu::SinamaGecti, $yedek->dogrulama_durumu);
        $this->assertTrue($this->pgRestoreGeriYukledi());
    }

    public function test_sinama_kapaliyken_yedekten_sonra_geri_yukleme_yapilmaz(): void
    {
        $yedek = $this->yedekAl();

        $this->assertSame(DogrulamaDurumu::OzetTamam, $yedek->dogrulama_durumu);
        $this->assertFalse($this->pgRestoreGeriYukledi());
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));
    }

    public function test_onceki_sinamadan_kalan_veritabani_yeni_sinamada_silinir(): void
    {
        $yedek = $this->yedekAl();
        $this->veritabanlari[] = 'erp_sinama_20261120_020000_abcdef';

        app(GeriYukleyici::class)->sina($this->kilit, $yedek, null);

        $this->assertTrue($this->psqlCalisti('DROP DATABASE', 'erp_sinama_20261120_020000_abcdef'));
        $this->assertContains('erp', $this->veritabanlari);
    }

    public function test_ice_alinmis_yedekte_karsilastirma_atlanir_kaynagin_kodlamasi_kullanilir(): void
    {
        $yedek = $this->manifestliYedek(null, iceAlindi: true);

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeDurumu::Tamam, $geri->durum, (string) $geri->hata_mesaji);
        $this->assertFalse($this->psqlCalisti('query_to_xml'));
        Process::assertRan(fn (PendingProcess $p): bool => str_contains((string) $p->input, 'CREATE DATABASE')
            && in_array('--set=siralama=C.UTF-8', (array) $p->command, true));
    }

    public function test_veritabani_ayarlari_manifestten_psql_degiskeniyle_uygulanir(): void
    {
        $ozellikler = ['kodlama' => 'UTF8', 'siralama' => 'en_US.UTF-8', 'karakter_turu' => 'en_US.UTF-8', 'ayarlar' => ['TimeZone=Europe/Istanbul', "statement_timeout=0'; DROP DATABASE erp; --"], 'baglanma_yetkileri' => null];
        $yedek = $this->manifestliYedek($ozellikler);

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeDurumu::Tamam, $geri->durum, (string) $geri->hata_mesaji);
        // Değer SQL metnine girmez: psql değişkeni olarak verilir, psql tırnaklar
        Process::assertRan(fn (PendingProcess $p): bool => str_contains((string) $p->input, "ALTER DATABASE :\"ad\" SET :\"a0\" = :'d0';")
            && ! str_contains((string) $p->input, 'Europe/Istanbul')
            && in_array('--set=a0=TimeZone', (array) $p->command, true)
            && in_array('--set=d0=Europe/Istanbul', (array) $p->command, true)
            && in_array("--set=d1=0'; DROP DATABASE erp; --", (array) $p->command, true));
    }

    /**
     * BY-026-R1: kopya kaynağın bağlanma yetkileriyle açılır; eşdeğer
     * uygulanamazsa (ör. rol yok) açık hata, yarım kopya silinir.
     * Gerçek PostgreSQL'de eşdeğerlik: GeriYuklemeVeritabaniPostgresTest.
     */
    public function test_kopya_manifestteki_baglanma_yetkileriyle_acilir_uygulanamazsa_silinir(): void
    {
        $acl = '{erp=CTc/erp,emor_inceleme=c/erp}';
        $yedek = $this->manifestliYedek(['kodlama' => 'UTF8', 'siralama' => 'en_US.UTF-8', 'karakter_turu' => 'en_US.UTF-8', 'ayarlar' => [], 'baglanma_yetkileri' => $acl]);

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeDurumu::Tamam, $geri->durum, (string) $geri->hata_mesaji);
        Process::assertRan(fn (PendingProcess $p): bool => str_contains((string) $p->input, 'erp_yedek.acl')
            && in_array('--set=ad='.$geri->hedef_veritabani, (array) $p->command, true)
            && in_array('--set=acl='.$acl, (array) $p->command, true)
            && in_array('--set=varsayilan=0', (array) $p->command, true));

        // Rol yok: psql hata verir → kopya başarısız, açılan veritabanı silinir
        $this->sahtePostgres(['*psql*' => fn (PendingProcess $p) => str_contains((string) $p->input, 'erp_yedek.acl')
            ? Process::result(errorOutput: 'ERROR:  role "emor_inceleme" does not exist', exitCode: 3)
            : $this->psqlYaniti($p)]);
        $ikinci = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, 'iki', null);

        $this->assertSame(GeriYuklemeHatasi::VERITABANI_YETKILERI, $ikinci->hata_kodu);
        $this->assertNotContains((string) $ikinci->hedef_veritabani, $this->veritabanlari);
        $this->assertTrue($this->psqlCalisti('DROP DATABASE', (string) $ikinci->hedef_veritabani));
    }

    public function test_liste_turundeki_veritabani_ayari_hicbir_sey_acmadan_reddedilir(): void
    {
        $yedek = $this->manifestliYedek(['kodlama' => 'UTF8', 'siralama' => 'en_US.UTF-8', 'karakter_turu' => 'en_US.UTF-8', 'ayarlar' => ['search_path="$user", public'], 'baglanma_yetkileri' => null]);

        $geri = app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);

        $this->assertSame(GeriYuklemeHatasi::VERITABANI_OZELLIGI, $geri->hata_kodu);
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));
    }

    public function test_silme_yalniz_kopya_ve_sinama_kalibindaki_adlarda_calisir(): void
    {
        foreach (['erp', 'erp_test', 'postgres', 'erp_onceki_20261121_103000', 'erp_geri_x', 'erp_geri_20261121_103000_Buyuk'] as $ad) {
            try {
                app(GeriYuklemeVeritabani::class)->dusur($ad);
                $this->fail("Kalıp dışı veritabanı silinmeye çalışıldı: {$ad}");
            } catch (LogicException) {
                // beklenen
            }
        }

        Process::assertNothingRan();
    }

    public function test_suresi_dolan_ve_yarim_kalan_kopyalar_temizlenir_digerlerine_dokunulmaz(): void
    {
        $yedek = Yedek::factory()->create();
        $kayit = function (string $ad, GeriYuklemeDurumu $durum, ?CarbonImmutable $bitti, array $adimlar = []) use ($yedek): GeriYukleme {
            $this->veritabanlari[] = $ad;

            return GeriYukleme::query()->create([
                'kimlik' => (string) Str::uuid(), 'yedek_id' => $yedek->id, 'tur' => GeriYuklemeTuru::Kopya, 'hedef_veritabani' => $ad,
                'durum' => $durum, 'adimlar' => $adimlar, 'basladi' => $bitti, 'bitti' => $bitti,
            ]);
        };
        $acildi = [['adim' => GeriYuklemeAdimi::Veritabani->value, 'durum' => 'basladi', 'zaman' => '2026-11-21T07:00:00+00:00']];
        $eski = $kayit('erp_geri_20261113_100000', GeriYuklemeDurumu::Tamam, CarbonImmutable::now()->subDays(8));
        $yeni = $kayit('erp_geri_20261120_100000', GeriYuklemeDurumu::Tamam, CarbonImmutable::now()->subDay());
        $yarim = $kayit('erp_geri_20261121_090000', GeriYuklemeDurumu::Basarisiz, CarbonImmutable::now()->subHour(), $acildi);
        $baskasi = $kayit('erp_geri_20261121_091000', GeriYuklemeDurumu::Basarisiz, CarbonImmutable::now()->subHour());
        $olmus = $kayit('erp_geri_20261121_092000', GeriYuklemeDurumu::Calisiyor, null, $acildi);

        $sonuc = app(KopyaVeritabanlari::class)->temizle($this->kilit);

        $this->assertSame(['silinen' => 3, 'hatali' => 0], $sonuc);
        $this->assertEqualsCanonicalizing(['erp', 'postgres', 'erp_geri_20261120_100000', 'erp_geri_20261121_091000'], $this->veritabanlari);
        $this->assertNotNull($eski->refresh()->veritabani_silinme_zamani);
        $this->assertNull($yeni->refresh()->veritabani_silinme_zamani);
        $this->assertNotNull($yarim->refresh()->veritabani_silinme_zamani);
        $this->assertNull($baskasi->refresh()->veritabani_silinme_zamani);
        $this->assertSame(GeriYuklemeDurumu::Basarisiz, $olmus->refresh()->durum);
        $this->assertSame(GeriYuklemeHatasi::YARIDA_KALDI, $olmus->hata_kodu);
        $this->assertSame(CarbonImmutable::now()->addDays(6)->toIso8601String(), app(KopyaVeritabanlari::class)->silinecegiZaman($yeni)?->toIso8601String());
    }

    public function test_kopya_elle_yalniz_kaydi_olan_bekleyen_kopyaysa_silinir(): void
    {
        $yedek = Yedek::factory()->create();
        $this->veritabanlari[] = 'erp_geri_20261120_100000';
        GeriYukleme::query()->create([
            'kimlik' => (string) Str::uuid(), 'yedek_id' => $yedek->id, 'tur' => GeriYuklemeTuru::Kopya,
            'hedef_veritabani' => 'erp_geri_20261120_100000', 'durum' => GeriYuklemeDurumu::Tamam, 'bitti' => CarbonImmutable::now(),
        ]);
        $kopyalar = app(KopyaVeritabanlari::class);

        foreach (['erp' => GeriYuklemeHatasi::HEDEF_ADI_GECERSIZ, 'erp_geri_20261120_110000' => GeriYuklemeHatasi::KOPYA_YOK] as $ad => $beklenen) {
            try {
                $kopyalar->sil($this->kilit, $ad, null);
                $this->fail("Silinmemesi gereken ad: {$ad}");
            } catch (GeriYuklemeHatasi $hata) {
                $this->assertSame($beklenen, $hata->kod);
            }
        }
        $this->assertFalse($this->psqlCalisti('DROP DATABASE'));

        $geri = $kopyalar->sil($this->kilit, 'erp_geri_20261120_100000', null);

        $this->assertNotNull($geri->veritabani_silinme_zamani);
        $this->assertNotContains('erp_geri_20261120_100000', $this->veritabanlari);
        $this->assertSame(1, YedekIslemKaydi::query()->where('islem', 'kopya_sil')->where('sonuc', 'basarili')->count());
    }

    public function test_kilit_tutulmadan_geri_yukleme_yapilmaz(): void
    {
        $yedek = $this->yedekAl();
        $this->kilit->birak();

        $this->expectException(LogicException::class);
        app(GeriYukleyici::class)->kopyaya($this->kilit, $yedek, null, null);
    }
}
