<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\YedeklemeMaili;
use App\Models\MailAyari;
use App\Models\Yedek;
use App\Models\YedekAyari;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
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
