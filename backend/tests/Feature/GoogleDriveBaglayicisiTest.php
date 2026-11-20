<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\GoogleDriveBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BY-021: Google Drive hedefi — yalnız `drive.file` yetkisi, izin
 * kaldırılınca "yeniden bağlanın", kota dolu, sırların hiçbir hata mesajına
 * girmemesi, OAuth durum değerinin taşınması.
 */
final class GoogleDriveBaglayicisiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const YENILEME = 'yenileme-anahtari-1//0gGizliDeger';

    private const ERISIM = 'ya29.erisim-anahtari-gizli';

    private const ISTEMCI_SIRRI = 'GOCSPX-istemci-sirri';

    private const TOKEN = 'https://oauth2.googleapis.com/token';

    private const KLASOR = 'https://www.googleapis.com/drive/v3/files/klasor123?fields=id%2CmimeType%2Ctrashed';

    private const OTURUM_AC = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&fields=id';

    private const OTURUM = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&upload_id=oturum-1';

    private string $klasor;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'yedekleme.google.istemci_kimligi' => 'istemci-123.apps.googleusercontent.com',
            'yedekleme.google.istemci_sirri' => self::ISTEMCI_SIRRI,
        ]);
        $this->klasor = sys_get_temp_dir().'/emor-drive-test-'.bin2hex(random_bytes(5));
        mkdir($this->klasor, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->klasor);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $baglanti */
    private function hedef(array $baglanti = ['klasor_adi' => 'eMOR Yedekleri', 'klasor_kimligi' => 'klasor123']): YedekHedefi
    {
        return YedekHedefi::factory()->create([
            'ad' => 'Drive',
            'tur' => HedefTuru::GoogleDrive,
            'baglanti' => $baglanti,
            'gizli_bilgiler' => ['yenileme_anahtari' => self::YENILEME],
        ]);
    }

    private function baglayici(YedekHedefi $hedef): GoogleDriveBaglayicisi
    {
        $baglayici = app(HedefKatalogu::class)->olustur($hedef);
        $this->assertInstanceOf(GoogleDriveBaglayicisi::class, $baglayici);

        return $baglayici;
    }

    private static function tokenYaniti(): PromiseInterface
    {
        return Http::response(['access_token' => self::ERISIM, 'expires_in' => 3599, 'token_type' => 'Bearer', 'scope' => GoogleDriveBaglayicisi::KAPSAM]);
    }

    private static function klasorYaniti(): PromiseInterface
    {
        return Http::response(['id' => 'klasor123', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false]);
    }

    private static function kotaDoluYaniti(): PromiseInterface
    {
        return Http::response(['error' => [
            'code' => 403,
            'message' => "The user's Drive storage quota has been exceeded.",
            'errors' => [['domain' => 'usageLimits', 'reason' => 'storageQuotaExceeded', 'message' => 'Bearer '.self::ERISIM]],
        ]], 403);
    }

    private function hataBekle(Closure $islem): HedefHatasi
    {
        try {
            $islem();
        } catch (HedefHatasi $hata) {
            return $hata;
        }

        $this->fail('HedefHatasi bekleniyordu.');
    }

    private function yedekDosyasi(string $icerik = 'GPG-sifreli-yedek'): string
    {
        file_put_contents($this->klasor.'/yedek', $icerik);

        return $this->klasor.'/yedek';
    }

    public function test_izin_kaldirilinca_hedef_yeniden_baglan_olur_ve_yeniden_denenmez(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([self::TOKEN => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
        $hedef = $this->hedef();

        $hata = $this->hataBekle(fn () => $this->baglayici($hedef)->baglan());

        $this->assertSame(HedefHatasi::YENIDEN_BAGLAN, $hata->kod);
        $this->assertSame(['klasor_adi' => 'eMOR Yedekleri', 'klasor_kimligi' => 'klasor123', 'durum' => 'yeniden_baglan'], $hedef->fresh()->baglanti);
        Http::assertSentCount(1);
    }

    public function test_bu_arada_yeniden_baglanan_hedef_eski_anahtarin_reddiyle_isaretlenmez(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN => Http::response(['error' => 'invalid_grant'], 400)]);
        $hedef = $this->hedef();
        YedekHedefi::query()->findOrFail($hedef->id)->update(['gizli_bilgiler' => ['yenileme_anahtari' => 'yeni-baglantinin-anahtari']]);

        $hata = $this->hataBekle(fn () => $this->baglayici($hedef)->baglan());

        $this->assertSame(HedefHatasi::YENIDEN_BAGLAN, $hata->kod);
        $this->assertArrayNotHasKey('durum', $hedef->fresh()->baglanti);
    }

    public function test_yenilenen_anahtarla_yine_401_gelirse_hedef_yeniden_baglan_olur(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::KLASOR => Http::response(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401),
        ]);
        $hedef = $this->hedef();

        $hata = $this->hataBekle(fn () => $this->baglayici($hedef)->baglan());

        $this->assertSame(HedefHatasi::YENIDEN_BAGLAN, $hata->kod);
        $this->assertSame('yeniden_baglan', $hedef->fresh()->baglanti['durum']);
        Http::assertSentCount(4);
    }

    public function test_kota_dolu_yanit_kota_hatasi_olur_ve_dosya_gonderilmez(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::KLASOR => self::klasorYaniti(),
            self::OTURUM_AC => self::kotaDoluYaniti(),
        ]);

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef())->yaz($this->yedekDosyasi(), 'erp_20261121.dump.gpg'));

        $this->assertSame(HedefHatasi::KOTA, $hata->kod);
        $this->assertSame('Google Drive depolama kotası dolu; yükleme oturumu yapılamadı (HTTP 403, storageQuotaExceeded).', $hata->getMessage());
        Http::assertNotSent(fn (Request $istek): bool => $istek->method() === 'PUT');
    }

    /**
     * Karşı taraf hata gövdesinde sırları geri yansıtıyor (en kötü durum).
     *
     * @return iterable<string, array{0: array<string, array{0: int, 1: array<string, mixed>}>}>
     */
    public static function sirYansitanHatalar(): iterable
    {
        $tokenBasarili = [200, ['access_token' => self::ERISIM, 'expires_in' => 3599, 'token_type' => 'Bearer']];

        yield 'izin kaldırıldı' => [[self::TOKEN => [400, ['error' => 'invalid_grant', 'error_description' => self::YENILEME.' revoked']]]];
        yield 'istemci geçersiz' => [[self::TOKEN => [401, ['error' => 'invalid_client', 'error_description' => self::ISTEMCI_SIRRI]]]];
        yield 'kimlik sunucusu hatası' => [[self::TOKEN => [500, ['error' => 'server_error', 'error_description' => 'refresh_token='.self::YENILEME.' client_secret='.self::ISTEMCI_SIRRI]]]];
        yield 'Drive hatası' => [[
            self::TOKEN => $tokenBasarili,
            self::KLASOR => [400, ['error' => ['code' => 400, 'message' => 'Authorization: Bearer '.self::ERISIM, 'errors' => [['reason' => self::ERISIM]]]]],
        ]];
    }

    /** @param  array<string, array{0: int, 1: array<string, mixed>}>  $yanitlar */
    #[DataProvider('sirYansitanHatalar')]
    public function test_yenileme_erisim_ve_istemci_sirri_hicbir_hata_mesajinda_yer_almaz(array $yanitlar): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake(array_map(fn (array $yanit): PromiseInterface => Http::response($yanit[1], $yanit[0]), $yanitlar));

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef())->baglan());

        foreach ([self::YENILEME, self::ERISIM, self::ISTEMCI_SIRRI] as $sir) {
            $this->assertStringNotContainsString($sir, $hata->getMessage());
        }
    }

    public function test_yukleme_klasore_oturumla_yapilir_ve_dosya_kimligi_doner(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::KLASOR => self::klasorYaniti(),
            self::OTURUM_AC => Http::response('', 200, ['Location' => self::OTURUM]),
            self::OTURUM => Http::response(['id' => 'dosya789'], 200),
        ]);

        $kimlik = $this->baglayici($this->hedef())->yaz($this->yedekDosyasi(), 'erp_20261121.dump.gpg');

        $this->assertSame('dosya789', $kimlik);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::OTURUM_AC
            && $istek->hasHeader('Authorization', 'Bearer '.self::ERISIM)
            && $istek->hasHeader('X-Upload-Content-Length', '17')
            && json_decode($istek->body(), true) === ['name' => 'erp_20261121.dump.gpg', 'parents' => ['klasor123']]);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::OTURUM
            && $istek->method() === 'PUT'
            && $istek->body() === 'GPG-sifreli-yedek'
            && ! $istek->hasHeader('Authorization'));
    }

    public function test_oturum_adresi_google_disindaysa_dosya_gonderilmez(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::KLASOR => self::klasorYaniti(),
            self::OTURUM_AC => Http::response('', 200, ['Location' => 'https://www.googleapis.com.kotu.test/upload/drive/v3/files?upload_id=x']),
        ]);

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef())->yaz($this->yedekDosyasi(), 'erp_20261121.dump.gpg'));

        $this->assertSame(HedefHatasi::ERISILEMEDI, $hata->kod);
        Http::assertNotSent(fn (Request $istek): bool => $istek->method() === 'PUT');
    }

    public function test_icerik_yaniti_alinamazsa_oturum_sorulur_ve_ikinci_kopya_yuklenmez(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::KLASOR => self::klasorYaniti(),
            self::OTURUM_AC => Http::response('', 200, ['Location' => self::OTURUM]),
            self::OTURUM => Http::sequence()->push('', 503)->push(['id' => 'dosya789'], 200),
        ]);

        $kimlik = $this->baglayici($this->hedef())->yaz($this->yedekDosyasi(), 'erp_20261121.dump.gpg');

        $this->assertSame('dosya789', $kimlik);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::OTURUM && $istek->hasHeader('Content-Range', 'bytes */17') && $istek->body() === '');
        $this->assertCount(1, Http::recorded(fn (Request $istek): bool => $istek->url() === self::OTURUM && $istek->body() === 'GPG-sifreli-yedek'));
    }

    public function test_klasor_yoksa_acilir_ve_kimligi_hedefe_kaydedilir(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            'https://www.googleapis.com/drive/v3/files?q=*' => Http::response(['files' => []]),
            'https://www.googleapis.com/drive/v3/files?fields=id' => Http::response(['id' => 'yeniKlasor'], 200),
        ]);
        $hedef = $this->hedef(['klasor_adi' => "Ali'nin Yedekleri"]);

        $this->baglayici($hedef)->baglan();

        $this->assertSame(['klasor_adi' => "Ali'nin Yedekleri", 'klasor_kimligi' => 'yeniKlasor'], $hedef->fresh()->baglanti);
        Http::assertSent(fn (Request $istek): bool => $istek->method() === 'GET'
            && ($istek->data()['q'] ?? null) === "mimeType = 'application/vnd.google-apps.folder' and name = 'Ali\\'nin Yedekleri' and trashed = false");
        Http::assertSent(fn (Request $istek): bool => $istek->method() === 'POST'
            && json_decode($istek->body(), true) === ['name' => "Ali'nin Yedekleri", 'mimeType' => 'application/vnd.google-apps.folder']);
    }

    public function test_liste_yalniz_klasordeki_yedek_dosyalarinin_kimliklerini_sayfalayarak_dondurur(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::KLASOR => self::klasorYaniti(),
            'https://www.googleapis.com/drive/v3/files?q=*' => Http::sequence()
                ->push(['nextPageToken' => 'sayfa2', 'files' => [['id' => 'a1', 'name' => 'erp_a.dump.gpg'], ['id' => 'n1', 'name' => 'notlar.txt']]])
                ->push(['files' => [['id' => 'b1', 'name' => 'erp_b.dump'], ['id' => 'c1', 'name' => 'erp_c.dump.tmp']]]),
        ]);

        $liste = $this->baglayici($this->hedef())->listele();

        $this->assertSame(['a1', 'b1'], $liste);
        Http::assertSent(fn (Request $istek): bool => ($istek->data()['pageToken'] ?? null) === 'sayfa2'
            && $istek->data()['q'] === "'klasor123' in parents and trashed = false");
    }

    /** @return iterable<string, array{0: array<string, string>, 1: int|null}> */
    public static function kotaYanitlari(): iterable
    {
        yield 'sınırlı hesap' => [['limit' => '16106127360', 'usage' => '1073741824'], 15032385536];
        yield 'sınırsız hesap' => [['usage' => '1073741824'], null];
    }

    /** @param  array<string, string>  $kota */
    #[DataProvider('kotaYanitlari')]
    public function test_kalan_alan_kota_siniri_eksi_kullanimdir(array $kota, ?int $beklenen): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            'https://www.googleapis.com/drive/v3/about?fields=storageQuota' => Http::response(['storageQuota' => $kota]),
        ]);

        $kalan = $this->baglayici($this->hedef())->kalanAlan();

        $this->assertSame($beklenen, $kalan);
    }

    public function test_yetki_adresi_yalniz_drive_file_kapsamini_ister_ve_durum_degerini_tasir(): void
    {
        $durum = str_repeat('aB3-', 10);

        $adres = GoogleDriveBaglayicisi::yetkiAdresi($durum, 'https://erp.ornek.test/yedek-hedefleri/google/donus');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $adres);
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $sorgu);
        $this->assertSame([
            'client_id' => 'istemci-123.apps.googleusercontent.com',
            'redirect_uri' => 'https://erp.ornek.test/yedek-hedefleri/google/donus',
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $durum,
        ], $sorgu);
    }

    public function test_yetki_adresi_kisa_durum_degerini_reddeder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        GoogleDriveBaglayicisi::yetkiAdresi('kisa', 'https://erp.ornek.test/donus');
    }

    public function test_istemci_kimligi_tanimsizsa_yapilandirma_hatasi_verir_ve_istek_gitmez(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);
        config(['yedekleme.google.istemci_sirri' => null]);

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef())->baglan());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        Http::assertNothingSent();
    }

    public function test_kod_degisimi_yenileme_anahtarini_ve_hesabin_epostasini_dondurur(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => self::ERISIM, 'refresh_token' => self::YENILEME, 'expires_in' => 3599, 'scope' => GoogleDriveBaglayicisi::KAPSAM, 'token_type' => 'Bearer']),
            'https://www.googleapis.com/drive/v3/about?fields=*' => Http::response(['user' => ['emailAddress' => 'yedek@ornek.test']]),
        ]);

        $sonuc = GoogleDriveBaglayicisi::kodDegistir('4/izin-kodu', 'https://erp.ornek.test/donus');

        $this->assertSame(['yenileme_anahtari' => self::YENILEME, 'eposta' => 'yedek@ornek.test'], $sonuc);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::TOKEN && $istek->data() === [
            'grant_type' => 'authorization_code',
            'code' => '4/izin-kodu',
            'redirect_uri' => 'https://erp.ornek.test/donus',
            'client_id' => 'istemci-123.apps.googleusercontent.com',
            'client_secret' => self::ISTEMCI_SIRRI,
        ]);
        Http::assertSent(fn (Request $istek): bool => str_starts_with($istek->url(), 'https://www.googleapis.com/drive/v3/about')
            && $istek->data() === ['fields' => 'user(emailAddress)']
            && $istek->hasHeader('Authorization', 'Bearer '.self::ERISIM));
    }

    public function test_drive_izni_verilmeyen_kod_degisimi_reddedilir(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN => Http::response(['access_token' => self::ERISIM, 'refresh_token' => self::YENILEME, 'scope' => 'openid', 'token_type' => 'Bearer'])]);

        $hata = $this->hataBekle(fn () => GoogleDriveBaglayicisi::kodDegistir('4/izin-kodu', 'https://erp.ornek.test/donus'));

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        Http::assertSentCount(1);
    }
}
