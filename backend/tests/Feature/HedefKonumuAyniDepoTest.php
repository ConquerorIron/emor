<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KopyaSifreleyici;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SilmeKarari;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKopyalayici;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\GpgAnahtarlari;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-010-R8: aynı uzak depoyu gösteren iki hedef tanımı (HedefKonumu::ayniDepoMu).
 * S3 testleri GERÇEK S3Baglayicisi ile tek bir sahte nesne deposunda çalışır:
 * iki tanımın PUT / GET / DELETE istekleri aynı (kova, anahtar) nesnesine
 * düşüyorsa sonuç fiziksel olarak görünür (üstüne yazma, silinen nesne).
 */
final class HedefKonumuAyniDepoTest extends TestCase
{
    use GpgAnahtarlari;
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const K = '/api/v1/ayarlar/yedek-hedefleri';

    /** Sahte S3 hizmetinin alan adı (yol stili: UC/kova/anahtar, sanal sunucu stili: kova.UC/anahtar) */
    private const UC = 's3.ornek.test';

    private const S3 = [
        'uc_nokta' => 'https://s3.ornek.test', 'bolge' => 'eu-central-1', 'kova' => 'emor',
        'on_ek' => 'sunucu1', 'yol_stili' => true, 'erisim_anahtari' => 'AKIDMEVCUT1',
    ];

    private const GIZLI_ANAHTAR = 'gizli/Anahtar+0123456789';

    private const SFTP = ['sunucu' => 'dosya.ornek.test', 'port' => 22, 'kullanici_adi' => 'yedekci', 'klasor' => '/emor'];

    /** @var array<string, string> sahte S3 deposu: "kova/anahtar" => gövde */
    private array $nesneler = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true]);
        Sleep::fake();
        $this->actingAs(User::factory()->yonetici()->create());
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /** Bellekte tek bir S3 hizmeti: adres biçimi ne olursa olsun nesne (kova, anahtar) ile tutulur */
    private function sahteS3(): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) {
            $uri = $istek->toPsrRequest()->getUri();
            $sunucu = $uri->getHost();
            $yol = rawurldecode($uri->getPath());
            $nesne = match (true) {
                $sunucu === self::UC => ltrim($yol, '/'),
                str_ends_with($sunucu, '.'.self::UC) => substr($sunucu, 0, -strlen('.'.self::UC)).$yol,
                default => null,
            };

            if ($nesne === null) {
                return Http::response('<Error><Code>InvalidHost</Code></Error>', 400);
            }
            if ($istek->method() === 'PUT') {
                $this->nesneler[$nesne] = $istek->body();

                return Http::response('', 200);
            }
            if ($istek->method() === 'DELETE') {
                unset($this->nesneler[$nesne]);

                return Http::response('', 204);
            }
            if ($istek->method() === 'GET') {
                return isset($this->nesneler[$nesne])
                    ? Http::response($this->nesneler[$nesne], 200)
                    : Http::response('<Error><Code>NoSuchKey</Code></Error>', 404);
            }

            return Http::response('', 200);
        });
    }

    /** @param  array<string, mixed>  $fark */
    private function s3Hedefi(string $ad, array $fark = [], bool $kaydet = true): YedekHedefi
    {
        $ozellikler = ['ad' => $ad, 'tur' => HedefTuru::S3, 'baglanti' => [...self::S3, ...$fark], 'gizli_bilgiler' => ['gizli_anahtar' => self::GIZLI_ANAHTAR]];

        return $kaydet ? YedekHedefi::factory()->create($ozellikler) : YedekHedefi::factory()->make($ozellikler);
    }

    /** Yerel dosyası (gerçek özetiyle) ve manifesti olan plan yedeği; $gunOnce gün önce alınmış */
    private function yedek(int $gunOnce): Yedek
    {
        $icerik = 'PGDMP-yedek-'.$gunOnce;
        $yedek = Yedek::factory()->create([
            'kaynak' => YedekKaynagi::Plan,
            'dosya_adi' => 'erp_plan_'.$gunOnce.'_'.bin2hex(random_bytes(3)).'.dump',
            'alinma_zamani' => CarbonImmutable::now()->subDays($gunOnce),
            'sha256' => hash('sha256', $icerik),
            'boyut' => strlen($icerik),
        ]);
        $yol = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        file_put_contents($yol, $icerik);
        file_put_contents(ManifestDeposu::jsonYolu($yol), '{}');

        return $yedek;
    }

    private function plan(YedekHedefi $hedef, int $son): YedekPlani
    {
        $plan = YedekPlani::factory()->create();
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => $son]);

        return $plan;
    }

    /** Hedefe yazılmış, kurtarmaya hazır şifreli kopya kaydı */
    private function kopya(Yedek $yedek, YedekHedefi $hedef, YedekPlani $plan, string $uzakKimlik): YedekKopyasi
    {
        $kopya = YedekKopyasi::query()->create([
            'yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam,
            'uzak_kimlik' => $uzakKimlik, 'sifreli' => true, 'kurtarma_hazir' => true,
        ]);
        $kopya->planlar()->attach($plan->id, ['yedek_hedefi_id' => $hedef->id]);

        return $kopya;
    }

    /**
     * Kayıtlı S3 hedefinden yalnız bu alanlarda farklı yeni tanım; hepsi
     * aynı (kova, önek) nesnelerine yazar.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function ayniS3Deposu(): iterable
    {
        yield 'aynı ayarlar, farklı ad' => [[]];
        yield 'uç nokta büyük harf, :443 ve sondaki "/"' => [['uc_nokta' => 'https://S3.Ornek.TEST:443/']];
        yield 'önekin sonunda "/"' => [['on_ek' => 'sunucu1/']];
        yield 'farklı bölge ve erişim anahtarı' => [['bolge' => 'us-east-1', 'erisim_anahtari' => 'AKIDBASKA2']];
        yield 'sanal sunucu stili (aynı kova, başka adres biçimi)' => [['yol_stili' => false]];
    }

    /**
     * Reddin dayanağı da sınanır: iki tanımın gerçek bağlayıcısı aynı adı
     * aynı nesneye yazar, ikincisi birincinin üstüne.
     *
     * @param  array<string, mixed>  $fark
     */
    #[DataProvider('ayniS3Deposu')]
    public function test_ayni_s3_nesnelerine_yazan_ikinci_hedef_409_kaydedilmez(array $fark): void
    {
        $this->sahteS3();
        $mevcut = $this->s3Hedefi('S3 mevcut');

        $this->postJson(self::K, ['ad' => 'S3 ikinci', 'tur' => 's3', 'alanlar' => [...self::S3, ...$fark, 'gizli_anahtar' => 'baska/Gizli+Anahtar1']])
            ->assertStatus(409)
            ->assertJsonPath('kod', 'HEDEF_AYNI_DEPO')
            ->assertJsonPath('hedefler', ['S3 mevcut'])
            ->assertJsonPath('mesaj', __('hata.yedek_hedefi_ayni_depo', ['hedefler' => 'S3 mevcut']));
        $this->assertSame(1, YedekHedefi::query()->count());

        $katalog = app(HedefKatalogu::class);
        file_put_contents($this->yedekKoku.'/bir', 'birinci hedefin kopyası');
        file_put_contents($this->yedekKoku.'/iki', 'ikinci tanımın kopyası');
        $katalog->olustur($mevcut)->yaz($this->yedekKoku.'/bir', 'erp_a.dump.gpg');
        $katalog->olustur($this->s3Hedefi('S3 ikinci', $fark, kaydet: false))->yaz($this->yedekKoku.'/iki', 'erp_a.dump.gpg');
        $this->assertSame(['emor/sunucu1/erp_a.dump.gpg' => 'ikinci tanımın kopyası'], $this->nesneler);
    }

    /**
     * Aşırı engelleme yok: nesne anahtarı farklı olan tanım ayrı depodur.
     *
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function baskaS3Deposu(): iterable
    {
        yield 'başka kova' => [['kova' => 'emor-2']];
        yield 'başka önek' => [['on_ek' => 'sunucu2']];
        yield 'alt önek' => [['on_ek' => 'sunucu1/alt']];
        yield 'boş önek (kovanın kökü)' => [['on_ek' => null]];
        yield 'aynı alan adında başka port' => [['uc_nokta' => 'https://s3.ornek.test:9000']];
        yield 'başka uç nokta' => [['uc_nokta' => 'https://minio.ornek.test']];
    }

    /** @param  array<string, mixed>  $fark */
    #[DataProvider('baskaS3Deposu')]
    public function test_baska_s3_nesnelerine_yazan_hedef_kaydedilir(array $fark): void
    {
        $this->s3Hedefi('S3 mevcut');

        $this->postJson(self::K, ['ad' => 'S3 ikinci', 'tur' => 's3', 'alanlar' => [...self::S3, ...$fark, 'gizli_anahtar' => self::GIZLI_ANAHTAR]])->assertCreated();

        $this->assertSame(2, YedekHedefi::query()->count());
    }

    /**
     * Kayıtlı SFTP hedefine göre yeni FTP / FTPS / SFTP tanımı: [tür, alan farkı, aynı depo mu].
     *
     * @return iterable<string, array{string, array<string, mixed>, bool}>
     */
    public static function dosyaSunucusuTanimlari(): iterable
    {
        yield 'FTPS: aynı sunucu, kullanıcı ve klasör; başka port' => ['ftps', ['port' => 21], true];
        yield 'FTP: alan adı ve kullanıcı adı büyük harf, klasörün sonunda "/"' => ['ftp', ['sunucu' => 'DOSYA.Ornek.Test', 'kullanici_adi' => 'Yedekci', 'klasor' => '/emor/'], true];
        yield 'SFTP: göreli klasör (chroot\'lu hesapta aynı yer)' => ['sftp', ['klasor' => 'emor'], true];
        yield 'SFTP: klasörün harfi farklı (harf duyarsız sunucu)' => ['sftp', ['klasor' => '/EMOR'], true];
        yield 'başka kullanıcı' => ['sftp', ['kullanici_adi' => 'baska'], false];
        yield 'başka sunucu' => ['ftps', ['sunucu' => 'dosya2.ornek.test'], false];
        yield 'alt klasör' => ['sftp', ['klasor' => '/emor/alt'], false];
    }

    /** @param  array<string, mixed>  $fark */
    #[DataProvider('dosyaSunucusuTanimlari')]
    public function test_ayni_sunucu_kullanici_ve_klasore_yazan_ftp_ailesi_hedefi_409_kaydedilmez(string $tur, array $fark, bool $ayniDepo): void
    {
        YedekHedefi::factory()->create(['ad' => 'SFTP mevcut', 'tur' => HedefTuru::Sftp, 'baglanti' => self::SFTP, 'gizli_bilgiler' => ['sifre' => 'Gizli-Parola-1']]);

        $yanit = $this->postJson(self::K, ['ad' => 'Yeni', 'tur' => $tur, 'alanlar' => [...self::SFTP, ...$fark, 'sifre' => 'Gizli-Parola-2']]);

        if ($ayniDepo) {
            $yanit->assertStatus(409)->assertJsonPath('kod', 'HEDEF_AYNI_DEPO')->assertJsonPath('hedefler', ['SFTP mevcut']);
            $this->assertSame(1, YedekHedefi::query()->count());
        } else {
            $yanit->assertCreated();
            $this->assertSame(2, YedekHedefi::query()->count());
        }
    }

    /**
     * Güncelleme: depo kimliğine girmeyen konum alanı (bölge, yol stili)
     * eskiden kalmış çakışan hedefte de değişir — yeni çakışma doğurmaz;
     * başka bir hedefin deposuna taşınan hedef 409 alır.
     */
    public function test_ayni_depoda_kalan_konum_degisikligi_serbest_baska_hedefin_deposuna_tasinma_409(): void
    {
        $this->s3Hedefi('S3 mevcut');
        $eski = $this->s3Hedefi('S3 eski çakışan', ['uc_nokta' => 'https://S3.ornek.test/']);
        $ayri = $this->s3Hedefi('S3 ayrı', ['kova' => 'emor-2']);
        $govde = fn (string $ad, array $fark): array => ['ad' => $ad, 'tur' => 's3', 'alanlar' => [...self::S3, ...$fark, 'gizli_anahtar' => self::GIZLI_ANAHTAR]];

        $this->putJson(self::K.'/'.$eski->id, $govde('S3 eski çakışan', ['uc_nokta' => 'https://S3.ornek.test/', 'bolge' => 'us-east-1', 'yol_stili' => false]))->assertOk();
        $this->assertSame(['us-east-1', false], [$eski->refresh()->baglanti['bolge'], $eski->baglanti['yol_stili']]);

        $this->putJson(self::K.'/'.$ayri->id, $govde('S3 ayrı', ['on_ek' => 'sunucu1/']))
            ->assertStatus(409)->assertJsonPath('kod', 'HEDEF_AYNI_DEPO')->assertJsonPath('hedefler', ['S3 mevcut', 'S3 eski çakışan']);
        $this->assertSame('emor-2', $ayri->refresh()->baglanti['kova']);
    }

    /**
     * Eskiden kalmış iki S3 tanımı aynı nesneleri gösteriyor. İkinci hedefe
     * şifreli "kopya" ilk hedefin nesnesinin üstüne yazılır ve iki bağımsız
     * kopya sanılırdı: kalıcı hata, hiçbir istek gitmez, ortak nesne aynen kalır.
     */
    public function test_ayni_s3_deposundaki_ikinci_hedefe_kopya_yazilmaz_ortak_nesne_degismez(): void
    {
        $this->gpgAnahtarlariniHazirla();
        $bilgi = app(KopyaSifreleyici::class)->acikAnahtarBilgisi(self::$anahtarlar['a']['acik']);
        YedekAyari::query()->create([
            'sifreleme_acik_anahtari' => self::$anahtarlar['a']['acik'], 'sifreleme_parmak_izi' => $bilgi['parmak_izi'],
            'sifreleme_anahtar_kullanicisi' => $bilgi['kullanici'], 'sifreleme_anahtar_bitisi' => $bilgi['bitis'],
        ]);
        $this->sahteS3();
        $ilk = $this->s3Hedefi('S3 ilk');
        $ikinci = $this->s3Hedefi('S3 ikinci', ['uc_nokta' => 'https://S3.Ornek.Test:443', 'on_ek' => '/sunucu1/', 'erisim_anahtari' => 'AKIDBASKA2']);
        $yedek = $this->yedek(1);
        $nesne = 'emor/sunucu1/'.$yedek->dosya_adi.'.gpg';
        $this->nesneler[$nesne] = 'ilk hedefin şifreli kopyası';
        $this->kopya($yedek, $ilk, $this->plan($ilk, 10), 'sunucu1/'.$yedek->dosya_adi.'.gpg');

        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $ikinci);

        $this->assertSame([KopyaDurumu::Basarisiz, HedefHatasi::AYNI_DEPO, 1], [$kopya->durum, $kopya->hata_kodu, $kopya->deneme]);
        Http::assertNothingSent();
        $this->assertSame([$nesne => 'ilk hedefin şifreli kopyası'], $this->nesneler);
    }

    /**
     * Eskiden kalmış iki S3 tanımı (farklı yazımla) aynı nesneleri gösteriyor;
     * uzun kural (son 10) ve kısa kural (son 1). Kısa kural ortak nesneyi
     * silince uzun kuralın kopyası `tamam` kalır, yerel dosya da ona
     * dayanılarak silinirdi: yedeğin hiçbir fiziksel kopyası kalmazdı.
     */
    public function test_ayni_s3_deposunu_gosteren_iki_saklama_kurali_ortak_nesneyi_silmez_gorunur_hata_yazar(): void
    {
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
        $this->sahteS3();
        $uzunHedef = $this->s3Hedefi('S3 uzun');
        $kisaHedef = $this->s3Hedefi('S3 kısa', ['uc_nokta' => 'https://S3.Ornek.Test:443/', 'on_ek' => '/sunucu1/', 'bolge' => 'us-east-1', 'erisim_anahtari' => 'AKIDKISA2']);
        $uzun = $this->plan($uzunHedef, 10);
        $kisa = $this->plan($kisaHedef, 1);
        $eski = $this->yedek(5);
        $yeni = $this->yedek(1);
        foreach ([$eski, $yeni] as $y) {
            $this->nesneler['emor/sunucu1/'.$y->dosya_adi.'.gpg'] = 'şifreli '.$y->dosya_adi;
            $this->kopya($y, $uzunHedef, $uzun, 'sunucu1/'.$y->dosya_adi.'.gpg');
            $this->kopya($y, $kisaHedef, $kisa, 'sunucu1/'.$y->dosya_adi.'.gpg');
        }
        // Yalnız kısa kurallı hedefte kopyası olan nesne ortak değildir: kuralıyla silinir
        $tekHedefli = $this->yedek(7);
        $this->nesneler['emor/sunucu1/'.$tekHedefli->dosya_adi.'.gpg'] = 'şifreli tek';
        $this->kopya($tekHedefli, $kisaHedef, $kisa, 'sunucu1/'.$tekHedefli->dosya_adi.'.gpg');

        $onizleme = app(SaklamaPolitikasi::class)->hesapla();
        app(SaklamaPolitikasi::class)->uygula();

        $this->assertEqualsCanonicalizing(['emor/sunucu1/'.$eski->dosya_adi.'.gpg', 'emor/sunucu1/'.$yeni->dosya_adi.'.gpg'], array_keys($this->nesneler));
        $this->assertContains('ayni_depo', $onizleme['korunan'][$eski->id] ?? []);
        $this->assertSame([KopyaDurumu::Tamam, KopyaDurumu::Tamam], YedekKopyasi::query()->where('yedek_id', $eski->id)->orderBy('yedek_hedefi_id')->get()->map(fn (YedekKopyasi $k) => $k->durum)->all());
        $kayit = YedekIslemKaydi::query()->where(['islem' => 'saklama_sil', 'yedek_id' => $eski->id, 'sonuc' => IslemSonucu::Basarisiz])->sole();
        $this->assertSame([HedefHatasi::AYNI_DEPO, $kisaHedef->id, [$uzunHedef->id]],
            [$kayit->ayrinti['kod'], $kayit->ayrinti['hedef_id'], $kayit->ayrinti['ayni_depo_hedefleri']]);
        $this->assertSame(KopyaDurumu::Silindi, YedekKopyasi::query()->where('yedek_id', $tekHedefli->id)->sole()->durum);
    }

    /** G1: FTP ailesinde de (SFTP ↔ FTPS, farklı yazım) saklama ortak dosyayı silmeye karar vermez */
    public function test_ayni_sunucu_kullanici_ve_klasoru_gosteren_sftp_ve_ftps_hedefinde_saklama_ortak_dosyayi_silmez(): void
    {
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
        $uzunHedef = YedekHedefi::factory()->create(['ad' => 'SFTP uzun', 'tur' => HedefTuru::Sftp, 'baglanti' => self::SFTP, 'gizli_bilgiler' => ['sifre' => 'p']]);
        $kisaHedef = YedekHedefi::factory()->create(['ad' => 'FTPS kısa', 'tur' => HedefTuru::Ftps, 'baglanti' => ['sunucu' => 'DOSYA.ornek.test', 'port' => 21, 'kullanici_adi' => 'Yedekci', 'klasor' => 'emor/'], 'gizli_bilgiler' => ['sifre' => 'p']]);
        $uzun = $this->plan($uzunHedef, 10);
        $kisa = $this->plan($kisaHedef, 1);
        $eski = $this->yedek(5);
        $yeni = $this->yedek(1);
        $kisaKopyalar = [];
        foreach ([$eski, $yeni] as $y) {
            $this->kopya($y, $uzunHedef, $uzun, $y->dosya_adi.'.gpg');
            $kisaKopyalar[$y->id] = $this->kopya($y, $kisaHedef, $kisa, $y->dosya_adi.'.gpg');
        }

        $hesap = app(SaklamaPolitikasi::class)->hesapla();

        $silinecekKopyalar = array_map(fn (SilmeKarari $k): ?int => $k->kopyaId, array_filter($hesap['silinecek'], fn (SilmeKarari $k): bool => $k->tur === SilmeKarari::DOSYA));
        $this->assertNotContains($kisaKopyalar[$eski->id]->id, $silinecekKopyalar);
        $this->assertSame([[$kisaKopyalar[$eski->id]->id, [$uzunHedef->id]]], array_map(fn (array $e): array => [$e['karar']->kopyaId, $e['hedefler']], $hesap['engellenen']));
        $this->assertContains('ayni_depo', $hesap['korunan'][$eski->id] ?? []);
    }
}
