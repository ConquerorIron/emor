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

    private const IPTAL = 'https://oauth2.googleapis.com/revoke';

    private const DOSYA = 'https://www.googleapis.com/drive/v3/files/dosya789';

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
        // JSONB anahtar sırasını korumaz (PostgreSQL): sırasız karşılaştırılır (T6)
        $this->assertEquals(['klasor_adi' => 'eMOR Yedekleri', 'klasor_kimligi' => 'klasor123', 'durum' => 'yeniden_baglan'], $hedef->fresh()->baglanti);
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

    public function test_klasoru_secilmemis_hedef_hicbir_istek_gondermeden_klasor_secilmedi_hatasi_verir(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $hedef = $this->hedef(['eposta' => 'kisi@gmail.com']);

        $yazma = $this->hataBekle(fn () => $this->baglayici($hedef)->yaz($this->yedekDosyasi(), 'erp_20261121.dump.gpg'));
        $sinama = $this->hataBekle(fn () => $this->baglayici($hedef)->baglan());

        $this->assertSame(HedefHatasi::KLASOR_SECILMEDI, $yazma->kod);
        $this->assertSame(HedefHatasi::KLASOR_SECILMEDI, $sinama->kod);
        // Adla klasör aranmaz, kendiliğinden açılmaz
        Http::assertNothingSent();
        $this->assertEquals(['eposta' => 'kisi@gmail.com'], $hedef->fresh()->baglanti);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: int}> */
    public static function kullanilamayanKayitliKlasorler(): iterable
    {
        yield 'silinmiş (404)' => [['error' => ['code' => 404]], 404];
        yield 'çöpte' => [['id' => 'klasor123', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => true], 200];
        yield 'klasör değil' => [['id' => 'klasor123', 'mimeType' => 'text/plain', 'trashed' => false], 200];
    }

    /** @param  array<string, mixed>  $govde */
    #[DataProvider('kullanilamayanKayitliKlasorler')]
    public function test_kayitli_klasor_kullanilamiyorsa_yeni_klasor_acilmaz_ve_yazilmaz(array $govde, int $durum): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN => self::tokenYaniti(), self::KLASOR => Http::response($govde, $durum)]);
        $hedef = $this->hedef();

        $hata = $this->hataBekle(fn () => $this->baglayici($hedef)->yaz($this->yedekDosyasi(), 'erp_20261121.dump.gpg'));

        $this->assertSame(HedefHatasi::KLASOR_GECERSIZ, $hata->kod);
        Http::assertNotSent(fn (Request $istek): bool => $istek->method() === 'POST' && str_starts_with($istek->url(), 'https://www.googleapis.com/'));
        $this->assertSame('klasor123', $hedef->fresh()->baglanti['klasor_kimligi']);
    }

    private const SECILEN = 'https://www.googleapis.com/drive/v3/files/secilen_1?fields=id%2Cname%2CmimeType%2Ctrashed%2CownedByMe%2Ccapabilities%28canAddChildren%29';

    private const COK_PARCALI = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id';

    private const DENEME_SIL = 'https://www.googleapis.com/drive/v3/files/deneme_1';

    /**
     * @param  array<string, mixed>  $ek
     * @return array<string, mixed>
     */
    private static function secilenGovdesi(array $ek = []): array
    {
        return [
            'id' => 'secilen_1', 'name' => 'Muhasebe Yedekleri', 'mimeType' => 'application/vnd.google-apps.folder',
            'trashed' => false, 'ownedByMe' => true, 'capabilities' => ['canAddChildren' => true], ...$ek,
        ];
    }

    private static function secilenYaniti(): PromiseInterface
    {
        return Http::response(self::secilenGovdesi());
    }

    /** Çok parçalı gövdenin ilk (JSON) bölümü */
    private static function ustBilgi(Request $istek): ?array
    {
        return preg_match('/\r\n\r\n(\{.*?\})\r\n--/s', $istek->body(), $m) === 1 ? json_decode($m[1], true) : null;
    }

    public function test_secilen_klasor_deneme_dosyasi_yazilip_silinerek_dogrulanir_ve_adi_doner(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::SECILEN => self::secilenYaniti(),
            self::COK_PARCALI => Http::response(['id' => 'deneme_1']),
            self::DENEME_SIL => Http::response('', 204),
        ]);
        $hedef = $this->hedef(['eposta' => 'kisi@gmail.com']);

        $klasor = $this->baglayici($hedef)->klasorDogrula('secilen_1');

        $this->assertSame(['kimlik' => 'secilen_1', 'ad' => 'Muhasebe Yedekleri'], $klasor);
        Http::assertSent(function (Request $istek): bool {
            $ust = self::ustBilgi($istek);

            return $istek->url() === self::COK_PARCALI
                && $istek->hasHeader('Authorization', 'Bearer '.self::ERISIM)
                && str_starts_with($istek->header('Content-Type')[0] ?? '', 'multipart/related; boundary=')
                && ($ust['parents'] ?? null) === ['secilen_1']
                // Silinemeyip kalsa bile yedek sayılmaz (listele yalnız erp_ ile başlayanları alır)
                && is_string($ust['name'] ?? null) && ! str_starts_with($ust['name'], 'erp_');
        });
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::DENEME_SIL && $istek->method() === 'DELETE');
        // Doğrulama kaydetmez: kaydı uç yapar (kilit + konum denetimiyle)
        $this->assertEquals(['eposta' => 'kisi@gmail.com'], $hedef->fresh()->baglanti);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: int, 2: string}> */
    public static function secilemeyenKlasorler(): iterable
    {
        yield 'erişilemiyor (404)' => [['error' => ['code' => 404]], 404, HedefHatasi::KLASOR_GECERSIZ];
        yield 'klasör değil' => [self::secilenGovdesi(['mimeType' => 'application/pdf']), 200, HedefHatasi::KLASOR_GECERSIZ];
        yield 'çöpte' => [self::secilenGovdesi(['trashed' => true]), 200, HedefHatasi::KLASOR_GECERSIZ];
        yield 'paylaşılmış (sahibi başkası)' => [self::secilenGovdesi(['ownedByMe' => false]), 200, HedefHatasi::KLASOR_GECERSIZ];
        yield 'sahiplik alanı yok' => [['id' => 'secilen_1', 'name' => 'X', 'mimeType' => 'application/vnd.google-apps.folder', 'trashed' => false], 200, HedefHatasi::KLASOR_GECERSIZ];
        yield 'dosya eklenemiyor' => [self::secilenGovdesi(['capabilities' => ['canAddChildren' => false]]), 200, HedefHatasi::YAZILAMADI];
        yield 'başka kimlik döndü' => [self::secilenGovdesi(['id' => 'baska']), 200, HedefHatasi::KLASOR_GECERSIZ];
    }

    /** @param  array<string, mixed>  $govde */
    #[DataProvider('secilemeyenKlasorler')]
    public function test_kullanilamayan_klasor_secilemez_ve_deneme_dosyasi_yazilmaz(array $govde, int $durum, string $kod): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN => self::tokenYaniti(), self::SECILEN => Http::response($govde, $durum)]);

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef(['eposta' => 'kisi@gmail.com']))->klasorDogrula('secilen_1'));

        $this->assertSame($kod, $hata->kod);
        Http::assertNotSent(fn (Request $istek): bool => $istek->method() === 'POST' && $istek->url() !== self::TOKEN);
    }

    public function test_bicimsiz_klasor_kimligi_drivea_sorulmadan_reddedilir(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef())->klasorDogrula('../files/x?fields=*'));

        $this->assertSame(HedefHatasi::KLASOR_GECERSIZ, $hata->kod);
        Http::assertNothingSent();
    }

    public function test_klasore_yazma_denemesi_reddedilirse_klasor_secilemez(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::SECILEN => self::secilenYaniti(),
            self::COK_PARCALI => Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'insufficientFilePermissions']]]], 403),
        ]);

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef())->klasorDogrula('secilen_1'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        // Dosya oluşturan istek yeniden denenmez
        $this->assertCount(1, Http::recorded(fn (Request $istek): bool => $istek->url() === self::COK_PARCALI));
    }

    public function test_deneme_dosyasi_silinemezse_basari_donmez(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            self::SECILEN => self::secilenYaniti(),
            self::COK_PARCALI => Http::response(['id' => 'deneme_1']),
            self::DENEME_SIL => Http::response(['error' => ['code' => 400]], 400),
        ]);

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef())->klasorDogrula('secilen_1'));

        $this->assertSame(HedefHatasi::SILINEMEDI, $hata->kod);
    }

    public function test_yeni_klasor_kokte_verilen_adla_acilir_ve_yazma_denenir(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => self::tokenYaniti(),
            'https://www.googleapis.com/drive/v3/files?fields=id' => Http::response(['id' => 'yeni_klasor']),
            self::COK_PARCALI => Http::response(['id' => 'deneme_1']),
            self::DENEME_SIL => Http::response('', 204),
        ]);

        $klasor = $this->baglayici($this->hedef(['eposta' => 'kisi@gmail.com']))->klasorAc("  Ali'nin Yedekleri ");

        $this->assertSame(['kimlik' => 'yeni_klasor', 'ad' => "Ali'nin Yedekleri"], $klasor);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === 'https://www.googleapis.com/drive/v3/files?fields=id'
            && json_decode($istek->body(), true) === ['name' => "Ali'nin Yedekleri", 'mimeType' => 'application/vnd.google-apps.folder']);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::COK_PARCALI && (self::ustBilgi($istek)['parents'] ?? null) === ['yeni_klasor']);
        $this->assertCount(1, Http::recorded(fn (Request $istek): bool => $istek->url() === 'https://www.googleapis.com/drive/v3/files?fields=id'));
    }

    public function test_secici_erisimi_yenileme_anahtariyla_alinan_erisim_anahtaridir(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN => self::tokenYaniti()]);

        $this->assertSame(self::ERISIM, $this->baglayici($this->hedef())->seciciErisimi());
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::TOKEN
            && ($istek->data()['grant_type'] ?? null) === 'refresh_token'
            && ($istek->data()['refresh_token'] ?? null) === self::YENILEME);
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
            // YF-47: include_granted_scopes yok — önceki izinler anahtara katılmaz
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

        $this->assertSame(['yenileme_anahtari' => self::YENILEME, 'eposta' => 'yedek@ornek.test', 'dogrulanan_klasor' => null], $sonuc);
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

    /**
     * Kapsam kümesi tam olarak drive.file değilse bağlantı kurulmaz (YF-47: fazlası
     * anahtarı genişletir); alınan anahtar kaydedilmeyeceği için Google'da iptal
     * edilir (YF-48) — bağlı başka Drive hedefi yokken.
     *
     * @return iterable<string, array{string}>
     */
    public static function uygunsuzKapsamlar(): iterable
    {
        yield 'Drive yetkisi verilmemiş' => ['openid'];
        yield 'drive.file ile birlikte başka kapsam' => [GoogleDriveBaglayicisi::KAPSAM.' https://www.googleapis.com/auth/gmail.readonly'];
    }

    #[DataProvider('uygunsuzKapsamlar')]
    public function test_kapsami_tam_drive_file_olmayan_kod_degisimi_reddedilir_alinan_anahtar_iptal_edilir(string $kapsam): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::response(['access_token' => self::ERISIM, 'refresh_token' => self::YENILEME, 'scope' => $kapsam, 'token_type' => 'Bearer']),
            self::IPTAL => Http::response('', 200),
        ]);

        $hata = $this->hataBekle(fn () => GoogleDriveBaglayicisi::kodDegistir('4/izin-kodu', 'https://erp.ornek.test/donus'));

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::IPTAL && $istek->data() === ['token' => self::YENILEME]);
    }

    /**
     * YF-48: Google'da iptal izni bütünüyle kaldırabilir — aynı hesapla (ya da
     * hesabı bilinmeyen) bağlı başka bir hedef varken kaydedilmeyen anahtar iptal
     * edilmez, o hedefin bağlantısı düşmesin.
     */
    public function test_bagli_baska_drive_hedefi_varken_kaydedilmeyen_anahtar_iptal_edilmez(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $this->hedef(['eposta' => 'yedek@ornek.test', 'klasor_kimligi' => 'klasor123']);
        Http::fake([
            self::TOKEN => Http::response(['access_token' => self::ERISIM, 'refresh_token' => 'yeni-yenileme', 'scope' => GoogleDriveBaglayicisi::KAPSAM, 'token_type' => 'Bearer']),
            'https://www.googleapis.com/drive/v3/about?fields=*' => Http::response('', 500),
        ]);

        $hata = $this->hataBekle(fn () => GoogleDriveBaglayicisi::kodDegistir('4/izin-kodu', 'https://erp.ornek.test/donus'));

        $this->assertSame(GoogleDriveBaglayicisi::HESAP_OKUNAMADI, $hata->kod);
        Http::assertNotSent(fn (Request $istek): bool => $istek->url() === self::IPTAL);
    }

    /** YF-52: indirme geçici ada yapılıp yerine taşınır; kimlik doğrulanmış erişim anahtarıyla */
    public function test_oku_dosyayi_indirir_yerine_tasir_gecici_dosya_kalmaz(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN => self::tokenYaniti(), self::DOSYA.'?alt=media' => Http::response('GPG-sifreli-yedek', 200)]);

        $this->baglayici($this->hedef())->oku('dosya789', $this->klasor.'/geri');

        $this->assertSame('GPG-sifreli-yedek', file_get_contents($this->klasor.'/geri'));
        $this->assertSame(['.', '..', 'geri'], scandir($this->klasor));
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::DOSYA.'?alt=media' && $istek->hasHeader('Authorization', 'Bearer '.self::ERISIM));
    }

    public function test_oku_404te_okunamadi_yerel_ve_gecici_dosya_kalmaz(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN => self::tokenYaniti(), self::DOSYA.'?alt=media' => Http::response(['error' => ['code' => 404]], 404)]);

        $hata = $this->hataBekle(fn () => $this->baglayici($this->hedef())->oku('dosya789', $this->klasor.'/geri'));

        $this->assertSame(HedefHatasi::OKUNAMADI, $hata->kod);
        $this->assertSame(['.', '..'], scandir($this->klasor));
    }

    public function test_oku_503te_yeniden_denenir_ve_indirir(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([self::TOKEN => self::tokenYaniti(), self::DOSYA.'?alt=media' => Http::sequence()->push('', 503)->push('GPG-sifreli-yedek', 200)]);

        $this->baglayici($this->hedef())->oku('dosya789', $this->klasor.'/geri');

        $this->assertSame('GPG-sifreli-yedek', file_get_contents($this->klasor.'/geri'));
        $this->assertCount(2, Http::recorded(fn (Request $istek): bool => $istek->url() === self::DOSYA.'?alt=media'));
    }

    public function test_oku_401de_erisim_anahtari_yenilenip_bir_kez_tekrarlanir(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN => Http::sequence()->push(['access_token' => 'eski-erisim', 'scope' => GoogleDriveBaglayicisi::KAPSAM])->push(['access_token' => self::ERISIM, 'scope' => GoogleDriveBaglayicisi::KAPSAM]),
            self::DOSYA.'?alt=media' => Http::sequence()->push('', 401)->push('GPG-sifreli-yedek', 200),
        ]);

        $this->baglayici($this->hedef())->oku('dosya789', $this->klasor.'/geri');

        $this->assertSame('GPG-sifreli-yedek', file_get_contents($this->klasor.'/geri'));
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::DOSYA.'?alt=media' && $istek->hasHeader('Authorization', 'Bearer '.self::ERISIM));
        $this->assertSame(2, count(Http::recorded(fn (Request $istek): bool => $istek->url() === self::TOKEN)));
    }

    /** @return iterable<string, array{int, string|null}> */
    public static function silmeYanitlari(): iterable
    {
        yield '204 silindi' => [204, null];
        yield '404 zaten yok' => [404, null];
        yield '403 yetki yok' => [403, HedefHatasi::KIMLIK];
    }

    /** YF-52: Drive silmesi — yok olan dosya başarı, yetki reddi hata */
    #[DataProvider('silmeYanitlari')]
    public function test_sil_yanitina_gore_sonuclanir(int $durum, ?string $beklenenKod): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN => self::tokenYaniti(), self::DOSYA => Http::response($durum === 204 ? '' : ['error' => ['code' => $durum]], $durum)]);

        $kod = null;
        try {
            $this->baglayici($this->hedef())->sil('dosya789');
        } catch (HedefHatasi $hata) {
            $kod = $hata->kod;
        }

        $this->assertSame($beklenenKod, $kod);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::DOSYA && $istek->method() === 'DELETE');
    }
}
