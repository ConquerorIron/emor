<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Yedek;
use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\OnayBilgisi;
use App\Services\Yedekleme\UstuneGeriYuklemeAdimi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Yedekleme\SahtePostgres;
use Tests\Yedekleme\UstuneGeriYuklemeOrtami;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-030 (arka uç): üstüne geri yüklemeyi başlatma (sistem yöneticisi) ve
 * bakım modundan muaf, oturumsuz, erişim anahtarıyla yetkilendirilen
 * ilerleme / onay / geri dön uçları (README §7.2).
 */
final class UstuneGeriYuklemeApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SahtePostgres;
    use UstuneGeriYuklemeOrtami;
    use YedeklemeOrtami;

    private const ISLEM = '/api/v1/yedekleme/ustune-geri-yukleme/';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ustuneOrtaminiKur();
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function baslatUcu(Yedek $yedek): string
    {
        return '/api/v1/ayarlar/yedekler/'.$yedek->kimlik.'/ustune-geri-yukle';
    }

    public function test_sistem_yoneticisi_olmayan_baslatamaz_403(): void
    {
        $yedek = Yedek::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->postJson($this->baslatUcu($yedek), ['onay_metni' => 'erp'])->assertForbidden()->assertJsonPath('kod', 'ERISIM_ENGELLI');
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_onay_metni_canli_veritabani_adi_degilse_422(): void
    {
        $yedek = Yedek::factory()->create();
        $this->actingAs(User::factory()->yonetici()->create());

        $this->postJson($this->baslatUcu($yedek), ['onay_metni' => 'ERP'])
            ->assertUnprocessable()
            ->assertJsonPath('kod', 'DOGRULAMA')
            ->assertJsonPath('hatalar.onay_metni.0', __('hata.geri_yukleme_onay_metni', ['ad' => 'erp']));
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function baslatmaReddi(): array
    {
        return [
            'içe alınmış yedek' => ['ice', 'GERI_YUKLEME_SEMA_BILINMIYOR'],
            'imzasız yedek' => ['imzasiz', 'GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ'],
            'servis çalıştırıcısı yok' => ['servis', 'GERI_YUKLEME_SERVIS_GEREKLI'],
            'açık işlem var' => ['acik', 'GERI_YUKLEME_ACIK_GERI_YUKLEME'],
        ];
    }

    #[DataProvider('baslatmaReddi')]
    public function test_baslatilamayan_durumlar_409(string $durum, string $kod): void
    {
        $yedek = Yedek::factory()->create(['ice_alindi' => $durum === 'ice', 'imzali' => $durum !== 'imzasiz']);
        match ($durum) {
            'servis' => config(['yedekleme.calistirici' => 'dogrudan']),
            'acik' => $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true),
            default => null,
        };
        $this->actingAs(User::factory()->yonetici()->create());

        $this->postJson($this->baslatUcu($yedek), ['onay_metni' => 'erp'])->assertConflict()->assertJsonPath('kod', $kod);
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_baslatma_istegi_anahtarin_yalniz_ozetini_dosyaya_yazar_anahtari_bir_kez_verir(): void
    {
        $yedek = Yedek::factory()->create();
        $yonetici = User::factory()->yonetici()->create();
        $this->actingAs($yonetici);

        $yanit = $this->postJson($this->baslatUcu($yedek), ['onay_metni' => 'erp'])->assertAccepted()->assertHeader('Cache-Control', 'no-store, private');
        $anahtar = (string) $yanit->json('data.erisim_anahtari');
        $islem = (string) $yanit->json('data.islem_kimligi');

        $this->assertTrue(ErisimAnahtari::bicimliMi($anahtar));
        $this->assertSame($yanit->json('data.istek_kimligi'), $islem);
        $dosyalar = app(IstekKutusu::class)->bekleyenler();
        $this->assertCount(1, $dosyalar);
        $icerik = (string) file_get_contents($dosyalar[0]);
        $this->assertStringNotContainsString($anahtar, $icerik);
        $istek = app(IstekKutusu::class)->oku($dosyalar[0]);
        $this->assertSame(['yedek_kimligi' => $yedek->kimlik, 'onay_metni' => 'erp', 'erisim_anahtari_ozeti' => ErisimAnahtari::ozet($anahtar)], $istek->parametreler);
        $this->assertSame($yonetici->id, $istek->kullaniciId);

        // Henüz çalıştırıcıya ulaşmamış istek: anahtarla "kuyrukta" okunur
        $this->getJson(self::ISLEM.$islem.'/durum', ['X-Erisim-Anahtari' => $anahtar])->assertOk()->assertJsonPath('data.durum', 'kuyrukta');
        $this->getJson(self::ISLEM.$islem.'/durum', ['X-Erisim-Anahtari' => ErisimAnahtari::uret()])->assertForbidden();
    }

    public function test_ilerleme_ucu_anahtarsiz_401_yanlis_anahtarla_403_suresi_dolanla_403(): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::PgRestore, true, anahtar: $anahtar);
        $uc = self::ISLEM.$kayit->kimlik.'/durum';

        $this->getJson($uc)->assertUnauthorized()->assertJsonPath('kod', GeriYuklemeHatasi::ERISIM_ANAHTARI_YOK);
        $this->getJson($uc, ['X-Erisim-Anahtari' => ErisimAnahtari::uret()])->assertForbidden()->assertJsonPath('kod', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ);
        $this->getJson($uc, ['X-Erisim-Anahtari' => 'kisa'])->assertForbidden();
        $this->getJson($uc, ['X-Erisim-Anahtari' => $anahtar])->assertOk()
            ->assertJsonPath('data.durum', 'calisiyor')
            ->assertJsonPath('data.adim', 'pg_restore')
            ->assertJsonPath('data.adimlar.5', ['adim' => 'pg_restore', 'durum' => 'suruyor']);

        $this->travelTo(CarbonImmutable::now()->addHours(9));
        $this->getJson($uc, ['X-Erisim-Anahtari' => $anahtar])->assertForbidden()->assertJsonPath('kod', GeriYuklemeHatasi::ERISIM_ANAHTARI_SURESI_DOLDU);
        $this->postJson(self::ISLEM.$kayit->kimlik.'/geri-don', [], ['X-Erisim-Anahtari' => $anahtar])->assertForbidden()->assertJsonPath('kod', GeriYuklemeHatasi::ERISIM_ANAHTARI_SURESI_DOLDU);
    }

    public function test_anahtar_yalniz_kendi_islemini_okur_baska_uca_yetki_vermez(): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, anahtar: $anahtar);

        // Başka işlemin kimliğiyle (aynı anahtar) okunamaz
        $this->getJson(self::ISLEM.Str::uuid().'/durum', ['X-Erisim-Anahtari' => $anahtar])->assertForbidden();
        // Anahtar oturum yerine geçmez
        $this->getJson('/api/v1/ayarlar/yedekler', ['X-Erisim-Anahtari' => $anahtar])->assertUnauthorized();
        $this->getJson('/api/v1/auth/me', ['X-Erisim-Anahtari' => $anahtar])->assertUnauthorized();

        $this->getJson(self::ISLEM.$kayit->kimlik.'/durum', ['X-Erisim-Anahtari' => $anahtar])->assertOk()->assertJsonPath('data.islem_kimligi', $kayit->kimlik);
    }

    public function test_ilerleme_ucu_bakim_modunda_ve_veritabani_erisilemezken_yanit_verir(): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, anahtar: $anahtar);
        $this->bakimDosyasiYaz($kayit->kimlik);
        app(OnayBilgisi::class)->bilgiYaz($kayit->kimlik, ['aktif_erp_ortami' => ['onceki' => 'canli', 'geri_yuklenen' => 'test', 'farkli' => true]]);
        // Oturum ve önbellek veritabanında; SPA (stateful) kökenli istek
        config(['session.driver' => 'database', 'cache.default' => 'database']);
        $basliklar = ['X-Erisim-Anahtari' => $anahtar, 'Referer' => 'http://localhost/', 'Origin' => 'http://localhost'];
        $this->assertTrue($this->app->maintenanceMode()->active());

        $this->veritabaniErisilemezken(function () use ($kayit, $anahtar, $basliklar): void {
            $this->getJson('/api/v1/auth/me')->assertStatus(503);

            $this->getJson(self::ISLEM.$kayit->kimlik.'/durum', $basliklar)->assertOk()
                ->assertJsonPath('data.durum', 'onay_bekliyor')
                ->assertJsonPath('data.yapilabilir', ['onayla' => true, 'geri_don' => true])
                ->assertJsonPath('data.onay_bilgisi.aktif_erp_ortami.geri_yuklenen', 'test');

            $istek = $this->postJson(self::ISLEM.$kayit->kimlik.'/onayla', [], $basliklar)->assertAccepted()->json('data.istek_kimligi');
            $yol = config('yedekleme.istek_klasoru').'/bekleyen/'.$istek.'.json';
            $okunan = app(IstekKutusu::class)->oku($yol);
            $this->assertSame('onayla', $okunan->tur);
            $this->assertSame($kayit->kimlik, $okunan->parametreler['islem_kimligi']);
            $this->assertSame(1, $okunan->kullaniciId);
            // Anahtar değil, işlem durumundaki özetle doğrulanan kısa ömürlü kanıt (BY-030-R1)
            $this->assertStringNotContainsString($anahtar, (string) file_get_contents($yol));
            $p = $okunan->parametreler;
            $this->assertSame(ErisimAnahtari::GECERLI, ErisimAnahtari::kanitDenetle((string) $p['kanit'], $kayit->erisimAnahtariOzeti, $kayit->erisimAnahtariBitis, $kayit->kimlik, 'onayla', (int) $p['son_gecerlilik'], (string) $p['nonce'], ''));
            $this->assertLessThanOrEqual(CarbonImmutable::now()->getTimestamp() + ErisimAnahtari::KANIT_OMRU_SN, $p['son_gecerlilik']);

            $this->getJson(self::ISLEM.$kayit->kimlik.'/durum?istek='.$istek, $basliklar)->assertOk()->assertJsonPath('data.istek.durum', 'kuyrukta');
        });
    }

    /**
     * BY-028-R2: onay bilgisinde farkı olan ayar grubu için seçim zorunlu;
     * seçim kanıta bağlanıp isteğe yazılır.
     */
    public function test_farkli_ayar_grubu_secilmeden_onay_istegi_422_secimle_kanita_baglanir(): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, anahtar: $anahtar);
        app(OnayBilgisi::class)->bilgiYaz($kayit->kimlik, ['ayar_gruplari' => [['grup' => 'mail', 'farkli' => true], ['grup' => 'planlar', 'farkli' => false]]]);
        $uc = self::ISLEM.$kayit->kimlik.'/onayla';
        $baslik = ['X-Erisim-Anahtari' => $anahtar];

        $this->postJson($uc, [], $baslik)->assertUnprocessable()->assertJsonPath('kod', GeriYuklemeHatasi::AYAR_SECIMI_EKSIK);
        $this->postJson($uc, ['ayar_secimleri' => ['planlar' => 'guncel']], $baslik)->assertUnprocessable()->assertJsonPath('kod', GeriYuklemeHatasi::AYAR_SECIMI_EKSIK);
        $this->postJson($uc, ['ayar_secimleri' => ['mail' => 'varsayilan']], $baslik)->assertUnprocessable()->assertJsonPath('kod', 'DOGRULAMA');
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());

        $istek = $this->postJson($uc, ['ayar_secimleri' => ['mail' => 'guncel', 'planlar' => 'geri_yuklenen']], $baslik)->assertAccepted()->json('data.istek_kimligi');
        $p = app(IstekKutusu::class)->oku(config('yedekleme.istek_klasoru').'/bekleyen/'.$istek.'.json')->parametreler;
        $this->assertSame('mail=guncel,planlar=geri_yuklenen', $p['ayar_secimleri']);
        // Kanıt seçimlere bağlı: başka seçimle doğrulanmaz
        $this->assertSame(ErisimAnahtari::GECERLI, ErisimAnahtari::kanitDenetle((string) $p['kanit'], $kayit->erisimAnahtariOzeti, $kayit->erisimAnahtariBitis, $kayit->kimlik, 'onayla', (int) $p['son_gecerlilik'], (string) $p['nonce'], 'mail=guncel,planlar=geri_yuklenen'));
        $this->assertSame(ErisimAnahtari::GECERSIZ, ErisimAnahtari::kanitDenetle((string) $p['kanit'], $kayit->erisimAnahtariOzeti, $kayit->erisimAnahtariBitis, $kayit->kimlik, 'onayla', (int) $p['son_gecerlilik'], (string) $p['nonce'], 'mail=geri_yuklenen,planlar=geri_yuklenen'));
    }

    /**
     * BY-030-R3: karar dosyası yayımlandıktan sonraki arıza (klasör eşitlemesi,
     * ardından günlüğün belirsizlik notu) kararı başarısız göstermez; kullanıcı
     * aynı kararı izler, aynı anahtarla yeniden deneme ikinci karar bırakmaz.
     */
    public function test_karar_yayimlandiktan_sonraki_ariza_kabulu_bozmaz_ayni_anahtarla_yeniden_deneme_tek_karar(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root klasör iznini aşar (T11).');
        }
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, anahtar: $anahtar);
        $baslik = ['X-Erisim-Anahtari' => $anahtar, 'Idempotency-Key' => (string) Str::uuid()];
        // Günlük ilk kaydı (yayından önce) yazar; sonrakiler yazılamaz
        $gunluk = new class extends AbstractProcessingHandler
        {
            /** @var list<string> */
            public array $yazilan = [];

            protected function write(LogRecord $kayit): void
            {
                if ($this->yazilan !== []) {
                    throw new RuntimeException('günlük yazılamadı');
                }
                $this->yazilan[] = $kayit->message;
            }
        };
        config(['logging.channels.yedekleme' => ['driver' => 'custom', 'via' => fn (): Logger => new Logger('yedekleme', [$gunluk])]]);
        Log::forgetChannel('yedekleme');
        $klasor = config('yedekleme.istek_klasoru').'/bekleyen';
        @mkdir($klasor, 0770, true);
        // Yazılabilir, geçilebilir, okunamaz: taşıma olur, klasör eşitlemek için açılamaz
        chmod($klasor, 0300);

        try {
            $istek = $this->postJson(self::ISLEM.$kayit->kimlik.'/onayla', [], $baslik)->assertAccepted()->json('data.istek_kimligi');
            $tekrar = $this->postJson(self::ISLEM.$kayit->kimlik.'/onayla', [], $baslik)->assertAccepted()->json('data.istek_kimligi');
        } finally {
            chmod($klasor, 0770);
        }

        $this->assertSame($istek, $tekrar);
        $this->assertSame(['yedekleme.istek'], $gunluk->yazilan);
        $this->assertSame([$istek.'.json'], array_map('basename', app(IstekKutusu::class)->bekleyenler()));
        $this->getJson(self::ISLEM.$kayit->kimlik.'/durum?istek='.$istek, ['X-Erisim-Anahtari' => $anahtar])->assertOk()
            ->assertJsonPath('data.istek.durum', 'kuyrukta');
    }

    public function test_yer_degistirmeden_once_onay_istegi_409(): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::Migration, true, anahtar: $anahtar);

        $this->postJson(self::ISLEM.$kayit->kimlik.'/onayla', [], ['X-Erisim-Anahtari' => $anahtar])->assertConflict()->assertJsonPath('kod', 'GERI_YUKLEME_ISLEM_DURUMU_UYGUN_DEGIL');
        $this->postJson(self::ISLEM.$kayit->kimlik.'/onayla', [], ['X-Erisim-Anahtari' => ErisimAnahtari::uret()])->assertForbidden();
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
    }

    public function test_islenmeden_reddedilen_baslatma_istegi_anahtarla_basarisiz_gorunur(): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kimlik = (string) Str::uuid();
        app(IstekKutusu::class)->sonucYaz($kimlik, 'basarisiz', 'ISTEK_YETKI_YOK', 'Kullanıcının bu işlem için yetkisi yok.', ['islem_kimligi' => $kimlik, 'erisim_anahtari_ozeti' => ErisimAnahtari::ozet($anahtar)]);

        $this->getJson(self::ISLEM.$kimlik.'/durum', ['X-Erisim-Anahtari' => $anahtar])->assertOk()
            ->assertJsonPath('data.durum', 'basarisiz')
            ->assertJsonPath('data.hata.kod', 'ISTEK_YETKI_YOK');
        $this->getJson(self::ISLEM.$kimlik.'/durum', ['X-Erisim-Anahtari' => ErisimAnahtari::uret()])->assertForbidden();
    }
}
