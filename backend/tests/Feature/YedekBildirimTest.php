<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\YedeklemeMaili;
use App\Models\MailAyari;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekPlani;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekBildirimcisi;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekIsaretleyici;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekOzeti;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-012: bildirimler ve yaşam sinyali (README §4.8).
 */
final class YedekBildirimTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

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
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    public function test_basarisiz_yedek_maili_kuyruga_konmadan_dogrudan_gider(): void
    {
        $this->sahtePgAraclari(['*pg_dump*--file=*' => Process::result(errorOutput: 'pg_dump: error', exitCode: 1)]);

        app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Elle);

        Mail::assertSent(YedeklemeMaili::class, fn (YedeklemeMaili $m): bool => $m->hasTo('bt@ornek.test') && $m->baslik === 'Yedek alınamadı');
        Mail::assertNothingQueued();
    }

    /**
     * YF-39: hata yolunda yedek kaydı güncellenemese de (veritabanı yazımı
     * düşer) denetim kaydı yazılır ve "yedek alınamadı" maili gider.
     */
    public function test_basarisiz_yedegin_kaydi_guncellenemese_de_mail_gider(): void
    {
        $this->sahtePgAraclari(['*pg_dump*--file=*' => Process::result(errorOutput: 'pg_dump: error', exitCode: 1)]);
        $kancaCalisti = false;
        Yedek::updating(function (Yedek $yedek) use (&$kancaCalisti): void {
            if ($yedek->durum === YedekDurumu::Basarisiz) {
                $kancaCalisti = true;
                throw new RuntimeException('veritabanı yazılamıyor');
            }
        });

        $yedek = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Elle);

        // Kanca gerçekten çalıştı (T14): kayıt `basarisiz`'a geçemedi
        $this->assertTrue($kancaCalisti);
        $this->assertSame(YedekDurumu::Calisiyor, $yedek->durum);
        Mail::assertSent(YedeklemeMaili::class, fn (YedeklemeMaili $m): bool => $m->baslik === 'Yedek alınamadı');
    }

    /** YF-39: yedek kaydı hiç açılamazsa (veritabanı yazılamıyor) zamanlayıcı bildirir. */
    public function test_planli_yedegin_kaydi_acilamazsa_zamanlayici_mail_gonderir(): void
    {
        config(['yedekleme.aktif' => true]);
        $this->sahtePgAraclari();
        $plan = YedekPlani::factory()->create();
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subMinute()])->save();
        $kancaCalisti = false;
        Yedek::creating(function () use (&$kancaCalisti): void {
            $kancaCalisti = true;
            throw new RuntimeException('veritabanı yazılamıyor');
        });

        $this->artisan('yedek:zamanla')->assertFailed();

        $this->assertTrue($kancaCalisti);
        Mail::assertSent(YedeklemeMaili::class, fn (YedeklemeMaili $m): bool => $m->baslik === 'Yedek alınamadı'
            && str_contains(implode("\n", $m->satirlar), 'Planlı yedek çalıştırılamadı: RuntimeException'));
    }

    /**
     * G2-3 (D6): planlar okunamıyorsa (PostgreSQL baştan erişilemiyor) bu
     * "vadesi gelen yok" değildir: zamanlayıcı hata ile çıkar ve bildirir. Mail
     * ayarı veritabanından okunamayınca dosya kopyasından gelir.
     */
    public function test_planlar_okunamazsa_zamanlayici_hata_verir_ve_mail_gonderir(): void
    {
        config(['yedekleme.aktif' => true]);
        app(AyarKopyasi::class)->yaz();
        $kancaCalisti = false;
        DB::connection()->beforeExecuting(function (string $sorgu) use (&$kancaCalisti): void {
            if (str_contains($sorgu, 'yedek_planlari')) {
                $kancaCalisti = true;
                throw new RuntimeException('veritabanına bağlanılamıyor');
            }
        });

        $this->artisan('yedek:zamanla')->assertFailed();

        $this->assertTrue($kancaCalisti);
        Mail::assertSent(YedeklemeMaili::class, fn (YedeklemeMaili $m): bool => $m->baslik === 'Yedek alınamadı'
            && str_contains(implode("\n", $m->satirlar), 'Yedek planları okunamadı'));
    }

    /** Geri yükleme sırasında mail ayarı veritabanında olmayabilir: dosya kopyasından gönderilir. */
    public function test_veritabaninda_mail_ayari_yokken_dosya_kopyasindaki_ayarla_gider(): void
    {
        app(AyarKopyasi::class)->yaz();
        MailAyari::query()->delete();
        YedekAyari::query()->delete();

        $gitti = app(YedekBildirimcisi::class)->bildir('kopya_basarisiz', ['FTP: erp.dump']);

        $this->assertTrue($gitti);
        Mail::assertSent(YedeklemeMaili::class, fn (YedeklemeMaili $m): bool => $m->hasTo('bt@ornek.test'));
        $this->assertSame('smtp.ornek.test', config('mail.mailers.uygulama.host'));
        $this->assertSame('smtp-gizli', config('mail.mailers.uygulama.password'));
        // Dosya kopyasında SMTP parolası düz metin durmaz
        $this->assertStringNotContainsString('smtp-gizli', (string) file_get_contents(config('yedekleme.ayar_kopyasi')));
    }

    public function test_ayni_hata_icin_bir_saat_icinde_ikinci_mail_gitmez(): void
    {
        $bildirimci = app(YedekBildirimcisi::class);

        $bildirimci->bildir('yedek_basarisiz', ['a']);
        $bildirimci->bildir('yedek_basarisiz', ['a']);
        $this->travel(61)->minutes();
        $bildirimci->bildir('yedek_basarisiz', ['a']);

        Mail::assertSentCount(2);
    }

    public function test_sinyal_adresi_erisilemezse_yedek_yine_tamam(): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->update(['sinyal_yerel_adresi' => 'https://izleyici.ornek.test/yerel']);
        Http::fake(['https://izleyici.ornek.test/*' => Http::failedConnection()]);

        $yedek = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Elle);

        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        Http::assertSentCount(1);
    }

    public function test_sinyal_istegi_govdesiz_get_gizli_bilgi_tasimaz(): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->update(['sinyal_yerel_adresi' => 'https://izleyici.ornek.test/yerel']);
        Http::fake(['https://izleyici.ornek.test/*' => Http::response('ok')]);

        app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Elle, 'gizli not');

        Http::assertSent(fn (Request $istek): bool => $istek->method() === 'GET'
            && $istek->url() === 'https://izleyici.ornek.test/yerel'
            && $istek->body() === ''
            && ! str_contains(json_encode($istek->headers()), 'gizli'));
    }

    public function test_ozet_uc_zamani_ayri_ve_sessizlik_suresini_verir(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00', 'UTC'));
        Yedek::factory()->create(['alinma_zamani' => CarbonImmutable::parse('2026-11-21 09:00', 'UTC')]);

        $ozet = app(YedekOzeti::class)->hesapla();

        $this->assertSame(3.0, $ozet['sessizlik_saat']);
        $this->assertNull($ozet['son_uzak']);
        $this->assertNull($ozet['son_sinama']);
        $this->assertSame(1, $ozet['toplam_adet']);
    }

    /** BY-012-R1: sınama zamanı sınamanın kaydından gelir; sonradan not yazmak özeti ilerletmez. */
    public function test_sinamadan_sonra_yalniz_aciklama_degisince_ozetin_sinama_zamani_ilerlemez(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18 03:00', 'UTC'));
        $yedek = Yedek::factory()->create(['dogrulama_durumu' => DogrulamaDurumu::SinamaGecti]);
        app(YedekDenetimi::class)->kaydet('sinama_geri_yukleme', IslemSonucu::Basarili, $yedek->id);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00', 'UTC'));

        app(YedekIsaretleyici::class)->aciklamaYaz($yedek, 'Yıl sonu', null);

        $this->assertSame('2026-11-18T03:00:00+00:00', app(YedekOzeti::class)->hesapla()['son_sinama']);
    }
}
