<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\S3Baglayicisi;
use App\Services\Yedekleme\HedefTuru;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BY-023: S3 uyumlu hedef — SigV4 imzası, silme yetkisi yokken bilgi
 * (SILME_YETKISI_YOK), liste süzgeci, yalnız geçici hatalarda yeniden deneme.
 */
final class S3BaglayicisiTest extends TestCase
{
    private const GIZLI = 'gizliAnahtar/Ornek+Deger0123456789abcdefgh';

    private const SANAL = 'https://emor-yedek.s3.eu-central-1.amazonaws.com';

    private const BOS_OZET = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    private string $klasor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->klasor = sys_get_temp_dir().'/emor-s3-test-'.bin2hex(random_bytes(5));
        mkdir($this->klasor, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->klasor);
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $baglanti */
    private function baglayici(array $baglanti = []): S3Baglayicisi
    {
        $hedef = YedekHedefi::factory()->make([
            'tur' => HedefTuru::S3,
            'baglanti' => $baglanti + [
                'uc_nokta' => 'https://s3.eu-central-1.amazonaws.com',
                'bolge' => 'eu-central-1',
                'kova' => 'emor-yedek',
                'on_ek' => 'sunucu1',
                'yol_stili' => false,
                'erisim_anahtari' => 'AKIDORNEK123',
            ],
            'gizli_bilgiler' => ['gizli_anahtar' => self::GIZLI],
        ]);

        $baglayici = app(HedefKatalogu::class)->olustur($hedef);
        $this->assertInstanceOf(S3Baglayicisi::class, $baglayici);

        return $baglayici;
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

    /**
     * AWS SigV4 imzası, belgedeki adımlarla (sınıftan bağımsız yazıldı;
     * doğruluğu AWS'nin yayımladığı örnekle ayrıca sınanır).
     *
     * @param  array<string, string>  $basliklar
     */
    private static function referansImza(string $gizli, string $yontem, string $yol, string $sorgu, array $basliklar, string $govdeOzeti, string $amzTarih, string $bolge): string
    {
        $gun = substr($amzTarih, 0, 8);
        ksort($basliklar);
        $kanonik = $yontem."\n".$yol."\n".$sorgu."\n";
        foreach ($basliklar as $ad => $deger) {
            $kanonik .= $ad.':'.$deger."\n";
        }
        $kanonik .= "\n".implode(';', array_keys($basliklar))."\n".$govdeOzeti;
        $kapsam = "{$gun}/{$bolge}/s3/aws4_request";
        $imzalanacak = "AWS4-HMAC-SHA256\n{$amzTarih}\n{$kapsam}\n".hash('sha256', $kanonik);
        $tarihAnahtari = hash_hmac('sha256', $gun, 'AWS4'.$gizli, true);
        $bolgeAnahtari = hash_hmac('sha256', $bolge, $tarihAnahtari, true);
        $hizmetAnahtari = hash_hmac('sha256', 's3', $bolgeAnahtari, true);

        return hash_hmac('sha256', $imzalanacak, hash_hmac('sha256', 'aws4_request', $hizmetAnahtari, true));
    }

    public function test_referans_imza_aws_belgesindeki_ornek_istekle_ayni_imzayi_uretir(): void
    {
        // AWS S3 belgesi, "Example: GET Bucket (List Objects)" (SigV4, tek parça gövde)
        $basliklar = ['host' => 'examplebucket.s3.amazonaws.com', 'x-amz-content-sha256' => self::BOS_OZET, 'x-amz-date' => '20130524T000000Z'];

        $imza = self::referansImza('wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'GET', '/', 'max-keys=2&prefix=J', $basliklar, self::BOS_OZET, '20130524T000000Z', 'us-east-1');

        $this->assertSame('34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7', $imza);
    }

    public function test_yukleme_sigv4_ile_imzalanir_ve_gizli_anahtar_hicbir_istege_girmez(): void
    {
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 10:15:30', 'UTC'));
        Http::fake([self::SANAL.'/sunucu1/erp_20261121%2B03.dump' => Http::response('', 200)]);
        $dosya = $this->klasor.'/yedek';
        file_put_contents($dosya, 'PGDMP-yedek-icerigi');

        $kimlik = $this->baglayici()->yaz($dosya, 'erp_20261121+03.dump');

        $this->assertSame('sunucu1/erp_20261121+03.dump', $kimlik);
        $istekler = Http::recorded();
        $this->assertCount(1, $istekler);
        /** @var Request $istek */
        [$istek] = $istekler[0];
        $ozet = hash('sha256', 'PGDMP-yedek-icerigi');
        $uri = $istek->toPsrRequest()->getUri();
        $beklenenImza = self::referansImza(self::GIZLI, 'PUT', $uri->getPath(), $uri->getQuery(), [
            'host' => 'emor-yedek.s3.eu-central-1.amazonaws.com', 'x-amz-content-sha256' => $ozet, 'x-amz-date' => '20261121T101530Z',
        ], $ozet, '20261121T101530Z', 'eu-central-1');
        $this->assertSame('PUT', $istek->method());
        $this->assertSame('PGDMP-yedek-icerigi', $istek->body());
        $this->assertSame([$ozet], $istek->header('x-amz-content-sha256'));
        $this->assertSame(['20261121T101530Z'], $istek->header('x-amz-date'));
        $this->assertSame(
            ["AWS4-HMAC-SHA256 Credential=AKIDORNEK123/20261121/eu-central-1/s3/aws4_request, SignedHeaders=host;x-amz-content-sha256;x-amz-date, Signature={$beklenenImza}"],
            $istek->header('Authorization'),
        );
        $ham = $istek->url()."\n".json_encode($istek->headers())."\n".$istek->body();
        $this->assertStringNotContainsString(self::GIZLI, $ham);
        $this->assertStringNotContainsString(rawurlencode(self::GIZLI), $ham);
    }

    public function test_silme_yetkisi_yoksa_silme_yetkisi_yok_hatasi_verir(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SANAL.'/sunucu1/erp_eski.dump.gpg' => Http::response('<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Access Denied</Message></Error>', 403)]);

        $hata = $this->hataBekle(fn () => $this->baglayici()->sil('sunucu1/erp_eski.dump.gpg'));

        $this->assertSame(HedefHatasi::SILME_YETKISI_YOK, $hata->kod);
        Http::assertSentCount(1);
    }

    public function test_silmede_imza_reddi_silme_yetkisi_sayilmaz_kimlik_hatasidir(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SANAL.'/sunucu1/erp_eski.dump.gpg' => Http::response('<Error><Code>SignatureDoesNotMatch</Code><Message>imza '.self::GIZLI.'</Message></Error>', 403)]);

        $hata = $this->hataBekle(fn () => $this->baglayici()->sil('sunucu1/erp_eski.dump.gpg'));

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        $this->assertSame('S3 silmesi başarısız (HTTP 403, SignatureDoesNotMatch).', $hata->getMessage());
    }

    public function test_liste_yol_stilinde_sayfalanir_ve_yalniz_on_ekteki_yedekleri_dondurur(): void
    {
        Http::preventStrayRequests();
        $taban = 'https://minio.ornek.test:9000/emor-yedek/';
        $sayfa = fn (string $icerik, string $devam): string => '<?xml version="1.0" encoding="UTF-8"?><ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
            .'<IsTruncated>'.($devam === '' ? 'false' : 'true').'</IsTruncated>'.$icerik
            .($devam === '' ? '' : "<NextContinuationToken>{$devam}</NextContinuationToken>").'</ListBucketResult>';
        $nesne = fn (string $anahtar): string => "<Contents><Key>{$anahtar}</Key><Size>10</Size></Contents>";
        Http::fake([
            $taban.'?list-type=2&prefix=sunucu1%2Ferp_' => Http::response($sayfa(
                $nesne('sunucu1/erp_a.dump').$nesne('sunucu1/erp_b.dump.gpg').$nesne('sunucu1/erp_notlar.txt').$nesne('sunucu1/alt/erp_c.dump'),
                'sayfa2',
            )),
            $taban.'?continuation-token=sayfa2&list-type=2&prefix=sunucu1%2Ferp_' => Http::response($sayfa(
                $nesne('sunucu1/erp_d.dump.tmp').$nesne('sunucu1/erp_e.dump'),
                '',
            )),
        ]);

        $liste = $this->baglayici(['uc_nokta' => 'https://minio.ornek.test:9000', 'yol_stili' => true])->listele();

        $this->assertSame(['sunucu1/erp_a.dump', 'sunucu1/erp_b.dump.gpg', 'sunucu1/erp_e.dump'], $liste);
        Http::assertSent(fn (Request $istek): bool => $istek->header('Authorization') !== [] && str_starts_with($istek->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIDORNEK123/'));
    }

    public function test_503_yanitinda_yeniden_denenir_ve_dosya_indirilir(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([self::SANAL.'/sunucu1/erp_a.dump' => Http::sequence()->push('', 503)->push('', 503)->push('YEDEK-ICERIGI', 200)]);
        $yerel = $this->klasor.'/geri';

        $this->baglayici()->oku('sunucu1/erp_a.dump', $yerel);

        $this->assertSame('YEDEK-ICERIGI', file_get_contents($yerel));
        Http::assertSentCount(3);
    }

    public function test_403_yanitinda_yeniden_denenmez(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([self::SANAL.'/' => Http::response('', 403)]);

        $hata = $this->hataBekle(fn () => $this->baglayici()->baglan());

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        Http::assertSentCount(1);
    }

    public function test_baglanti_kurulamazsa_iki_kez_yeniden_denenir_sonra_erisilemedi_hatasi_verir(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([self::SANAL.'/' => Http::failedConnection()]);

        $hata = $this->hataBekle(fn () => $this->baglayici()->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $hata->kod);
        Sleep::assertSleptTimes(2);
    }

    public function test_okuma_hatasinda_yerel_dosya_ve_gecici_dosya_kalmaz(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SANAL.'/sunucu1/erp_yok.dump' => Http::response('<Error><Code>NoSuchKey</Code></Error>', 404)]);

        $hata = $this->hataBekle(fn () => $this->baglayici()->oku('sunucu1/erp_yok.dump', $this->klasor.'/geri'));

        $this->assertSame(HedefHatasi::OKUNAMADI, $hata->kod);
        $this->assertSame(['.', '..'], scandir($this->klasor));
    }

    public function test_alan_adi_disi_karakterli_uc_noktaya_istek_gitmez(): void
    {
        Http::preventStrayRequests();
        Http::fake([]);

        $hata = $this->hataBekle(fn () => $this->baglayici(['uc_nokta' => 'https://kotu.com\.amazonaws.com'])->baglan());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        Http::assertNothingSent();
    }

    /** @return iterable<string, array{0: int, 1: string, 2: bool|null}> */
    public static function nesneKilidiYanitlari(): iterable
    {
        yield 'açık' => [200, '<ObjectLockConfiguration><ObjectLockEnabled>Enabled</ObjectLockEnabled></ObjectLockConfiguration>', true];
        yield 'yapılandırılmamış' => [404, '<Error><Code>ObjectLockConfigurationNotFoundError</Code></Error>', false];
        yield 'yetki yok (bilinmiyor)' => [403, '<Error><Code>AccessDenied</Code></Error>', null];
    }

    #[DataProvider('nesneKilidiYanitlari')]
    public function test_silinme_korumasi_nesne_kilidi_yanitindan_okunur(int $durum, string $govde, ?bool $beklenen): void
    {
        Http::preventStrayRequests();
        Http::fake([self::SANAL.'/?object-lock=' => Http::response($govde, $durum)]);

        $sonuc = $this->baglayici()->silinmeKorumasiVarMi();

        $this->assertSame($beklenen, $sonuc);
    }
}
