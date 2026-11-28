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
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\S3Baglayicisi;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\YedekDeposu;
use Closure;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
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
     * Kayıtlı hedefin türü ve bağlantısı ile yeni yerel hedefin klasörü; null = klasör boş (yedek klasörünün kendisi).
     *
     * @return iterable<string, array{0: HedefTuru, 1: array<string, string|null>, 2: string|null}>
     */
    public static function ayniDepoyuGosterenHedefler(): iterable
    {
        yield 'aynı klasör, farklı ad' => [HedefTuru::Yerel, ['klasor' => '{kok}/disk2'], '{kok}/disk2'];
        yield 'noktalı yazım' => [HedefTuru::Yerel, ['klasor' => '{kok}/disk2'], '{kok}/disk2/.'];
        yield 'henüz olmayan klasörün noktalı yazımı' => [HedefTuru::Yerel, ['klasor' => '{kok}/yok'], '{kok}/yok/.'];
        yield 'sembolik bağlantı' => [HedefTuru::Yerel, ['klasor' => '{kok}/disk2'], '{kok}/bag'];
        yield 'ikinci ana klasör hedefi' => [HedefTuru::Yerel, ['klasor' => null], null];
        yield 'ana klasörün başka yazımı' => [HedefTuru::Yerel, ['klasor' => null], '{kok}/yedekler/../yedekler'];
        yield 'NAS hedefinin klasörü' => [HedefTuru::Nas, ['baglanti_noktasi' => '{kok}', 'klasor' => '{kok}/disk2'], '{kok}/disk2'];
    }

    /**
     * BY-010-R7: aynı fiziksel depoyu gösteren ikinci hedef kaydedilmez; iki
     * hedefte aynı ad aynı dosyadır.
     *
     * @param  array<string, string|null>  $kayitliBaglanti
     */
    #[DataProvider('ayniDepoyuGosterenHedefler')]
    public function test_ayni_fiziksel_depoyu_gosteren_ikinci_hedef_409_kaydedilmez(HedefTuru $kayitliTur, array $kayitliBaglanti, ?string $yeniKlasor): void
    {
        mkdir($this->yedekKoku.'/disk2');
        symlink($this->yedekKoku.'/disk2', $this->yedekKoku.'/bag');
        $yol = fn (?string $y): ?string => $y === null ? null : str_replace('{kok}', $this->yedekKoku, $y);
        YedekHedefi::factory()->create(['ad' => 'Disk 2', 'tur' => $kayitliTur, 'baglanti' => array_map($yol, $kayitliBaglanti)]);

        $this->postJson(self::K, ['ad' => 'Disk 2 (yine)', 'tur' => 'yerel', 'alanlar' => ['klasor' => $yol($yeniKlasor)]])
            ->assertStatus(409)
            ->assertJsonPath('kod', 'HEDEF_AYNI_DEPO')
            ->assertJsonPath('hedefler', ['Disk 2'])
            ->assertJsonPath('mesaj', __('hata.yedek_hedefi_ayni_depo', ['hedefler' => 'Disk 2']));

        $this->assertSame(1, YedekHedefi::query()->count());
    }

    /** BY-010-R7: alt klasör ayrı depodur; konumu değişen güncelleme de denetlenir, konumu değişmeyen eski çakışan hedef pasife alınabilir */
    public function test_alt_klasor_ayri_hedef_olur_konumu_baska_hedefin_deposuna_cevrilemez_eski_cakisan_hedef_pasife_alinir(): void
    {
        $disk = $this->yedekKoku.'/disk2';
        mkdir($disk);
        $govde = fn (string $ad, string $klasor, bool $aktif = true): array => ['ad' => $ad, 'tur' => 'yerel', 'aktif' => $aktif, 'alanlar' => ['klasor' => $klasor]];
        $this->postJson(self::K, $govde('Disk 2', $disk))->assertCreated();
        $alt = $this->postJson(self::K, $govde('Alt klasör', $disk.'/alt'))->assertCreated()->json('data.id');

        $this->putJson(self::K.'/'.$alt, $govde('Alt klasör', $disk.'/alt/..'))->assertStatus(409)->assertJsonPath('kod', 'HEDEF_AYNI_DEPO');
        $this->assertSame($disk.'/alt', YedekHedefi::query()->findOrFail($alt)->baglanti['klasor']);

        // Eskiden kalmış çakışan tanım: konumu değişmeden adı ve aktifliği değişir (çözüm yolu)
        $eski = YedekHedefi::factory()->create(['ad' => 'Eski çakışan', 'baglanti' => ['klasor' => $disk.'/.']]);
        $this->putJson(self::K.'/'.$eski->id, $govde('Eski çakışan (kullanmayın)', $disk.'/.', aktif: false))->assertOk();
        $this->assertSame(['Eski çakışan (kullanmayın)', false], [$eski->refresh()->ad, $eski->aktif]);
    }

    /**
     * BY-010-R7, G18: denetim ile kayıt tek adım. Başka bir istek kilidi
     * tutarken aynı klasörü kaydediyorsa bu istek onu bekler ve görür.
     */
    public function test_eszamanli_kayitta_ayni_klasoru_ikinci_kez_tanimlayan_istek_bekler_ve_409_alir(): void
    {
        $disk = $this->yedekKoku.'/disk2';
        mkdir($disk);
        $kilit = Cache::lock(YedekHedefiController::DEPO_KILIDI, 10);
        $this->assertTrue($kilit->get());
        Sleep::fake();
        Sleep::whenFakingSleep(function () use ($kilit, $disk): void {
            if (! YedekHedefi::query()->exists()) {
                YedekHedefi::factory()->create(['ad' => 'Önce gelen', 'baglanti' => ['klasor' => $disk]]);
                $kilit->release();
            }
        });

        $this->postJson(self::K, ['ad' => 'Sonra gelen', 'tur' => 'yerel', 'alanlar' => ['klasor' => $disk]])
            ->assertStatus(409)->assertJsonPath('hedefler', ['Önce gelen']);

        $this->assertSame(['Önce gelen'], YedekHedefi::query()->pluck('ad')->all());
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
     * açık kalır); klasör eski hesaptadır, yeni hesapta yeniden seçilir.
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

    // --- Klasör seçimi (Google Picker) -------------------------------------

    private const G_TOKEN = 'https://oauth2.googleapis.com/token';

    private const G_KLASOR = 'https://www.googleapis.com/drive/v3/files/secilen_1?fields=*';

    private const G_YENI_KLASOR = 'https://www.googleapis.com/drive/v3/files?fields=id';

    private const G_DENEME = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id';

    private const G_DENEME_SIL = 'https://www.googleapis.com/drive/v3/files/deneme_1';

    /** Hesabı ve yenileme anahtarı kayıtlı, klasörü seçilmemiş Drive hedefi */
    private function bagliDriveHedefi(string $ad = 'Drive', array $baglanti = []): YedekHedefi
    {
        return YedekHedefi::factory()->create([
            'ad' => $ad, 'tur' => HedefTuru::GoogleDrive,
            'baglanti' => ['eposta' => 'yedek@ornek.test', ...$baglanti],
            'gizli_bilgiler' => ['yenileme_anahtari' => 'yenileme-GIZLI'],
        ]);
    }

    /**
     * Google yapılandırması + klasör sorgusu, deneme dosyası yazma/silme sahteleri.
     *
     * @param  array<string, mixed>  $klasor  Seçilen klasörün `files.get` yanıtına eklenecekler
     */
    private function klasorSahtesi(array $klasor = []): void
    {
        config([
            'yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir',
            'yedekleme.google.api_anahtari' => 'AIza-tarayici-anahtari', 'yedekleme.google.proje_numarasi' => '123456789012',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            self::G_TOKEN => Http::response(['access_token' => 'ya29.kisa-omurlu', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            self::G_KLASOR => Http::response(['id' => 'secilen_1', 'name' => 'Muhasebe Yedekleri', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false, 'ownedByMe' => true, 'capabilities' => ['canAddChildren' => true], ...$klasor]),
            self::G_YENI_KLASOR => Http::response(['id' => 'yeni_klasor']),
            self::G_DENEME => Http::response(['id' => 'deneme_1']),
            self::G_DENEME_SIL => Http::response('', 204),
        ]);
    }

    public function test_google_secici_bagli_hesabin_kisa_omurlu_erisim_anahtarini_doner_yenileme_anahtari_donmez_onbellege_alinmaz(): void
    {
        $this->klasorSahtesi();
        $hedef = $this->bagliDriveHedefi();

        $yanit = $this->postJson(self::K.'/'.$hedef->id.'/google/secici')->assertOk()
            ->assertExactJson(['data' => ['erisim_anahtari' => 'ya29.kisa-omurlu', 'api_anahtari' => 'AIza-tarayici-anahtari', 'proje_numarasi' => '123456789012', 'eposta' => 'yedek@ornek.test']]);

        $this->assertStringContainsString('no-store', (string) $yanit->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('yenileme-GIZLI', (string) $yanit->getContent());
        $kayit = YedekIslemKaydi::query()->where('islem', 'hedef_google_secici')->sole();
        $this->assertStringNotContainsString('ya29.kisa-omurlu', (string) json_encode($kayit->getAttributes()));
    }

    public function test_google_secici_api_anahtari_ya_da_proje_numarasi_yoksa_409_ve_googlea_istek_gitmez(): void
    {
        $this->klasorSahtesi();
        config(['yedekleme.google.proje_numarasi' => null]);
        $hedef = $this->bagliDriveHedefi();

        $this->postJson(self::K.'/'.$hedef->id.'/google/secici')->assertStatus(409)->assertJsonPath('kod', 'GOOGLE_SECICI_YAPILANDIRILMADI');
        Http::assertNothingSent();
    }

    public function test_google_secici_hesabi_bagli_olmayan_hedefte_409_yeniden_baglan(): void
    {
        $this->klasorSahtesi();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => []]);

        $this->postJson(self::K.'/'.$hedef->id.'/google/secici')->assertStatus(409)->assertJsonPath('kod', 'HEDEF_YENIDEN_BAGLAN');
        Http::assertNothingSent();
    }

    public function test_secilen_klasor_denenip_kaydedilir_adi_gosterilir_kopya_konumu_olusur(): void
    {
        $this->klasorSahtesi();
        $hedef = $this->bagliDriveHedefi();
        $this->assertNull(app(HedefKonumu::class)->iz($hedef));

        $this->postJson(self::K.'/'.$hedef->id.'/google/klasor', ['klasor_kimligi' => 'secilen_1'])->assertOk()
            ->assertJsonPath('data.baglanti.klasor_kimligi', 'secilen_1')
            ->assertJsonPath('data.baglanti.klasor_adi', 'Muhasebe Yedekleri');

        $hedef->refresh();
        $this->assertEquals(['eposta' => 'yedek@ornek.test', 'klasor_kimligi' => 'secilen_1', 'klasor_adi' => 'Muhasebe Yedekleri'], $hedef->baglanti);
        $this->assertNotNull(app(HedefKonumu::class)->iz($hedef));
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::G_DENEME);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::G_DENEME_SIL && $istek->method() === 'DELETE');
        $this->assertSame(['yeni' => false], array_intersect_key(YedekIslemKaydi::query()->where('islem', 'hedef_google_klasor')->sole()->ayrinti ?? [], ['yeni' => 1]));
    }

    public function test_yeni_klasor_drivein_kokune_acilip_kaydedilir(): void
    {
        $this->klasorSahtesi();
        $hedef = $this->bagliDriveHedefi();

        $this->postJson(self::K.'/'.$hedef->id.'/google/klasor', ['yeni_klasor_adi' => 'eMOR Yedekleri'])->assertOk()
            ->assertJsonPath('data.baglanti.klasor_kimligi', 'yeni_klasor')
            ->assertJsonPath('data.baglanti.klasor_adi', 'eMOR Yedekleri');

        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::G_YENI_KLASOR
            && json_decode($istek->body(), true) === ['name' => 'eMOR Yedekleri', 'mimeType' => 'application/vnd.google-apps.folder']);
    }

    public function test_klasor_dogrulanamazsa_422_hicbir_sey_kaydedilmez(): void
    {
        $this->klasorSahtesi(['ownedByMe' => false]);
        $hedef = $this->bagliDriveHedefi();

        $this->postJson(self::K.'/'.$hedef->id.'/google/klasor', ['klasor_kimligi' => 'secilen_1'])
            ->assertStatus(422)->assertJsonPath('kod', 'HEDEF_KLASOR_GECERSIZ');

        $this->assertEquals(['eposta' => 'yedek@ornek.test'], $hedef->refresh()->baglanti);
        Http::assertNotSent(fn (Request $istek): bool => $istek->url() === self::G_DENEME);
    }

    /** @return iterable<string, array{0: array<string, mixed>}> */
    public static function gecersizKlasorIstekleri(): iterable
    {
        yield 'ikisi de yok' => [[]];
        yield 'ikisi birden' => [['klasor_kimligi' => 'secilen_1', 'yeni_klasor_adi' => 'X']];
        yield 'biçimsiz kimlik' => [['klasor_kimligi' => '../about?fields=*']];
        yield 'denetim karakterli ad' => [['yeni_klasor_adi' => "a\nb"]];
    }

    /** @param  array<string, mixed>  $govde */
    #[DataProvider('gecersizKlasorIstekleri')]
    public function test_gecersiz_klasor_istegi_googlea_gitmeden_422(array $govde): void
    {
        $this->klasorSahtesi();
        $hedef = $this->bagliDriveHedefi();

        $this->postJson(self::K.'/'.$hedef->id.'/google/klasor', $govde)->assertStatus(422);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: KopyaDurumu}> */
    public static function klasoruKilitleyenKopyalar(): iterable
    {
        yield 'tamam' => [KopyaDurumu::Tamam];
        yield 'kopyalanıyor' => [KopyaDurumu::Kopyalaniyor];
        // Dosyası durabilir (G21): kilit yalnız tamam / kopyalanıyor'a bakar, klasör yine değişmemeli
        yield 'konum belirsiz' => [KopyaDurumu::KonumBelirsiz];
    }

    #[DataProvider('klasoruKilitleyenKopyalar')]
    public function test_kopyasi_duran_hedefte_baska_klasor_secilemez_409_googlea_istek_gitmez(KopyaDurumu $durum): void
    {
        $this->klasorSahtesi();
        $hedef = $this->bagliDriveHedefi(baglanti: ['klasor_kimligi' => 'eski_klasor', 'klasor_adi' => 'Eski']);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => $durum, 'uzak_kimlik' => 'drive-dosya-1', 'sifreli' => true, 'konum_izi' => app(HedefKonumu::class)->iz($hedef)]);

        $this->postJson(self::K.'/'.$hedef->id.'/google/klasor', ['klasor_kimligi' => 'secilen_1'])
            ->assertStatus(409)->assertJsonPath('kod', 'HEDEF_KONUMU_DEGISMEZ')->assertJsonPath('alanlar', ['klasor_kimligi']);
        $this->postJson(self::K.'/'.$hedef->id.'/google/klasor', ['yeni_klasor_adi' => 'Yeni'])->assertStatus(409);

        Http::assertNothingSent();
        $this->assertSame('eski_klasor', $hedef->refresh()->baglanti['klasor_kimligi']);
    }

    public function test_yalniz_basarisiz_kopyasi_olan_drive_hedefinin_klasoru_degisebilir(): void
    {
        $this->klasorSahtesi();
        $hedef = $this->bagliDriveHedefi(baglanti: ['klasor_kimligi' => 'eski_klasor']);
        YedekKopyasi::query()->create(['yedek_id' => Yedek::factory()->create()->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Basarisiz, 'sifreli' => true]);

        $this->postJson(self::K.'/'.$hedef->id.'/google/klasor', ['klasor_kimligi' => 'secilen_1'])->assertOk();

        $this->assertSame('secilen_1', $hedef->refresh()->baglanti['klasor_kimligi']);
    }

    public function test_baska_hedefin_klasoru_secilemez_409_ayni_depo(): void
    {
        $this->klasorSahtesi();
        $this->bagliDriveHedefi('Birinci', ['klasor_kimligi' => 'secilen_1']);
        $ikinci = $this->bagliDriveHedefi('İkinci');

        $this->postJson(self::K.'/'.$ikinci->id.'/google/klasor', ['klasor_kimligi' => 'secilen_1'])
            ->assertStatus(409)->assertJsonPath('kod', 'HEDEF_AYNI_DEPO')->assertJsonPath('hedefler', ['Birinci']);

        $this->assertArrayNotHasKey('klasor_kimligi', $ikinci->refresh()->baglanti);
    }

    public function test_klasor_denenirken_hedef_baska_hesaba_baglanirsa_klasor_kaydedilmez_409(): void
    {
        $this->klasorSahtesi();
        $hedef = $this->bagliDriveHedefi();
        Http::fake([
            self::G_TOKEN => Http::response(['access_token' => 'ya29.kisa-omurlu', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            self::G_KLASOR => Http::response(['id' => 'secilen_1', 'name' => 'X', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false, 'ownedByMe' => true, 'capabilities' => ['canAddChildren' => true]]),
            self::G_DENEME => function () use ($hedef) {
                // Deneme sürerken başka sekmede başka hesapla yeniden bağlandı
                YedekHedefi::query()->whereKey($hedef->id)->update(['baglanti' => json_encode(['eposta' => 'baska@ornek.test'])]);

                return Http::response(['id' => 'deneme_1']);
            },
            self::G_DENEME_SIL => Http::response('', 204),
        ]);

        $this->postJson(self::K.'/'.$hedef->id.'/google/klasor', ['klasor_kimligi' => 'secilen_1'])
            ->assertStatus(409)->assertJsonPath('kod', 'GOOGLE_HESAP_DEGISTI');

        $this->assertEquals(['eposta' => 'baska@ornek.test'], $hedef->refresh()->baglanti);
    }

    public function test_tur_listesi_google_yapilandirmasi_eksikse_drivei_hazir_degil_gosterir(): void
    {
        $hazirMi = fn (): bool => collect($this->getJson(self::K.'/turler')->assertOk()->json('data'))->keyBy('tur')['google_drive']['oauth_hazir'];

        config(['yedekleme.google.istemci_kimligi' => null, 'yedekleme.google.istemci_sirri' => null, 'yedekleme.google.api_anahtari' => null, 'yedekleme.google.proje_numarasi' => null]);
        $this->assertFalse($hazirMi());

        // Klasör seçme penceresinin anahtarı eksik: bağlanılsa da klasör seçilemezdi
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir']);
        $this->assertFalse($hazirMi());

        config(['yedekleme.google.api_anahtari' => 'AIza-anahtar', 'yedekleme.google.proje_numarasi' => '123456789012']);
        $this->assertTrue($hazirMi());

        $turler = collect($this->getJson(self::K.'/turler')->json('data'))->keyBy('tur');
        $this->assertFalse($turler['ftp']['oauth_hazir']);
    }

    public function test_drive_formunda_alan_yok_hedef_yalniz_adla_eklenir(): void
    {
        $turler = collect($this->getJson(self::K.'/turler')->assertOk()->json('data'))->keyBy('tur');
        $this->assertSame([], $turler['google_drive']['alanlar']);

        $this->postJson(self::K, ['ad' => 'Drive', 'tur' => 'google_drive', 'alanlar' => []])->assertCreated()
            ->assertJsonPath('data.baglanti', []);
        $this->postJson(self::K, ['ad' => 'Drive 2', 'tur' => 'google_drive', 'alanlar' => ['klasor_adi' => 'X']])->assertStatus(422);
    }

    /**
     * YF-07: Yerel kopya şifresiz yazılır; uygulama deposu (web'den sunulan
     * storage/app/public ve frontend/dist dahil) ve sistem klasörleri hedef olamaz. Gerçek
     * konuma bakılır: bağlantıyla oraya giden ve henüz olmayan alt klasör de.
     *
     * @return iterable<string, array{Closure(string): string}>
     */
    public static function yasakYerelKlasorler(): iterable
    {
        yield 'web\'den sunulan storage/app/public' => [fn (string $kok): string => storage_path('app/public')];
        yield 'uygulama ağacında henüz olmayan klasör' => [fn (string $kok): string => base_path('henuz-olmayan/alt')];
        // G1-1: nginx'in kökü backend'in dışında, depo kökünde (deploy.sh)
        yield 'depo kökünde web\'den sunulan frontend/dist' => [fn (string $kok): string => dirname(base_path()).'/frontend/dist'];
        yield 'uygulama ağacına giden bağlantının altı' => [function (string $kok): string {
            symlink(storage_path('app'), $kok.'/bag');

            return $kok.'/bag/public/yeni';
        }];
        yield '/proc' => [fn (string $kok): string => '/proc/emor-yedek'];
        yield '/run' => [fn (string $kok): string => '/run/emor-yedek'];
    }

    #[DataProvider('yasakYerelKlasorler')]
    public function test_uygulama_agacinda_ya_da_sistem_klasorunde_yerel_hedef_kaydedilmez(Closure $klasor): void
    {
        $this->postJson(self::K, ['ad' => 'Yasak', 'tur' => 'yerel', 'alanlar' => ['klasor' => $klasor($this->yedekKoku)]])
            ->assertStatus(422)->assertJsonValidationErrors('klasor', 'hatalar');

        $this->assertSame(0, YedekHedefi::query()->count());
    }

    public function test_uygulama_agacindaki_yedek_klasorunun_alti_ve_uygulama_disi_klasor_yerel_hedef_olabilir(): void
    {
        // Geçiş dönemindeki gibi yedek klasörü storage/app altında (dosya sistemine dokunulmaz)
        config(['yedekleme.klasor' => storage_path('app/yedekler-yf07-'.bin2hex(random_bytes(3)))]);

        $this->postJson(self::K, ['ad' => 'Alt', 'tur' => 'yerel', 'alanlar' => ['klasor' => config('yedekleme.klasor').'/ikinci']])->assertCreated();
        $this->postJson(self::K, ['ad' => 'Disk 2', 'tur' => 'yerel', 'alanlar' => ['klasor' => $this->yedekKoku.'/disk2']])->assertCreated();
    }

    /** YF-07: eskiden kaydedilmiş yasak klasörlü hedefe kopya yazılmaz (kalıcı hata, dosya bırakılmaz) */
    public function test_eskiden_kaydedilmis_uygulama_agacindaki_yerel_hedefe_yazilmaz(): void
    {
        $klasor = storage_path('framework/testing/emor-yf07-'.bin2hex(random_bytes(4)));
        mkdir($klasor, 0750, true);
        $kaynak = $this->yedekKoku.'/kaynak.dump';
        file_put_contents($kaynak, 'PGDMP-sifresiz');

        try {
            $hedef = YedekHedefi::factory()->create(['ad' => 'Eski', 'baglanti' => ['klasor' => $klasor]]);
            $baglayici = app(HedefKatalogu::class)->olustur($hedef);

            $hata = null;
            try {
                $baglayici->yaz($kaynak, 'erp_20261124.dump');
            } catch (HedefHatasi $e) {
                $hata = $e->kod;
            }

            $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata);
            $this->assertSame(['.', '..'], scandir($klasor));
        } finally {
            File::deleteDirectory($klasor);
        }
    }

    /**
     * YF-21: servis kipinde yedek klasörüne çalıştırıcının hesabı yazar, web
     * yalnız okur. Web'deki "Sına" yazma iznini kendi hesabıyla sınamaz:
     * yalnız varlık denetlenir, kalan adımlar "çalıştırıcıda" olarak döner.
     */
    public function test_servis_kipinde_yerel_hedef_sinamasi_yalniz_varligi_denetler_yazma_adimlarini_calistirmaz(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root her klasöre yazabilir; yazılamayan klasör kurgusu kurulamaz (T11).');
        }

        config(['yedekleme.calistirici' => 'servis']);
        $klasor = config('yedekleme.klasor');
        chmod($klasor, 0550);

        try {
            $yanit = $this->postJson(self::K.'/sina', ['tur' => 'yerel', 'alanlar' => ['klasor' => null]])->assertOk();

            $yanit->assertJsonPath('data.basarili', null);
            $this->assertSame(
                [['baglan', true, null], ['yaz', null, 'HEDEF_SINAMA_CALISTIRICIDA'], ['oku', null, 'HEDEF_SINAMA_CALISTIRICIDA'], ['dogrula', null, 'HEDEF_SINAMA_CALISTIRICIDA'], ['sil', null, 'HEDEF_SINAMA_CALISTIRICIDA']],
                array_map(fn (array $a): array => [$a['adim'], $a['basarili'], $a['kod']], $yanit->json('data.adimlar')),
            );
            $this->assertSame(['.', '..', YedekDeposu::ISARET], scandir($klasor));
            $this->assertNull(YedekIslemKaydi::query()->where('islem', 'hedef_sina')->sole()->hata_mesaji);

            // Klasör yoksa yine başarısız
            $this->postJson(self::K.'/sina', ['tur' => 'yerel', 'alanlar' => ['klasor' => $this->yedekKoku.'/yok']])
                ->assertOk()->assertJsonPath('data.basarili', false)->assertJsonPath('data.adimlar.0.kod', 'HEDEF_ERISILEMEDI');
        } finally {
            chmod($klasor, 0750);
        }
    }

    /** YF-50: evet / hayır alanı gerçek bool saklanır; boolean olmayan değer reddedilir */
    public function test_evet_hayir_alanlari_bool_saklanir_gecersiz_deger_reddedilir(): void
    {
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, S3Baglayicisi::class);
        $s3 = ['ad' => 'S3', 'tur' => 's3', 'alanlar' => [
            'uc_nokta' => 'https://minio.ornek.test', 'bolge' => 'us-east-1', 'kova' => 'emor', 'erisim_anahtari' => 'AKID123', 'gizli_anahtar' => 'gizli', 'yol_stili' => 1,
        ]];

        $id = $this->postJson(self::K, $s3)->assertCreated()->json('data.id');
        $this->assertTrue(YedekHedefi::query()->findOrFail($id)->baglanti['yol_stili']);

        $ftp = $this->postJson(self::K, $this->ftpGovdesi(['pasif' => '0']))->assertCreated()->json('data.id');
        $this->assertFalse(YedekHedefi::query()->findOrFail($ftp)->baglanti['pasif']);

        $this->postJson(self::K, [...$this->ftpGovdesi(['pasif' => ['a' => 1]]), 'ad' => 'FTP 2'])
            ->assertStatus(422)->assertJsonValidationErrors('pasif', 'hatalar');
        $this->postJson(self::K, [...$s3, 'ad' => 'S3 2', 'alanlar' => [...$s3['alanlar'], 'kova' => 'emor2', 'yol_stili' => 'evet']])
            ->assertStatus(422)->assertJsonValidationErrors('yol_stili', 'hatalar');
    }

    /** @return array<string, mixed> */
    private function sftpGovdesi(array $alanlar = []): array
    {
        return ['ad' => 'SFTP', 'tur' => 'sftp', 'alanlar' => [
            'sunucu' => 'sftp.ornek.test', 'port' => 22, 'kullanici_adi' => 'yedekci', 'klasor' => '/emor', ...$alanlar,
        ]];
    }

    /**
     * YF-49: kayıtlı isteğe bağlı sır yalnız `silinecek_sirlar` ile silinir
     * (boş alan "değişmedi"dir); SFTP'de en az bir kimlik kalmalı.
     */
    public function test_kayitli_istege_bagli_sir_silinebilir_en_az_bir_kimlik_kalmali(): void
    {
        $id = $this->postJson(self::K, $this->sftpGovdesi(['sifre' => 'Eski-Parola-1', 'ozel_anahtar' => 'ANAHTAR']))->assertCreated()->json('data.id');

        $this->putJson(self::K.'/'.$id, [...$this->sftpGovdesi(), 'silinecek_sirlar' => ['sifre']])
            ->assertOk()->assertJsonPath('data.gizli_kayitli.sifre', false)->assertJsonPath('data.gizli_kayitli.ozel_anahtar', true);

        $this->assertSame(['ozel_anahtar' => 'ANAHTAR'], YedekHedefi::query()->findOrFail($id)->gizli_bilgiler);
        $this->assertSame(['sifre'], YedekIslemKaydi::query()->where('islem', 'hedef_guncelle')->sole()->ayrinti['sir_silindi']);

        // Son kimlik silinemez; sır olmayan / zorunlu alan silinemez; aynı alana değer + silme çelişir
        $this->putJson(self::K.'/'.$id, [...$this->sftpGovdesi(), 'silinecek_sirlar' => ['ozel_anahtar']])
            ->assertStatus(422)->assertJsonValidationErrors('silinecek_sirlar', 'hatalar');
        $this->putJson(self::K.'/'.$id, [...$this->sftpGovdesi(), 'silinecek_sirlar' => ['sunucu']])->assertStatus(422);
        $this->putJson(self::K.'/'.$id, [...$this->sftpGovdesi(['ozel_anahtar' => 'YENI']), 'silinecek_sirlar' => ['ozel_anahtar']])
            ->assertStatus(422)->assertJsonValidationErrors('silinecek_sirlar', 'hatalar');
        $ftp = $this->postJson(self::K, $this->ftpGovdesi())->json('data.id');
        $this->putJson(self::K.'/'.$ftp, [...$this->ftpGovdesi(['sifre' => '']), 'silinecek_sirlar' => ['sifre']])->assertStatus(422);

        $this->assertSame(['ozel_anahtar' => 'ANAHTAR'], YedekHedefi::query()->findOrFail($id)->gizli_bilgiler);
        $this->assertSame('Gizli-Parola-1', YedekHedefi::query()->findOrFail($ftp)->gizli_bilgiler['sifre']);
    }

    /**
     * YF-49: "Sına" da `silinecek_sirlar`'ı kayıtla aynı kuralla alır ve silinecek
     * sırrın kayıtlı değerine düşmez — sınanan, kaydedilecek olandır.
     */
    public function test_sina_silinecek_sirri_kayitli_degerle_doldurmaz_ayni_kuralla_dogrular(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'SFTP', 'tur' => HedefTuru::Sftp, 'baglanti' => [
            'sunucu' => 'sftp.ornek.test', 'port' => 22, 'kullanici_adi' => 'yedekci', 'klasor' => '/emor',
            'sunucu_anahtari' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMe0NgRXiRgIHEKi16ZxwBWlZnAgh5lzMpXqkOF+T8Aq',
            'sunucu_parmak_izi' => 'SHA256:deneme', 'sunucu_anahtari_icin' => 'sftp.ornek.test:22',
        ], 'gizli_bilgiler' => ['sifre' => 'Eski-Parola-1', 'ozel_anahtar' => 'ANAHTAR']]);
        Process::fake(['*' => '']);

        $this->postJson(self::K.'/sina', [...$this->sftpGovdesi(), 'id' => $hedef->id, 'silinecek_sirlar' => ['sifre']])
            ->assertOk()->assertJsonPath('data.kaydedildi', false);

        // Parola gönderilmedi: ssh'e parola kimliği kapalı, askpass yok
        Process::assertRan(fn ($surec): bool => in_array('PasswordAuthentication=no', (array) $surec->command, true));
        Process::assertDidntRun(fn ($surec): bool => in_array('PasswordAuthentication=yes', (array) $surec->command, true));
        $this->assertSame(['sifre' => 'Eski-Parola-1', 'ozel_anahtar' => 'ANAHTAR'], $hedef->refresh()->gizli_bilgiler);

        $this->postJson(self::K.'/sina', [...$this->sftpGovdesi(), 'id' => $hedef->id, 'silinecek_sirlar' => ['sifre', 'ozel_anahtar']])
            ->assertStatus(422)->assertJsonValidationErrors('silinecek_sirlar', 'hatalar');
    }

    /** YF-33: denetim kaydı değişiklikle aynı işlemde — günlük yazılamazsa hedef eklenmez / silinmez */
    public function test_denetim_gunlugu_yazilamazsa_hedef_eklenmez_ve_silinmez_500(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Duran']);
        // Günlük klasörü düz bir dosyanın altında: kanal yazamaz (YedekApiTest ile aynı kurgu)
        touch($this->yedekKoku.'/dosya');
        config(['logging.channels.yedekleme.path' => $this->yedekKoku.'/dosya/yedekleme.log']);
        Log::forgetChannel('yedekleme');

        $this->postJson(self::K, $this->ftpGovdesi())->assertStatus(500);
        $this->deleteJson(self::K.'/'.$hedef->id)->assertStatus(500);

        $this->assertSame([$hedef->id], YedekHedefi::query()->pluck('id')->all());
        $this->assertSame(0, YedekIslemKaydi::query()->count());
    }

    /**
     * YF-48 + YF-55: "Bağlantıyı kes" yenileme anahtarını siler ve Google'da
     * iptal eder; iptal başarısız olsa da bağlantı kesilir, denetime yazılır.
     */
    public function test_google_baglantisi_kesilince_yenileme_anahtari_googleda_iptal_edilir_iptal_hatasi_kesmeyi_engellemez(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::sequence()->push('', 200)->push(['error' => 'invalid_token'], 400)]);
        $ilk = $this->bagliDriveHedefi('Drive 1', ['klasor_kimligi' => 'k1']);
        $ikinci = $this->bagliDriveHedefi('Drive 2', ['klasor_kimligi' => 'k2', 'eposta' => 'baska@ornek.test']);

        $this->postJson(self::K.'/'.$ilk->id.'/google/kes')->assertOk();
        $this->postJson(self::K.'/'.$ikinci->id.'/google/kes')->assertOk();

        Http::assertSent(fn (Request $istek): bool => $istek->url() === 'https://oauth2.googleapis.com/revoke' && $istek->data() === ['token' => 'yenileme-GIZLI']);
        $this->assertNull($ilk->refresh()->gizli_bilgiler);
        $this->assertNull($ikinci->refresh()->gizli_bilgiler);
        $this->assertEquals(['eposta' => 'baska@ornek.test', 'klasor_kimligi' => 'k2', 'durum' => 'yeniden_baglan'], $ikinci->baglanti);
        $iptaller = YedekIslemKaydi::query()->where('islem', 'hedef_google_iptal')->orderBy('id')->get();
        $this->assertSame([null, 'GOOGLE_IPTAL_EDILEMEDI'], $iptaller->pluck('hata_mesaji')->all());
    }

    /**
     * YF-55: "Bağlantıyı kes" ve "sunucu anahtarını sıfırla" satırı yeniden ve
     * kilit altında okur; rota bağlamasının okuduğu ile yazım arasında kaydedilen
     * değişiklik (ör. seçilen klasör) eski kopyayla ezilmez.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function kilitliYenidenOkuyanUclar(): iterable
    {
        yield 'Google bağlantısını kes' => ['google/kes', ['eposta' => 'yedek@ornek.test', 'klasor_kimligi' => 'eski']];
        yield 'SFTP sunucu anahtarını sıfırla' => ['sunucu-anahtari-sifirla', ['sunucu_parmak_izi' => 'SHA256:x', 'klasor_kimligi' => 'eski']];
    }

    /** @param  array<string, mixed>  $baglanti */
    #[DataProvider('kilitliYenidenOkuyanUclar')]
    public function test_baglanti_alanini_yazan_uc_araya_giren_degisikligi_ezmez(string $uc, array $baglanti): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Hedef', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => $baglanti, 'gizli_bilgiler' => ['yenileme_anahtari' => 'y']]);
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response('', 200)]);
        // Rota bağlaması hedefi okuduktan hemen sonra başka bir istek klasörü değiştirip kaydediyor
        $araya = false;
        YedekHedefi::retrieved(function (YedekHedefi $okunan) use (&$araya, $hedef): void {
            if (! $araya && $okunan->id === $hedef->id) {
                $araya = true;
                DB::table('yedek_hedefleri')->where('id', $hedef->id)->update(['baglanti' => json_encode([...$okunan->baglanti, 'klasor_kimligi' => 'yeni'])]);
            }
        });

        $this->postJson(self::K.'/'.$hedef->id.'/'.$uc)->assertOk();

        $this->assertTrue($araya);
        $this->assertSame('yeni', $hedef->refresh()->baglanti['klasor_kimligi']);
    }

    /** YF-48: aynı hesapla bağlı başka hedef varken iptal edilmez (Google izni bütünüyle kaldırabilir); silinen hedefin anahtarı iptal edilir */
    public function test_ayni_hesapla_bagli_baska_hedef_varken_anahtar_iptal_edilmez_son_hedef_silinince_iptal_edilir(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response('', 200)]);
        $ilk = $this->bagliDriveHedefi('Drive 1', ['klasor_kimligi' => 'k1']);
        $ikinci = $this->bagliDriveHedefi('Drive 2', ['klasor_kimligi' => 'k2']);

        $this->postJson(self::K.'/'.$ilk->id.'/google/kes')->assertOk();
        Http::assertNothingSent();

        $this->deleteJson(self::K.'/'.$ikinci->id)->assertNoContent();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === 'https://oauth2.googleapis.com/revoke' && $istek->data() === ['token' => 'yenileme-GIZLI']);
    }
}
