<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\YedekHedefiController;
use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\HedefKonumu;
use App\Services\Yedekleme\Hedefler\GoogleDriveBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\S3Baglayicisi;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\KopyaDurumu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-016 (+ BY-021 bağlantı akışı): Yedek Hedefleri API'si.
 */
final class YedekHedefiApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const K = '/api/v1/ayarlar/yedek-hedefleri';

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true]);
        BellekHedefi::sifirla();
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
        $this->actingAs(User::factory()->yonetici()->create());
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $alanlar
     * @return array<string, mixed>
     */
    private function ftpGovdesi(array $alanlar = []): array
    {
        return ['ad' => 'Yedek FTP', 'tur' => 'ftps', 'alanlar' => [
            'sunucu' => 'ftp.ornek.test', 'port' => 21, 'kullanici_adi' => 'yedek', 'sifre' => 'Gizli-Parola-1', 'klasor' => 'emor', 'pasif' => true, ...$alanlar,
        ]];
    }

    public function test_parola_hicbir_yanitta_donmez_duzenlemede_bos_birakilirsa_degismez(): void
    {
        $olustur = $this->postJson(self::K, $this->ftpGovdesi())->assertCreated();
        $this->assertStringNotContainsString('Gizli-Parola-1', (string) $olustur->getContent());
        $olustur->assertJsonPath('data.gizli_kayitli.sifre', true)->assertJsonPath('data.baglanti.sunucu', 'ftp.ornek.test');
        $id = $olustur->json('data.id');

        $liste = $this->getJson(self::K)->assertOk();
        $this->assertStringNotContainsString('Gizli-Parola-1', (string) $liste->getContent());

        $this->putJson(self::K.'/'.$id, [...$this->ftpGovdesi(['sifre' => '', 'klasor' => 'emor2']), 'ad' => 'Yedek FTP'])->assertOk();

        $hedef = YedekHedefi::query()->findOrFail($id);
        $this->assertSame('Gizli-Parola-1', $hedef->gizli_bilgiler['sifre']);
        $this->assertSame('emor2', $hedef->baglanti['klasor']);
    }

    public function test_sunucu_degisirken_bos_parola_reddedilir_kayitli_parola_yeni_adrese_gitmez(): void
    {
        $id = $this->postJson(self::K, $this->ftpGovdesi())->json('data.id');

        $this->putJson(self::K.'/'.$id, $this->ftpGovdesi(['sunucu' => 'baska.ornek.test', 'sifre' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('alanlar.sifre', 'hatalar');

        $this->assertSame('ftp.ornek.test', YedekHedefi::query()->findOrFail($id)->baglanti['sunucu']);
    }

    public function test_bilinmeyen_alan_ve_tur_degisikligi_reddedilir(): void
    {
        $this->postJson(self::K, $this->ftpGovdesi(['sunucu_anahtari' => 'ssh-ed25519 AAAA']))->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');
        $id = $this->postJson(self::K, $this->ftpGovdesi())->json('data.id');
        $this->putJson(self::K.'/'.$id, [...$this->ftpGovdesi(), 'tur' => 'ftp'])->assertStatus(422)->assertJsonValidationErrors('tur', 'hatalar');
    }

    public function test_planin_kullandigi_hedef_silinemez_409(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Ana']);
        YedekPlani::factory()->create()->hedefler()->attach($hedef, ['sakla_son_adet' => 7]);

        $this->deleteJson(self::K.'/'.$hedef->id)
            ->assertStatus(409)->assertJsonPath('kod', 'HEDEF_KULLANILIYOR')->assertJsonPath('plan_sayisi', 1);
        $this->assertModelExists($hedef);
    }

    public function test_kullanilmayan_hedef_silinir_denetime_yazilir(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Eski']);

        $this->deleteJson(self::K.'/'.$hedef->id)->assertNoContent();

        $this->assertModelMissing($hedef);
        $this->assertSame('hedef_sil', YedekIslemKaydi::query()->sole()->islem);
    }

    public function test_sina_sonucu_adim_adim_doner_basarisiz_adimdan_sonrasi_atlanir(): void
    {
        $this->postJson(self::K.'/sina', ['tur' => 's3', 'alanlar' => ['kova' => 'k']])
            ->assertOk()->assertJsonPath('data.basarili', true)
            ->assertJsonPath('data.adimlar.*.adim', ['baglan', 'yaz', 'oku', 'dogrula', 'sil']);

        BellekHedefi::$hataAdimi = 'oku';
        $yanit = $this->postJson(self::K.'/sina', ['tur' => 's3', 'alanlar' => ['kova' => 'k']])->assertOk();

        $yanit->assertJsonPath('data.basarili', false);
        $adimlar = collect($yanit->json('data.adimlar'))->pluck('basarili', 'adim')->all();
        $this->assertSame(['baglan' => true, 'yaz' => true, 'oku' => false, 'dogrula' => null, 'sil' => true], $adimlar);
        $this->assertSame([], BellekHedefi::$dosyalar);
    }

    public function test_sina_sure_sinirini_yalniz_adimlar_suresince_kisaltir_ayari_kalici_degistirmez(): void
    {
        // Adımlar içindeki kısaltma HedefSinayiciTest'te; burada ayarın isteğe sızmadığı
        config(['yedekleme.sure_siniri.kopya' => 900]);

        $this->postJson(self::K.'/sina', ['tur' => 's3', 'alanlar' => ['kova' => 'k']])->assertOk();

        $this->assertSame(900, config('yedekleme.sure_siniri.kopya'));
    }

    /** G1 (BY-016-R3 kardeşi): formdaki "1", kayıtlı "001" sayılıp kayıtlı hedef sınanmaz */
    public function test_sina_formdaki_sayisal_esit_gorunen_degeri_kayitli_hedefle_karistirmaz(): void
    {
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::S3, 'baglanti' => ['kova' => '001']]);

        $this->postJson(self::K.'/sina', ['id' => $hedef->id, 'alanlar' => ['kova' => '1']])->assertOk()->assertJsonPath('data.kaydedildi', false);
        $this->postJson(self::K.'/sina', ['id' => $hedef->id, 'alanlar' => ['kova' => '001']])->assertOk()->assertJsonPath('data.kaydedildi', true);
    }

    public function test_kopyasi_olan_hedefte_konum_degisikligi_409_sir_ve_konum_disi_alan_degisebilir(): void
    {
        $id = $this->postJson(self::K, $this->ftpGovdesi())->json('data.id');
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'emor/erp_a.dump.gpg', 'sifreli' => true]);

        $this->putJson(self::K.'/'.$id, $this->ftpGovdesi(['klasor' => 'emor2', 'sifre' => '']))
            ->assertStatus(409)->assertJsonPath('kod', 'HEDEF_KONUMU_DEGISMEZ')->assertJsonPath('alanlar', ['klasor']);
        // Sır yeniden girilse de kullanıcı adı (klasörün kökü) ve port değişmez
        $this->putJson(self::K.'/'.$id, $this->ftpGovdesi(['kullanici_adi' => 'baska', 'port' => 2121, 'sifre' => 'Yeni-Parola-2']))
            ->assertStatus(409)->assertJsonPath('alanlar', ['port', 'kullanici_adi']);
        $hedef = YedekHedefi::query()->findOrFail($id);
        $this->assertSame(['emor', 'yedek', 21, 'Gizli-Parola-1'], [$hedef->baglanti['klasor'], $hedef->baglanti['kullanici_adi'], $hedef->baglanti['port'], $hedef->gizli_bilgiler['sifre']]);

        // Parola yenileme, pasif kip ve ad serbest
        $this->putJson(self::K.'/'.$id, [...$this->ftpGovdesi(['sifre' => 'Yeni-Parola-2', 'pasif' => false]), 'ad' => 'Yedek FTP (yeni ad)'])->assertOk();
        $hedef->refresh();
        $this->assertSame(['Yeni-Parola-2', false, 'Yedek FTP (yeni ad)'], [$hedef->gizli_bilgiler['sifre'], $hedef->baglanti['pasif'], $hedef->ad]);

        // Ekran kilitli alanları listeden öğrenir
        $this->getJson(self::K)->assertOk()->assertJsonPath('data.0.konum_kilitli_alanlar', ['sunucu', 'port', 'kullanici_adi', 'klasor']);
    }

    /** BY-016-R3: port ve sayısal görünümlü klasör kesin karşılaştırılır; eşdeğer yazım ve parola yenileme serbest */
    public function test_kopyasi_olan_sftp_hedefinde_port_ve_sayisal_bicimli_klasor_degismez_esdeger_yazim_ve_parola_yenilenir(): void
    {
        $alanlar = ['sunucu' => 'sftp.ornek.test', 'port' => 22, 'kullanici_adi' => 'yedekci', 'klasor' => '001', 'sifre' => 'Gizli-Parola-1'];
        $govde = fn (array $degisen): array => ['ad' => 'SFTP', 'tur' => 'sftp', 'alanlar' => [...$alanlar, 'sifre' => '', ...$degisen]];
        $id = $this->postJson(self::K, ['ad' => 'SFTP', 'tur' => 'sftp', 'alanlar' => $alanlar])->assertCreated()->json('data.id');
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $id, 'durum' => KopyaDurumu::Kopyalaniyor, 'sifreli' => true]);

        $this->putJson(self::K.'/'.$id, $govde(['port' => 2222, 'sifre' => 'Yeni-Parola-2']))->assertStatus(409)->assertJsonPath('alanlar', ['port']);
        $this->putJson(self::K.'/'.$id, $govde(['klasor' => '1']))->assertStatus(409)->assertJsonPath('alanlar', ['klasor']);
        $this->putJson(self::K.'/'.$id, $govde(['sunucu' => 'SFTP.ornek.test', 'klasor' => '01']))->assertStatus(409)->assertJsonPath('alanlar', ['klasor']);
        $hedef = YedekHedefi::query()->findOrFail($id);
        $this->assertSame([22, '001', 'Gizli-Parola-1'], [$hedef->baglanti['port'], $hedef->baglanti['klasor'], $hedef->gizli_bilgiler['sifre']]);

        // Bağlayıcının aynı yere gittiği yazımlar (boş port = 22, "22", sondaki "/", alan adının harfi) + parola yenileme
        $this->putJson(self::K.'/'.$id, $govde(['port' => null, 'klasor' => '001/', 'sunucu' => 'SFTP.Ornek.Test', 'sifre' => 'Yeni-Parola-2']))->assertOk();
        $this->putJson(self::K.'/'.$id, $govde(['port' => '22']))->assertOk();
        $this->assertSame('Yeni-Parola-2', $hedef->refresh()->gizli_bilgiler['sifre']);
    }

    public function test_yalniz_basarisiz_kopyasi_olan_hedefin_konumu_degisebilir(): void
    {
        $id = $this->postJson(self::K, $this->ftpGovdesi(['klasor' => '/emor']))->json('data.id');
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $id, 'durum' => KopyaDurumu::Basarisiz, 'sifreli' => true]);

        $this->putJson(self::K.'/'.$id, $this->ftpGovdesi(['klasor' => '/emor2', 'sifre' => '']))->assertOk()->assertJsonPath('data.konum_kilitli_alanlar', []);
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: string}>
     */
    public static function nesneKilidiYanitlari(): iterable
    {
        yield 'etkin' => [200, '<ObjectLockConfiguration><ObjectLockEnabled>Enabled</ObjectLockEnabled></ObjectLockConfiguration>', 'etkin'];
        yield 'devre dışı' => [404, '<Error><Code>ObjectLockConfigurationNotFoundError</Code></Error>', 'devre_disi'];
        yield 'yetki yok' => [403, '<Error><Code>AccessDenied</Code></Error>', 'sorgulanamadi'];
    }

    #[DataProvider('nesneKilidiYanitlari')]
    public function test_s3_sinamasi_silinme_korumasini_bildirir(int $durum, string $govde, string $beklenen): void
    {
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, S3Baglayicisi::class);
        Http::preventStrayRequests();
        $kova = 'https://emor-yedek.s3.eu-central-1.amazonaws.com';
        $nesneler = [];
        // Bellekte bir kova: HEAD kova, PUT / GET / DELETE nesne, nesne kilidi sorgusu
        Http::fake(function (Request $istek) use (&$nesneler, $kova, $durum, $govde) {
            $yol = substr($istek->url(), strlen($kova));
            if ($yol === '/?object-lock=') {
                return Http::response($govde, $durum);
            }
            if ($istek->method() === 'PUT') {
                $nesneler[$yol] = $istek->body();

                return Http::response('', 200);
            }

            return match ($istek->method()) {
                'HEAD' => Http::response('', $yol === '/' ? 200 : 404),
                'GET' => isset($nesneler[$yol]) ? Http::response($nesneler[$yol], 200) : Http::response('', 404),
                'DELETE' => Http::response('', 204),
                default => Http::response('', 500),
            };
        });

        $yanit = $this->postJson(self::K.'/sina', ['tur' => 's3', 'alanlar' => [
            'uc_nokta' => 'https://s3.eu-central-1.amazonaws.com', 'bolge' => 'eu-central-1', 'kova' => 'emor-yedek',
            'erisim_anahtari' => 'AKIDORNEK123', 'gizli_anahtar' => 'gizli/Anahtar+0123456789',
        ]])->assertOk();

        $yanit->assertJsonPath('data.basarili', true)->assertJsonPath('data.silinme_korumasi', $beklenen);
    }

    public function test_s3_disindaki_hedefte_silinme_korumasi_yoktur(): void
    {
        $this->postJson(self::K.'/sina', ['tur' => 's3', 'alanlar' => ['kova' => 'k']])->assertOk()->assertJsonPath('data.silinme_korumasi', null);
    }

    public function test_tur_listesi_bagdayicilardan_gelir(): void
    {
        $turler = collect($this->getJson(self::K.'/turler')->assertOk()->json('data'))->keyBy('tur');

        $this->assertSame(['kova'], array_column($turler['s3']['alanlar'], 'ad'));
        $this->assertSame('yedekleme.hedef.uyari.duz_ftp', $turler['ftp']['uyari']);
        $this->assertTrue($turler['google_drive']['oauth']);
    }

    public function test_google_baglanti_akisi_durum_degerini_dogrular_yenileme_anahtarini_saklar_dondurmez(): void
    {
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir', 'app.url' => 'https://erp.ornek.test']);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.erisim', 'refresh_token' => 'yenileme-GIZLI', 'expires_in' => 3599, 'scope' => GoogleDriveBaglayicisi::KAPSAM, 'token_type' => 'Bearer']),
            'https://www.googleapis.com/drive/v3/about?fields=*' => Http::response(['user' => ['emailAddress' => 'yedek@ornek.test']]),
        ]);
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'durum' => 'yeniden_baglan']]);

        $adres = $this->postJson(self::K.'/'.$hedef->id.'/google/baglan')->assertOk()->json('data.adres');
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $sorgu);
        $this->assertSame('https://erp.ornek.test/ayarlar/yedek-hedefleri/google-donus', $sorgu['redirect_uri']);

        // Sahte durum değeri reddedilir ve oturumdaki değer tüketilir
        $this->postJson(self::K.'/google/donus', ['kod' => 'k', 'durum' => str_repeat('x', 48)])->assertStatus(422)->assertJsonPath('kod', 'GOOGLE_DURUM_GECERSIZ');
        Http::assertNothingSent();

        $adres = $this->postJson(self::K.'/'.$hedef->id.'/google/baglan')->json('data.adres');
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $sorgu);
        $yanit = $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $sorgu['state']])->assertOk();

        $this->assertStringNotContainsString('yenileme-GIZLI', (string) $yanit->getContent());
        $yanit->assertJsonPath('data.baglanti.eposta', 'yedek@ornek.test')->assertJsonPath('data.gizli_kayitli', []);
        $hedef->refresh();
        $this->assertSame('yenileme-GIZLI', $hedef->gizli_bilgiler['yenileme_anahtari']);
        $this->assertArrayNotHasKey('durum', $hedef->baglanti);
        // Aynı durum değeri ikinci kez kullanılamaz
        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $sorgu['state']])->assertStatus(422);
    }

    /** Google yapılandırması + sağlayıcı sahteleri; `$eposta` bağlanan hesap */
    private function googleSahtesi(string $eposta = 'yedek@ornek.test'): void
    {
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir', 'app.url' => 'https://erp.ornek.test']);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.erisim', 'refresh_token' => 'yenileme-GIZLI', 'expires_in' => 3599, 'scope' => GoogleDriveBaglayicisi::KAPSAM, 'token_type' => 'Bearer']),
            'https://www.googleapis.com/drive/v3/about?fields=*' => Http::response(['user' => ['emailAddress' => $eposta]]),
        ]);
    }

    /** "Google ile bağlan"ın verdiği durum değeri */
    private function googleDurumu(YedekHedefi $hedef): string
    {
        $adres = $this->postJson(self::K.'/'.$hedef->id.'/google/baglan')->assertOk()->json('data.adres');
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $sorgu);

        return $sorgu['state'];
    }

    private function tokenIstegiSayisi(): int
    {
        return Http::recorded(fn (Request $istek): bool => $istek->url() === 'https://oauth2.googleapis.com/token')->count();
    }

    public function test_google_donusu_suren_donus_varken_durumu_tuketmez_saglayiciya_tek_token_istegi_gider(): void
    {
        $this->googleSahtesi();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR']]);
        $durum = $this->googleDurumu($hedef);

        // Aynı değerle süren bir dönüş (kilit tutuluyor): ikinci dönüş sağlayıcıya gitmez, değeri tüketmez
        $kilit = Cache::lock(YedekHedefiController::googleDurumKilidi($durum), 10);
        $this->assertTrue($kilit->get());
        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $durum])->assertStatus(422)->assertJsonPath('kod', 'GOOGLE_DURUM_GECERSIZ');
        Http::assertNothingSent();
        $kilit->release();

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $durum])->assertOk();
        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $durum])->assertStatus(422);

        $this->assertSame(1, $this->tokenIstegiSayisi());
    }

    public function test_yanlis_oturumun_google_donusu_dogru_akisi_tuketmez(): void
    {
        $this->googleSahtesi();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR']]);
        $sahibi = User::factory()->yonetici()->create();
        $durum = $this->actingAs($sahibi)->googleDurumu($hedef);

        $this->actingAs(User::factory()->yonetici()->create())
            ->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $durum])->assertStatus(422)->assertJsonPath('kod', 'GOOGLE_DURUM_GECERSIZ');
        Http::assertNothingSent();

        $this->actingAs($sahibi)->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $durum])->assertOk();
        $this->assertSame('yenileme-GIZLI', $hedef->refresh()->gizli_bilgiler['yenileme_anahtari']);
    }

    public function test_kopyasi_olan_drive_hedefi_baska_google_hesabiyla_yeniden_baglanamaz_409(): void
    {
        $this->googleSahtesi('baska@ornek.test');
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'eposta' => 'yedek@ornek.test', 'durum' => 'yeniden_baglan']]);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'drive-dosya-1', 'sifreli' => true]);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])
            ->assertStatus(409)->assertJsonPath('kod', 'HEDEF_KONUMU_DEGISMEZ')->assertJsonPath('alanlar', ['eposta']);

        $hedef->refresh();
        $this->assertSame(['yedek@ornek.test', 'yeniden_baglan'], [$hedef->baglanti['eposta'], $hedef->baglanti['durum']]);
        $this->assertNull($hedef->gizli_bilgiler);
    }

    /** Yeni hesabın sahibi olduğu, çöpte olmayan, dosya eklenip listelenebilen kayıtlı klasör (BY-021-R4) */
    private const SAHIP_OLUNAN_KLASOR = ['id' => 'klasor-1', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false, 'ownedByMe' => true, 'capabilities' => ['canAddChildren' => true, 'canListChildren' => true]];

    /** Yeni hesabın sahibi olduğu, indirilebilen kayıtlı kopya (BY-021-R4) */
    private const SAHIP_OLUNAN_KOPYA = ['id' => 'drive-dosya-1', 'trashed' => false, 'ownedByMe' => true, 'capabilities' => ['canDownload' => true]];

    /**
     * Sağlayıcı sahteleri: hesap bilgisi (`about`), kayıtlı klasörün ve kayıtlı
     * kopyanın yeni bağlantıyla sorgusu. Dizi = 200 gövdesi, sayı = boş gövdeli
     * durum, 'baglanti' = bağlantı hatası; null verilen uç sahtelenmez (istek
     * giderse test düşer).
     *
     * @param  array<string, mixed>|int|string|null  $klasor
     * @param  array<string, mixed>|int|string|null  $kopya
     */
    private function googleYanitlari(int $hesapDurumu, array|int|string|null $klasor = null, array|int|string|null $kopya = null): void
    {
        Sleep::fake();
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir', 'app.url' => 'https://erp.ornek.test']);
        $yanit = fn (array|int|string|null $tanim) => match (true) {
            $tanim === null => null,
            $tanim === 'baglanti' => Http::failedConnection(),
            is_array($tanim) => Http::response($tanim),
            default => Http::response('', (int) $tanim),
        };
        Http::preventStrayRequests();
        Http::fake(array_filter([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.erisim', 'refresh_token' => 'yeni-anahtar', 'expires_in' => 3599, 'scope' => GoogleDriveBaglayicisi::KAPSAM, 'token_type' => 'Bearer']),
            'https://www.googleapis.com/drive/v3/about?fields=*' => $hesapDurumu === 200 ? Http::response(['user' => ['emailAddress' => 'yedek@ornek.test']]) : Http::response('', $hesapDurumu),
            'https://www.googleapis.com/drive/v3/files/klasor-1?*' => $yanit($klasor),
            'https://www.googleapis.com/drive/v3/files/drive-dosya-1?*' => $yanit($kopya),
        ]));
    }

    /**
     * @return iterable<string, array{0: string|null, 1: int, 2: int|null, 3: int, 4: string}>
     */
    public static function dogrulanamayanHesaplar(): iterable
    {
        // [kayıtlı e-posta, hesap bilgisi yanıtı, kayıtlı klasör sorgusunun yanıtı, beklenen durum, beklenen kod]
        yield 'hesap bilgisi alınamadı (geçici hata)' => ['yedek@ornek.test', 503, null, 422, 'GOOGLE_HESAP_OKUNAMADI'];
        yield 'kayıtlı hesap bilinmiyor, kayıtlı klasör yeni hesapta yok' => [null, 200, 404, 409, 'GOOGLE_HESAP_DOGRULANAMADI'];
        yield 'kayıtlı hesap bilinmiyor, klasör sorgusu geçici hata' => [null, 200, 503, 409, 'GOOGLE_HESAP_DOGRULANAMADI'];
    }

    /** BY-021-R2, R3 */
    #[DataProvider('dogrulanamayanHesaplar')]
    public function test_kopyali_drive_hedefinde_hesap_dogrulanamazsa_eski_anahtar_ve_klasor_degismez(?string $kayitliEposta, int $hesapDurumu, ?int $klasorDurumu, int $beklenenDurum, string $beklenenKod): void
    {
        $this->googleYanitlari($hesapDurumu, $klasorDurumu);
        $baglanti = ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'durum' => 'yeniden_baglan', ...($kayitliEposta === null ? [] : ['eposta' => $kayitliEposta])];
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => $baglanti, 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'drive-dosya-1', 'sifreli' => true]);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])
            ->assertStatus($beklenenDurum)->assertJsonPath('kod', $beklenenKod);

        $hedef->refresh();
        $this->assertEquals($baglanti, $hedef->baglanti);
        $this->assertSame(['yenileme_anahtari' => 'eski-anahtar'], $hedef->gizli_bilgiler);
        $this->assertSame($beklenenKod, YedekIslemKaydi::query()->where('islem', 'hedef_google_baglan')->sole()->hata_mesaji);
    }

    /**
     * BY-021-R3: ilk bağlantıda hesap kimliği okunamazsa hiçbir şey kaydedilmez
     * (kimliksiz hedef kopya alıp sonra yenilenemez hâle düşmesin); yeniden
     * "Google ile bağlan" denenebilir.
     */
    public function test_ilk_google_baglantisinda_hesap_okunamazsa_hicbir_sey_kaydedilmez_yeniden_denenebilir(): void
    {
        Sleep::fake();
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir', 'app.url' => 'https://erp.ornek.test']);
        $hesapOkunur = false;
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) use (&$hesapOkunur) {
            if ($istek->url() === 'https://oauth2.googleapis.com/token') {
                return Http::response(['access_token' => 'ya29.erisim', 'refresh_token' => 'yeni-anahtar', 'expires_in' => 3599, 'scope' => GoogleDriveBaglayicisi::KAPSAM, 'token_type' => 'Bearer']);
            }

            return str_starts_with($istek->url(), 'https://www.googleapis.com/drive/v3/about?') && $hesapOkunur
                ? Http::response(['user' => ['emailAddress' => 'yedek@ornek.test']])
                : Http::response('', 503);
        });
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR']]);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])
            ->assertStatus(422)->assertJsonPath('kod', 'GOOGLE_HESAP_OKUNAMADI');

        $hedef->refresh();
        $this->assertSame(['klasor_adi' => 'eMOR'], $hedef->baglanti);
        $this->assertNull($hedef->gizli_bilgiler);

        $hesapOkunur = true;
        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu-2', 'durum' => $this->googleDurumu($hedef)])->assertOk();
        $hedef->refresh();
        $this->assertSame(['yedek@ornek.test', 'yeni-anahtar'], [$hedef->baglanti['eposta'], $hedef->gizli_bilgiler['yenileme_anahtari']]);
    }

    /**
     * BY-021-R3, R4: hesabı kayıtlı olmayan (eski) kopyalı hedefte yeni hesap
     * kayıtlı klasörün sahibiyse (çöpte değil, dosya eklenip listelenebiliyor)
     * ve en son kayıtlı kopyanın da sahibiyse (indirilebiliyor) aynı hesap
     * sayılır: hesap e-postası doldurulur, klasör (ve içindeki kopyalar) korunur.
     */
    public function test_kimliksiz_eski_kopyali_drive_hedefi_kayitli_klasorun_ve_kopyanin_sahibi_hesapla_yeniden_baglanir_klasor_korunur(): void
    {
        $this->googleYanitlari(200, self::SAHIP_OLUNAN_KLASOR, self::SAHIP_OLUNAN_KOPYA);
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'durum' => 'yeniden_baglan'], 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'drive-dosya-1', 'sifreli' => true]);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])->assertOk();

        $hedef->refresh();
        $this->assertEquals(['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'eposta' => 'yedek@ornek.test'], $hedef->baglanti);
        $this->assertSame(['yenileme_anahtari' => 'yeni-anahtar'], $hedef->gizli_bilgiler);
        foreach (['klasor-1', 'drive-dosya-1'] as $kimlik) {
            Http::assertSent(fn (Request $istek): bool => str_starts_with($istek->url(), "https://www.googleapis.com/drive/v3/files/{$kimlik}?") && $istek->hasHeader('Authorization', 'Bearer ya29.erisim'));
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>|int|string, 1: array<string, mixed>|int|string|null}>
     */
    public static function sahipligiKanitlanamayanBaglantilar(): iterable
    {
        // [kayıtlı klasörün yeni bağlantıyla sorgusu, kayıtlı kopyanın sorgusu]
        yield 'klasör paylaşılmış, başka hesabın (200, ownedByMe false)' => [['ownedByMe' => false] + self::SAHIP_OLUNAN_KLASOR, self::SAHIP_OLUNAN_KOPYA];
        yield 'klasörün sahiplik bilgisi yok (paylaşılan drive)' => [array_diff_key(self::SAHIP_OLUNAN_KLASOR, ['ownedByMe' => true]), self::SAHIP_OLUNAN_KOPYA];
        yield 'klasör çöpte' => [['trashed' => true] + self::SAHIP_OLUNAN_KLASOR, self::SAHIP_OLUNAN_KOPYA];
        yield 'klasöre dosya eklenemiyor' => [['capabilities' => ['canAddChildren' => false, 'canListChildren' => true]] + self::SAHIP_OLUNAN_KLASOR, self::SAHIP_OLUNAN_KOPYA];
        yield 'klasör listelenemiyor' => [['capabilities' => ['canAddChildren' => true, 'canListChildren' => false]] + self::SAHIP_OLUNAN_KLASOR, self::SAHIP_OLUNAN_KOPYA];
        yield 'klasör sorgusunda bağlantı hatası' => ['baglanti', null];
        yield 'kayıtlı kopyaya erişilemiyor' => [self::SAHIP_OLUNAN_KLASOR, 404];
        yield 'kayıtlı kopya başka hesabın' => [self::SAHIP_OLUNAN_KLASOR, ['ownedByMe' => false] + self::SAHIP_OLUNAN_KOPYA];
        yield 'kayıtlı kopya indirilemiyor' => [self::SAHIP_OLUNAN_KLASOR, ['capabilities' => ['canDownload' => false]] + self::SAHIP_OLUNAN_KOPYA];
        yield 'kayıtlı kopya çöpte' => [self::SAHIP_OLUNAN_KLASOR, ['trashed' => true] + self::SAHIP_OLUNAN_KOPYA];
        yield 'kayıtlı kopya sorgusunda bağlantı hatası' => [self::SAHIP_OLUNAN_KLASOR, 'baglanti'];
    }

    /**
     * BY-021-R4: Drive'da erişim sahiplik değildir — paylaşılmış klasör başka
     * hesaptan da 200 döner. Kimliksiz eski hedefte klasörün sahipliği,
     * kullanılabilirliği ve kayıtlı kopyaya erişim kanıtlanamazsa eski bağlantı
     * (anahtar, klasör, durum) aynen kalır; elle uzlaştırma istenir.
     *
     * @param  array<string, mixed>|int|string  $klasor
     * @param  array<string, mixed>|int|string|null  $kopya
     */
    #[DataProvider('sahipligiKanitlanamayanBaglantilar')]
    public function test_kimliksiz_eski_drive_hedefinde_klasor_sahipligi_ya_da_kayitli_kopya_kanitlanamazsa_eski_baglanti_degismez(array|int|string $klasor, array|int|string|null $kopya): void
    {
        $this->googleYanitlari(200, $klasor, $kopya);
        $baglanti = ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'durum' => 'yeniden_baglan'];
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => $baglanti, 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'drive-dosya-1', 'sifreli' => true]);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])
            ->assertStatus(409)->assertJsonPath('kod', 'GOOGLE_HESAP_DOGRULANAMADI');

        $hedef->refresh();
        $this->assertEquals($baglanti, $hedef->baglanti);
        $this->assertSame(['yenileme_anahtari' => 'eski-anahtar'], $hedef->gizli_bilgiler);
        $this->assertSame('GOOGLE_HESAP_DOGRULANAMADI', YedekIslemKaydi::query()->where('islem', 'hedef_google_baglan')->sole()->hata_mesaji);
    }

    /**
     * BY-021-R4: kopyası henüz aktarılıyor (uzak kimliği yok) olan kimliksiz
     * eski hedefte sorulacak kayıtlı kopya yoktur; klasörün sahipliği yeter.
     */
    public function test_kimliksiz_eski_drive_hedefinde_uzak_kimlikli_kopya_yoksa_klasor_sahipligiyle_baglanir(): void
    {
        $this->googleYanitlari(200, self::SAHIP_OLUNAN_KLASOR);
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'durum' => 'yeniden_baglan'], 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Kopyalaniyor, 'uzak_kimlik' => null, 'sifreli' => true]);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])->assertOk();

        $hedef->refresh();
        $this->assertEquals(['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'eposta' => 'yedek@ornek.test'], $hedef->baglanti);
        $this->assertSame(['yenileme_anahtari' => 'yeni-anahtar'], $hedef->gizli_bilgiler);
    }

    /** BY-021-R2: aynı hesap yeniden bağlanınca klasör (ve içindeki kopyalar) aynen kullanılır */
    public function test_ayni_google_hesabiyla_yeniden_baglaninca_klasor_kimligi_korunur(): void
    {
        $this->googleSahtesi('yedek@ornek.test');
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'eposta' => 'yedek@ornek.test', 'durum' => 'yeniden_baglan'], 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => 'drive-dosya-1', 'sifreli' => true]);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])->assertOk();

        $hedef->refresh();
        $this->assertEquals(['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'eposta' => 'yedek@ornek.test'], $hedef->baglanti);
        $this->assertSame('yenileme-GIZLI', $hedef->gizli_bilgiler['yenileme_anahtari']);
    }

    /**
     * Yükseltilmiş kurulumdaki eski kopya: eski şemada (konum izi yok) `tamam`
     * kayıt, gerçek ileri migration onu `konum_belirsiz` yapar (BY-028-R5).
     */
    private function yukseltilmisEskiKopya(YedekHedefi $hedef): YedekKopyasi
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_21_112737_add_konum_izi_to_yedek_kopyalari_table.php');
        $yedekId = Yedek::factory()->create()->id;
        $migration->down();
        DB::table('yedek_kopyalari')->insert(['yedek_id' => $yedekId, 'yedek_hedefi_id' => $hedef->id, 'durum' => 'tamam', 'uzak_kimlik' => 'drive-dosya-1', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $kopya = YedekKopyasi::query()->where('yedek_hedefi_id', $hedef->id)->sole();
        $this->assertSame([KopyaDurumu::KonumBelirsiz, null], [$kopya->durum, $kopya->konum_izi]);

        return $kopya;
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>|int|null}>
     */
    public static function yukseltilenKopyadaKanitlanamayanSahiplikler(): iterable
    {
        // [kayıtlı klasörün yeni bağlantıyla sorgusu, yükseltilen kopyanın sorgusu]
        yield 'klasör başka hesabın' => [['ownedByMe' => false] + self::SAHIP_OLUNAN_KLASOR, null];
        yield 'klasörün sahibi, yükseltilen kopya başka hesabın' => [self::SAHIP_OLUNAN_KLASOR, ['ownedByMe' => false] + self::SAHIP_OLUNAN_KOPYA];
        yield 'klasörün sahibi, yükseltilen kopyaya erişilemiyor' => [self::SAHIP_OLUNAN_KLASOR, 404];
    }

    /**
     * BY-021-R5: yükseltmede `konum_belirsiz` olan eski kopyanın dosyası bağlı
     * hesapta durur; konum kilidini tutmasa da hesap değişikliği kararında
     * korunur. Kimliksiz eski hedefte klasörün ve o kopyanın sahipliği
     * kanıtlanamazsa eski bağlantı (anahtar, klasör, durum) aynen kalır.
     *
     * @param  array<string, mixed>  $klasor
     * @param  array<string, mixed>|int|null  $kopya
     */
    #[DataProvider('yukseltilenKopyadaKanitlanamayanSahiplikler')]
    public function test_yukseltmede_konum_belirsiz_olan_eski_kopyali_kimliksiz_drive_hedefi_sahiplik_kanitlanamazsa_409_eski_baglanti_degismez(array $klasor, array|int|null $kopya): void
    {
        $this->googleYanitlari(200, $klasor, $kopya);
        $baglanti = ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'durum' => 'yeniden_baglan'];
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => $baglanti, 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        $eskiKopya = $this->yukseltilmisEskiKopya($hedef);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])
            ->assertStatus(409)->assertJsonPath('kod', 'GOOGLE_HESAP_DOGRULANAMADI');

        $hedef->refresh();
        $this->assertEquals($baglanti, $hedef->baglanti);
        $this->assertSame(['yenileme_anahtari' => 'eski-anahtar'], $hedef->gizli_bilgiler);
        $this->assertSame(KopyaDurumu::KonumBelirsiz, $eskiKopya->refresh()->durum);
    }

    /** BY-021-R5: yükseltilen kopyanın ve klasörün sahibi olan hesap kabul edilir; kopyanın sahipliği yeni anahtarla sorulur */
    public function test_yukseltmede_konum_belirsiz_olan_eski_kopyali_kimliksiz_drive_hedefi_klasorun_ve_kopyanin_sahibi_hesapla_baglanir(): void
    {
        $this->googleYanitlari(200, self::SAHIP_OLUNAN_KLASOR, self::SAHIP_OLUNAN_KOPYA);
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'durum' => 'yeniden_baglan'], 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        $this->yukseltilmisEskiKopya($hedef);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])->assertOk();

        $hedef->refresh();
        $this->assertEquals(['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'eposta' => 'yedek@ornek.test'], $hedef->baglanti);
        $this->assertSame(['yenileme_anahtari' => 'yeni-anahtar'], $hedef->gizli_bilgiler);
        Http::assertSent(fn (Request $istek): bool => str_starts_with($istek->url(), 'https://www.googleapis.com/drive/v3/files/drive-dosya-1?') && $istek->hasHeader('Authorization', 'Bearer ya29.erisim'));
    }

    /**
     * BY-021-R5: kanıt kayıttan önce kilit altında yeniden eşlenir. Sahiplik
     * sorulurken hedefe daha yeni bir fiziksel kopya eklendiyse sorulan kopya
     * artık en son kopya değildir: kanıt geçersiz, eski bağlantı aynen kalır.
     */
    public function test_sahiplik_sorulurken_hedefe_yeni_kopya_eklenirse_kanit_gecersiz_409_eski_baglanti_degismez(): void
    {
        Sleep::fake();
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir', 'app.url' => 'https://erp.ornek.test']);
        $baglanti = ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'durum' => 'yeniden_baglan'];
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => $baglanti, 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::KonumBelirsiz, 'uzak_kimlik' => 'drive-dosya-1', 'sifreli' => true]);
        $yeniYedekId = Yedek::factory()->create()->id;
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) use ($hedef, $yeniYedekId) {
            return match (true) {
                $istek->url() === 'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.erisim', 'refresh_token' => 'yeni-anahtar', 'expires_in' => 3599, 'scope' => GoogleDriveBaglayicisi::KAPSAM, 'token_type' => 'Bearer']),
                str_starts_with($istek->url(), 'https://www.googleapis.com/drive/v3/about?') => Http::response(['user' => ['emailAddress' => 'yedek@ornek.test']]),
                str_starts_with($istek->url(), 'https://www.googleapis.com/drive/v3/files/klasor-1?') => Http::response(self::SAHIP_OLUNAN_KLASOR),
                // Kopyanın sahipliği sorulurken dizin taraması daha yeni bir kopya ekliyor
                str_starts_with($istek->url(), 'https://www.googleapis.com/drive/v3/files/drive-dosya-1?') => tap(Http::response(self::SAHIP_OLUNAN_KOPYA), fn () => YedekKopyasi::query()->create(['yedek_id' => $yeniYedekId, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::KonumBelirsiz, 'uzak_kimlik' => 'drive-dosya-2', 'sifreli' => true])),
                default => Http::response('', 500),
            };
        });

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])
            ->assertStatus(409)->assertJsonPath('kod', 'GOOGLE_HESAP_DOGRULANAMADI');

        $hedef->refresh();
        $this->assertEquals($baglanti, $hedef->baglanti);
        $this->assertSame(['yenileme_anahtari' => 'eski-anahtar'], $hedef->gizli_bilgiler);
    }

    /**
     * BY-021-R5 (G1): hesabı kayıtlı hedefte de yükseltilen eski kopya hesap
     * değişikliğini engeller; dosyası kayıtlı hesapta kalırdı.
     */
    public function test_yukseltmede_konum_belirsiz_olan_eski_kopyali_drive_hedefi_baska_google_hesabiyla_yeniden_baglanamaz_409(): void
    {
        $this->googleSahtesi('baska@ornek.test');
        $baglanti = ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'eposta' => 'yedek@ornek.test', 'durum' => 'yeniden_baglan'];
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => $baglanti, 'gizli_bilgiler' => ['yenileme_anahtari' => 'eski-anahtar']]);
        $this->yukseltilmisEskiKopya($hedef);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])
            ->assertStatus(409)->assertJsonPath('kod', 'HEDEF_KONUMU_DEGISMEZ')->assertJsonPath('alanlar', ['eposta']);

        $hedef->refresh();
        $this->assertEquals($baglanti, $hedef->baglanti);
        $this->assertSame(['yenileme_anahtari' => 'eski-anahtar'], $hedef->gizli_bilgiler);
    }

    /**
     * BY-021-R5: konum izi yeni hesabı gösteren `konum_belirsiz` kopya geride
     * kalmaz — o hesaba dönmek kopyanın konumunu düzeltir (konum düzeltme yolu
     * açık kalır); klasör yeni hesapta yeniden aranır.
     */
    public function test_konum_izi_yeni_hesabi_gosteren_konum_belirsiz_kopya_hesap_degisikligini_engellemez(): void
    {
        $this->googleSahtesi('baska@ornek.test');
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'klasor_kimligi' => 'klasor-1', 'eposta' => 'yedek@ornek.test']]);
        $eskiYer = clone $hedef;
        $eskiYer->baglanti = [...$hedef->baglanti, 'eposta' => 'baska@ornek.test'];
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::KonumBelirsiz, 'uzak_kimlik' => 'drive-dosya-1', 'sifreli' => true, 'konum_izi' => app(HedefKonumu::class)->iz($eskiYer)]);

        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $this->googleDurumu($hedef)])->assertOk();

        $this->assertEquals(['klasor_adi' => 'eMOR', 'eposta' => 'baska@ornek.test'], $hedef->refresh()->baglanti);
    }
}
