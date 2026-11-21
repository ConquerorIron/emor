<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeriYukleme;
use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\RolServisi;
use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\Istekler\IstekCalistiricisi;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKilidi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use App\Yetki\Izin;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\SahtePostgres;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-025: çalıştırıcı (`yedek:calistir`) ve istek klasörü. Çalıştırıcı
 * isteğe güvenmez: biçim, şema, tür / biçim, dosya türü ve kullanıcının
 * yetkisi (veritabanından, işlem anında) yeniden denetlenir (README §5.2).
 */
final class YedekCalistiriciTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SahtePostgres;
    use YedeklemeOrtami;

    private Yedek $yedek;

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true, 'yedekleme.calistirici' => 'dogrudan', 'yedekleme.sure_siniri.kilit_bekleme' => 1]);
        $this->sahtePostgres();

        $kilit = app(YedeklemeYapilandirmasi::class)->islemDurumu()->kilit();
        $this->assertTrue($kilit->al());
        $this->yedek = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $kilit->birak();
        $this->assertSame(YedekDurumu::Tamam, $this->yedek->durum);

        $this->yonetici = User::factory()->yonetici()->create();
    }

    protected function tearDown(): void
    {
        BellekHedefi::sifirla();
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /**
     * @param  list<string>  $izinler
     */
    private function kullanici(array $izinler): User
    {
        $kullanici = User::factory()->create();
        if ($izinler !== []) {
            $kullanici->roller()->attach(app(RolServisi::class)->kaydet(null, ['ad' => 'Rol '.Str::random(8), 'izinler' => $izinler]));
        }

        return $kullanici;
    }

    private function kutu(): IstekKutusu
    {
        return app(IstekKutusu::class);
    }

    private function bekleyen(string $kimlik): string
    {
        $klasor = config('yedekleme.istek_klasoru').'/bekleyen';
        @mkdir($klasor, 0770, true);

        return $klasor.'/'.$kimlik.'.json';
    }

    /**
     * Elle yazılmış (web süreci taklidi) istek; IstekKutusu::yaz'ın denetimini atlar.
     *
     * @param  array<string, mixed>  $veri
     */
    private function hamIstek(array $veri): string
    {
        $kimlik = is_string($veri['kimlik'] ?? null) && preg_match('/^[0-9a-f-]{36}$/D', $veri['kimlik']) === 1 ? $veri['kimlik'] : (string) Str::uuid();
        file_put_contents($this->bekleyen($kimlik), json_encode($veri, JSON_UNESCAPED_SLASHES));

        return $kimlik;
    }

    /**
     * @return array<string, mixed>
     */
    private function gecerliIstek(string $kimlik): array
    {
        return [
            'surum' => 1,
            'kimlik' => $kimlik,
            'tur' => 'sabitle',
            'parametreler' => ['yedek_kimligi' => $this->yedek->kimlik],
            'kullanici_id' => $this->yonetici->id,
            'zaman' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:sP'),
        ];
    }

    private function tur(): int
    {
        return app(IstekCalistiricisi::class)->tur();
    }

    /**
     * @return array<string, mixed>
     */
    private function sonuc(string $kimlik): array
    {
        $durum = $this->kutu()->durum($kimlik);
        $this->assertIsArray($durum);

        return $durum;
    }

    private function reddedildi(string $kimlik, string $kod): void
    {
        $sonuc = $this->sonuc($kimlik);
        $this->assertSame('basarisiz', $sonuc['durum']);
        $this->assertSame($kod, $sonuc['kod']);
        $this->assertSame([], $this->kutu()->bekleyenler());
        $this->assertSame([], $this->kutu()->isleniyorlar());
        $this->assertTrue(YedekIslemKaydi::query()->where('islem', 'istek')->where('sonuc', 'basarisiz')->get()
            ->contains(fn (YedekIslemKaydi $k): bool => ($k->ayrinti['istek'] ?? null) === $kimlik && ($k->ayrinti['kod'] ?? null) === $kod), 'Red denetim kaydına yazılır');
    }

    public function test_yetkili_kullanicinin_istegi_islenir_sonuc_yedek_kimligini_tasir(): void
    {
        $kullanici = $this->kullanici([Izin::YedeklerAl->value]);
        $kimlik = $this->kutu()->yaz('yedek_al', ['kaynak' => 'elle', 'aciklama' => 'Ay sonu'], $kullanici->id);
        $this->assertSame('kuyrukta', $this->sonuc($kimlik)['durum']);

        $this->artisan('yedek:calistir', ['--tek-tur' => true])->assertSuccessful();

        $yeni = Yedek::query()->where('id', '!=', $this->yedek->id)->sole();
        $sonuc = $this->sonuc($kimlik);
        $this->assertSame('tamam', $sonuc['durum']);
        $this->assertSame($yeni->kimlik, $sonuc['yedek_kimligi']);
        $this->assertSame('Ay sonu', $yeni->aciklama);
        $this->assertSame($kullanici->id, $yeni->alan_kullanici_id);
        $this->assertSame([], $this->kutu()->isleniyorlar());
    }

    public function test_yetkisiz_pasif_ya_da_olmayan_kullanici_adina_yazilmis_istek_reddedilir(): void
    {
        $izinsiz = $this->kullanici([Izin::YedeklerGoruntule->value]);
        $pasif = User::factory()->yonetici()->pasif()->create();
        $kimlikler = [];
        foreach ([$izinsiz->id, $pasif->id, 999_999] as $kullaniciId) {
            $kimlikler[$kullaniciId] = $this->kutu()->yaz('sabitle', ['yedek_kimligi' => $this->yedek->kimlik], $kullaniciId);
        }

        $this->tur();

        foreach ($kimlikler as $kullaniciId => $kimlik) {
            $this->reddedildi($kimlik, 'ISTEK_YETKI_YOK');
            $this->assertTrue(YedekIslemKaydi::query()->where('islem', 'istek')->get()
                ->contains(fn (YedekIslemKaydi $k): bool => ($k->ayrinti['istekteki_kullanici'] ?? null) === $kullaniciId && $k->kullanici_id === null));
        }
        $this->assertFalse($this->yedek->refresh()->sabit);
    }

    /**
     * @return array<string, array{0: string, 1: Closure(Yedek): array<string, mixed>, 2: Izin}>
     */
    public static function izinEslemesi(): array
    {
        $yedekli = fn (Yedek $y): array => ['yedek_kimligi' => $y->kimlik];

        return [
            'yedek_al' => ['yedek_al', fn (): array => ['kaynak' => 'elle', 'aciklama' => null], Izin::YedeklerAl],
            'dogrula' => ['dogrula', $yedekli, Izin::YedeklerAl],
            'aciklama' => ['aciklama', fn (Yedek $y): array => ['yedek_kimligi' => $y->kimlik, 'aciklama' => 'x'], Izin::YedeklerAl],
            'tara' => ['tara', fn (): array => [], Izin::YedeklerAl],
            'sabitle' => ['sabitle', $yedekli, Izin::YedeklerSil],
            'sabit_coz' => ['sabit_coz', $yedekli, Izin::YedeklerSil],
            'sil' => ['sil', $yedekli, Izin::YedeklerSil],
            'plan_calistir' => ['plan_calistir', fn (): array => ['plan_id' => 1], Izin::YedekPlanlariGuncelle],
            'kopya_geri_yukle' => ['kopya_geri_yukle', fn (Yedek $y): array => ['yedek_kimligi' => $y->kimlik, 'ek' => null], Izin::GeriYuklemeKopya],
            'kopya_sil' => ['kopya_sil', fn (): array => ['veritabani' => 'erp_geri_20261121_103000'], Izin::GeriYuklemeKopya],
        ];
    }

    /**
     * @param  Closure(Yedek): array<string, mixed>  $parametreler
     */
    #[DataProvider('izinEslemesi')]
    public function test_her_tur_kendi_iznini_ister_diger_yedek_izinleri_yetmez(string $tur, Closure $parametreler, Izin $gereken): void
    {
        $digerleri = array_values(array_filter(
            [Izin::YedeklerGoruntule, Izin::YedeklerAl, Izin::YedeklerSil, Izin::YedeklerIndir, Izin::GeriYuklemeKopya, Izin::YedekPlanlariGoruntule, Izin::YedekPlanlariGuncelle],
            fn (Izin $i): bool => $i !== $gereken,
        ));
        $kullanici = $this->kullanici(array_map(fn (Izin $i): string => $i->value, $digerleri));
        $this->assertNotContains($gereken->value, $kullanici->izinler());
        $kimlik = $this->kutu()->yaz($tur, $parametreler($this->yedek), $kullanici->id);

        $this->tur();

        $this->reddedildi($kimlik, 'ISTEK_YETKI_YOK');
    }

    /** Bu sürecin (testin yazdığı istek dosyalarının sahibinin) uid'si */
    private function uid(): int
    {
        return stat('/proc/self')['uid'];
    }

    /** Testin yazdığı dosyalar yayın kullanıcısınınmış gibi (web başka biri) */
    private function yayinKullanicisiOl(): void
    {
        config(['yedekleme.web_uid' => $this->uid() + 1, 'yedekleme.yayin_uid' => $this->uid()]);
    }

    public function test_kullanicisiz_istek_yalniz_yayin_oncesi_yedekte_kabul_edilir(): void
    {
        $this->yayinKullanicisiOl();
        $sabitle = $this->kutu()->yaz('sabitle', ['yedek_kimligi' => $this->yedek->kimlik], null);
        $elle = $this->kutu()->yaz('yedek_al', ['kaynak' => 'elle', 'aciklama' => null], null);
        $kullaniciliYayin = $this->kutu()->yaz('yedek_al', ['kaynak' => 'yayin_oncesi', 'aciklama' => null], $this->yonetici->id);
        $yayin = $this->kutu()->yaz('yedek_al', ['kaynak' => 'yayin_oncesi', 'aciklama' => null], null);

        $this->tur();

        $this->reddedildi($sabitle, 'ISTEK_KULLANICI_YOK');
        $this->reddedildi($elle, 'ISTEK_KULLANICI_YOK');
        $this->reddedildi($kullaniciliYayin, 'ISTEK_YETKI_YOK');
        $this->assertSame('tamam', $this->sonuc($yayin)['durum']);
        $this->assertSame(1, Yedek::query()->where('kaynak', YedekKaynagi::YayinOncesi->value)->count());
    }

    public function test_gonderen_dosyanin_sahibiyle_dogrulanir_web_yayin_istisnasini_taklit_edemez(): void
    {
        // Web kullanıcısının dosyası: kullanıcılı istek geçer, kullanıcısız yayın isteği geçmez
        config(['yedekleme.web_uid' => $this->uid(), 'yedekleme.yayin_uid' => $this->uid() + 1]);
        $webinYayini = $this->kutu()->yaz('yedek_al', ['kaynak' => 'yayin_oncesi', 'aciklama' => null], null);
        $sabitle = $this->kutu()->yaz('sabitle', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);

        $this->tur();

        $this->reddedildi($webinYayini, 'ISTEK_GONDEREN_DOGRULANAMADI');
        $this->assertTrue(YedekIslemKaydi::query()->where('islem', 'istek')->get()
            ->contains(fn (YedekIslemKaydi $k): bool => ($k->ayrinti['istek'] ?? null) === $webinYayini && ($k->ayrinti['gonderen_uid'] ?? null) === $this->uid()));
        $this->assertSame('tamam', $this->sonuc($sabitle)['durum']);
        $this->assertSame(0, Yedek::query()->where('kaynak', YedekKaynagi::YayinOncesi->value)->count());

        // Yayın kullanıcısının dosyası: yayın isteği geçer, kullanıcı adına yazılmış istek geçmez
        $this->yayinKullanicisiOl();
        $yayininKullaniciIstegi = $this->kutu()->yaz('sabit_coz', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);
        $yayin = $this->kutu()->yaz('yedek_al', ['kaynak' => 'yayin_oncesi', 'aciklama' => null], null);

        $this->tur();

        $this->reddedildi($yayininKullaniciIstegi, 'ISTEK_GONDEREN_DOGRULANAMADI');
        $this->assertTrue($this->yedek->refresh()->sabit, 'Tanınmayan gönderenin isteği işlenmedi');
        $this->assertSame('tamam', $this->sonuc($yayin)['durum']);
        $this->assertSame(1, Yedek::query()->where('kaynak', YedekKaynagi::YayinOncesi->value)->count());
    }

    public function test_kilit_beklenirken_yetkisi_alinan_kullanicinin_isi_hic_baslamaz(): void
    {
        $kullanici = $this->kullanici([Izin::YedeklerSil->value]);
        $kimlik = $this->kutu()->yaz('sabitle', ['yedek_kimligi' => $this->yedek->kimlik], $kullanici->id);
        $kilit = $this->kilitAl();
        Sleep::fake();
        // Çalıştırıcı kilidi beklerken: yetki alınır, kilit boşalır
        Sleep::whenFakingSleep(function () use ($kullanici, $kilit): void {
            $kullanici->roller()->detach();
            $kilit->birak();
        });

        $this->tur();

        $this->reddedildi($kimlik, 'ISTEK_YETKI_YOK');
        $this->assertFalse($this->yedek->refresh()->sabit, 'Yetkisi alınan kullanıcının işi başlamadı');
        Sleep::assertSleptTimes(1);
    }

    /**
     * @return array<string, array{0: Closure(array<string, mixed>): (array<string, mixed>|string), 1: string}>
     */
    public static function kotuIstekler(): array
    {
        return [
            'JSON değil' => [fn (array $v): string => '{"surum": 1, "kimlik": ', 'ISTEK_GECERSIZ'],
            'nesne değil' => [fn (array $v): string => json_encode(array_values($v)), 'ISTEK_GECERSIZ'],
            'beklenmeyen üst alan' => [fn (array $v): array => [...$v, 'komut' => 'rm -rf /'], 'ISTEK_GECERSIZ'],
            'eksik üst alan' => [fn (array $v): array => array_diff_key($v, ['zaman' => true]), 'ISTEK_GECERSIZ'],
            'bilinmeyen sürüm' => [fn (array $v): array => [...$v, 'surum' => 2], 'ISTEK_GECERSIZ'],
            'kimlik dosya adından farklı' => [fn (array $v): array => [...$v, 'kimlik' => (string) Str::uuid()], 'ISTEK_GECERSIZ'],
            'kimlikte yol kaçışı' => [fn (array $v): array => [...$v, 'kimlik' => '../../durum/islem'], 'ISTEK_GECERSIZ'],
            'kullanıcı kimliği metin' => [fn (array $v): array => [...$v, 'kullanici_id' => (string) $v['kullanici_id']], 'ISTEK_GECERSIZ'],
            'zaman biçimsiz' => [fn (array $v): array => [...$v, 'zaman' => 'dün'], 'ISTEK_GECERSIZ'],
            'taşan tarih' => [fn (array $v): array => [...$v, 'zaman' => '2026-02-30T10:00:00+00:00'], 'ISTEK_GECERSIZ'],
            'bayat istek' => [fn (array $v): array => [...$v, 'zaman' => CarbonImmutable::now('UTC')->subDays(2)->format('Y-m-d\TH:i:sP')], 'ISTEK_SURESI_DOLDU'],
            'bilinmeyen tür' => [fn (array $v): array => [...$v, 'tur' => 'sql_calistir'], 'ISTEK_TURU_GECERSIZ'],
            'tür metin değil' => [fn (array $v): array => [...$v, 'tur' => ['sabitle']], 'ISTEK_TURU_GECERSIZ'],
            'beklenmeyen parametre' => [fn (array $v): array => [...$v, 'parametreler' => [...$v['parametreler'], 'yol' => '/etc/passwd']], 'ISTEK_PARAMETRE_GECERSIZ'],
            'eksik parametre' => [fn (array $v): array => [...$v, 'parametreler' => new \stdClass], 'ISTEK_PARAMETRE_GECERSIZ'],
            'parametreler liste' => [fn (array $v): array => [...$v, 'parametreler' => [$v['parametreler']['yedek_kimligi']]], 'ISTEK_PARAMETRE_GECERSIZ'],
            'değer dizi' => [fn (array $v): array => [...$v, 'parametreler' => ['yedek_kimligi' => ['$ne' => null]]], 'ISTEK_PARAMETRE_GECERSIZ'],
            'yedek kimliğinde yol' => [fn (array $v): array => [...$v, 'parametreler' => ['yedek_kimligi' => '../yedekler/x']], 'ISTEK_PARAMETRE_GECERSIZ'],
            'yedek kimliği sonunda satır sonu' => [fn (array $v): array => [...$v, 'parametreler' => ['yedek_kimligi' => $v['parametreler']['yedek_kimligi']."\n"]], 'ISTEK_PARAMETRE_GECERSIZ'],
            'plan kimliği metin' => [fn (array $v): array => [...$v, 'tur' => 'plan_calistir', 'parametreler' => ['plan_id' => '1']], 'ISTEK_PARAMETRE_GECERSIZ'],
            'kopya adı erp' => [fn (array $v): array => [...$v, 'tur' => 'kopya_sil', 'parametreler' => ['veritabani' => 'erp']], 'ISTEK_PARAMETRE_GECERSIZ'],
            'ek kalıp dışı' => [fn (array $v): array => [...$v, 'tur' => 'kopya_geri_yukle', 'parametreler' => ['yedek_kimligi' => $v['parametreler']['yedek_kimligi'], 'ek' => 'x; DROP']], 'ISTEK_PARAMETRE_GECERSIZ'],
            'yedek kaynağı tanınmıyor' => [fn (array $v): array => [...$v, 'tur' => 'yedek_al', 'parametreler' => ['kaynak' => 'plan', 'aciklama' => null]], 'ISTEK_PARAMETRE_GECERSIZ'],
        ];
    }

    /**
     * @param  Closure(array<string, mixed>): (array<string, mixed>|string)  $boz
     */
    #[DataProvider('kotuIstekler')]
    public function test_bozuk_ya_da_kotu_niyetli_istek_hicbir_is_yapmadan_reddedilir(Closure $boz, string $kod): void
    {
        $kimlik = (string) Str::uuid();
        $icerik = $boz($this->gecerliIstek($kimlik));
        file_put_contents($this->bekleyen($kimlik), is_string($icerik) ? $icerik : json_encode($icerik, JSON_UNESCAPED_SLASHES));
        $yedekSayisi = Yedek::query()->count();

        $this->tur();

        $this->reddedildi($kimlik, $kod);
        $this->assertFalse($this->yedek->refresh()->sabit);
        $this->assertSame($yedekSayisi, Yedek::query()->count());
    }

    public function test_gecerli_istegin_aynisi_islenir(): void
    {
        // Kötü istek testlerinin dayanağı: aynı temel istek bozulmadan geçer (T7)
        $kimlik = $this->hamIstek($this->gecerliIstek((string) Str::uuid()));

        $this->tur();

        $this->assertSame('tamam', $this->sonuc($kimlik)['durum']);
        $this->assertTrue($this->yedek->refresh()->sabit);
    }

    public function test_baglanti_sabit_baglanti_ve_buyuk_dosya_okunmaz_hedefe_dokunulmaz(): void
    {
        $disari = $this->yedekKoku.'/web-yazdi';
        mkdir($disari);
        $sembolik = (string) Str::uuid();
        file_put_contents($disari.'/hedef.json', json_encode($this->gecerliIstek($sembolik)));
        symlink($disari.'/hedef.json', $this->bekleyen($sembolik));
        $sabit = (string) Str::uuid();
        file_put_contents($disari.'/sabit.json', json_encode($this->gecerliIstek($sabit)));
        link($disari.'/sabit.json', $this->bekleyen($sabit));
        $buyuk = (string) Str::uuid();
        file_put_contents($this->bekleyen($buyuk), json_encode($this->gecerliIstek($buyuk)).str_repeat(' ', IstekKutusu::EN_BUYUK_BOYUT));
        $onceki = (string) file_get_contents($disari.'/hedef.json');

        $this->tur();

        $this->reddedildi($sembolik, 'ISTEK_DOSYASI_GECERSIZ');
        $this->reddedildi($sabit, 'ISTEK_DOSYASI_GECERSIZ');
        $this->reddedildi($buyuk, 'ISTEK_DOSYASI_GECERSIZ');
        $this->assertSame($onceki, file_get_contents($disari.'/hedef.json'), 'Bağlantının hedefi değişmez');
        $this->assertFileExists($disari.'/sabit.json');
        $this->assertFalse($this->yedek->refresh()->sabit);
    }

    public function test_kimligi_uuid_olmayan_dosya_islenmez_durum_sorgusu_yol_kacisini_reddeder(): void
    {
        file_put_contents($this->bekleyen('baska'), json_encode($this->gecerliIstek((string) Str::uuid())));

        $this->assertSame(0, $this->tur());
        $this->assertFileExists($this->bekleyen('baska'));

        $this->expectException(\InvalidArgumentException::class);
        $this->kutu()->durum('../sonuc/x');
    }

    public function test_istek_bir_kez_ustlenilir_ustlenilen_istek_calisiyor_gorunur(): void
    {
        $kimlik = $this->kutu()->yaz('tara', [], $this->yonetici->id);
        $yol = $this->kutu()->bekleyenler()[0];

        $ilk = $this->kutu()->talepEt($yol);
        $ikinci = $this->kutu()->talepEt($yol);

        $this->assertNotNull($ilk);
        $this->assertNull($ikinci, 'İkinci çalıştırıcı aynı isteği alamaz');
        $this->assertSame('calisiyor', $this->sonuc($kimlik)['durum']);
    }

    public function test_baska_calistirici_calisirken_tur_atlanir(): void
    {
        $yapilandirma = app(YedeklemeYapilandirmasi::class);
        $diger = new YedekKilidi($yapilandirma->durumKlasoru().'/calistirici', $yapilandirma->kaliciKok());
        $this->assertTrue($diger->al());
        $kimlik = $this->kutu()->yaz('tara', [], $this->yonetici->id);

        try {
            $this->assertSame(0, $this->tur());
            $this->assertSame('kuyrukta', $this->sonuc($kimlik)['durum']);
        } finally {
            $diger->birak();
        }
    }

    public function test_islenirken_olen_surecin_istegi_sonraki_turda_yarida_kaldi_olur(): void
    {
        $kimlik = (string) Str::uuid();
        $klasor = config('yedekleme.istek_klasoru').'/isleniyor';
        mkdir($klasor, 0750, true);
        file_put_contents($klasor.'/'.$kimlik.'.json', json_encode($this->gecerliIstek($kimlik)));

        $this->tur();

        $this->reddedildi($kimlik, 'ISTEK_YARIDA_KALDI');
        $this->assertFalse($this->yedek->refresh()->sabit, 'Yarıda kalan istek yeniden çalıştırılmaz');
    }

    /**
     * G1 / BY-014-R2: sonuç yerine taşınıp yalnız klasör eşitlemesi
     * doğrulanamadıysa sonuç yazılmış sayılır; üstlenilen dosya kalıp
     * sonraki turda gerçek sonucu "yarıda kaldı" ile ezmez.
     */
    public function test_sonuc_klasoru_esitlenemese_de_yazilan_sonuc_sonraki_turda_yarida_kaldi_ile_ezilmez(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root klasör iznini aşar (T11).');
        }
        $kimlik = $this->hamIstek($this->gecerliIstek((string) Str::uuid()));
        $sonucKlasoru = config('yedekleme.istek_klasoru').'/sonuc';
        @mkdir($sonucKlasoru, 0750, true);
        // Yazılabilir ve geçilebilir ama okunamaz: taşıma olur, eşitlemek için açılamaz
        chmod($sonucKlasoru, 0300);

        try {
            $this->tur();
        } finally {
            chmod($sonucKlasoru, 0750);
        }
        $this->tur();

        $this->assertSame('tamam', $this->sonuc($kimlik)['durum']);
        $this->assertSame([], glob(config('yedekleme.istek_klasoru').'/isleniyor/*.json'));
    }

    public function test_bir_haftadan_eski_sonuclar_silinir(): void
    {
        $eski = (string) Str::uuid();
        $yeni = (string) Str::uuid();
        $this->kutu()->sonucYaz($eski, 'tamam');
        $this->kutu()->sonucYaz($yeni, 'tamam');
        touch(config('yedekleme.istek_klasoru').'/sonuc/'.$eski.'.json', time() - 8 * 86_400);

        $this->tur();

        $this->assertNull($this->kutu()->durum($eski));
        $this->assertSame('tamam', $this->sonuc($yeni)['durum']);
    }

    public function test_kilit_baska_islemdeyken_istek_kilit_mesgul_ile_reddedilir(): void
    {
        $this->kilitAl();
        $kimlik = $this->kutu()->yaz('sabitle', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);

        $this->tur();

        $this->reddedildi($kimlik, 'YEDEK_KILIT_MESGUL');
        $this->assertFalse($this->yedek->refresh()->sabit);
    }

    public function test_acik_ustune_geri_yukleme_varken_istek_islenmez(): void
    {
        $kilit = $this->kilitAl();
        app(YedeklemeYapilandirmasi::class)->islemDurumu()->yaz(new IslemKaydi(
            kimlik: (string) Str::uuid(), tur: IslemTuru::GeriYuklemeUstune, adim: 'onay_bekliyor', acik: true,
            baslangic: CarbonImmutable::now('UTC'), sonGuncelleme: CarbonImmutable::now('UTC'), hata: null, erisimAnahtariOzeti: null,
        ), $kilit);
        $kilit->birak();
        $this->tutulanKilit = null;
        $kimlik = $this->kutu()->yaz('sabitle', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);

        $this->tur();

        $this->reddedildi($kimlik, 'YEDEK_ACIK_GERI_YUKLEME');
        $this->assertFalse($this->yedek->refresh()->sabit);
    }

    public function test_acik_islem_yokken_onay_istegi_anahtar_dogrulanamadigi_icin_reddedilir(): void
    {
        // Onayın yetkisi erişim anahtarından üretilen karar kanıtıdır (BY-029, BY-030-R1);
        // işlem durumu yoksa doğrulanamaz
        $kimlik = $this->kutu()->yaz('onayla', [
            'islem_kimligi' => (string) Str::uuid(), 'son_gecerlilik' => now()->getTimestamp() + 60,
            'nonce' => str_repeat('a', 32), 'ayar_secimleri' => '', 'kanit' => str_repeat('b', 64),
        ], $this->yonetici->id);

        $this->tur();

        $this->reddedildi($kimlik, 'GERI_YUKLEME_ERISIM_ANAHTARI_GECERSIZ');
    }

    public function test_sabitle_coz_ve_not_olay_gunlugune_dizine_ve_denetim_kaydina_yazilir(): void
    {
        $sabitle = $this->kutu()->yaz('sabitle', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);
        $not = $this->kutu()->yaz('aciklama', ['yedek_kimligi' => $this->yedek->kimlik, 'aciklama' => 'Göç öncesi'], $this->yonetici->id);
        $this->tur();

        $this->assertSame('tamam', $this->sonuc($sabitle)['durum']);
        $this->assertSame($this->yedek->kimlik, $this->sonuc($not)['yedek_kimligi']);
        $this->yedek->refresh();
        $this->assertTrue($this->yedek->sabit);
        $this->assertSame('Göç öncesi', $this->yedek->aciklama);
        $durum = app(OlayGunlugu::class)->durumlar()[$this->yedek->kimlik];
        $this->assertTrue($durum->sabit);
        $this->assertSame('Göç öncesi', $durum->aciklama);
        $this->assertSame(1, YedekIslemKaydi::query()->where('islem', 'sabitle')->where('kullanici_id', $this->yonetici->id)->count());

        $coz = $this->kutu()->yaz('sabit_coz', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);
        $this->tur();

        $this->assertSame('tamam', $this->sonuc($coz)['durum']);
        $this->assertFalse($this->yedek->refresh()->sabit);
        $this->assertFalse(app(OlayGunlugu::class)->durumlar()[$this->yedek->kimlik]->sabit);
    }

    public function test_sil_yerel_dosyayi_ve_kopyalari_siler_manifest_kalir(): void
    {
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        BellekHedefi::$dosyalar['k/1'] = 'kopya';
        YedekKopyasi::factory()->plansiz()->create(['yedek_id' => $this->yedek->id, 'yedek_hedefi_id' => $hedef->id, 'uzak_kimlik' => 'k/1']);
        $dosya = config('yedekleme.klasor').'/'.$this->yedek->dosya_adi;
        $kimlik = $this->kutu()->yaz('sil', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);

        $this->tur();

        $sonuc = $this->sonuc($kimlik);
        $this->assertSame('tamam', $sonuc['durum']);
        $this->assertSame('silindi', $sonuc['yedek_durumu']);
        $this->assertSame(YedekDurumu::Silindi, $this->yedek->refresh()->durum);
        $this->assertFileDoesNotExist($dosya);
        $this->assertFileExists(ManifestDeposu::jsonYolu($dosya));
        $this->assertSame([], BellekHedefi::$dosyalar);
        $this->assertSame(KopyaDurumu::Silindi, YedekKopyasi::query()->sole()->durum);
    }

    public function test_bir_kopya_silinemezse_yedek_silindi_olmaz_hata_bildirilir(): void
    {
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        BellekHedefi::$dosyalar['k/1'] = 'kopya';
        BellekHedefi::$hataAdimi = 'sil';
        YedekKopyasi::factory()->plansiz()->create(['yedek_id' => $this->yedek->id, 'yedek_hedefi_id' => $hedef->id, 'uzak_kimlik' => 'k/1']);
        $kimlik = $this->kutu()->yaz('sil', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);

        $this->tur();

        $this->assertSame('YEDEK_KOPYA_SILINEMEDI', $this->sonuc($kimlik)['kod']);
        // F7: uzakta doğrulanmış kopya kaldıkça yedek "silindi" değildir
        $this->assertSame(YedekDurumu::Tamam, $this->yedek->refresh()->durum);
        $this->assertNotNull($this->yedek->yerel_silinme_zamani);
        $this->assertSame(KopyaDurumu::Tamam, YedekKopyasi::query()->sole()->durum);
    }

    public function test_sabit_ya_da_kullanimdaki_yedek_silinmez(): void
    {
        $this->yedek->update(['sabit' => true]);
        $sabit = $this->kutu()->yaz('sil', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);
        $kullanimda = Yedek::factory()->create();
        YedekKopyasi::factory()->plansiz()->create(['yedek_id' => $kullanimda->id, 'durum' => KopyaDurumu::Kopyalaniyor]);
        $kopyalanan = $this->kutu()->yaz('sil', ['yedek_kimligi' => $kullanimda->kimlik], $this->yonetici->id);

        $this->tur();

        $this->reddedildi($sabit, 'YEDEK_SABIT');
        $this->reddedildi($kopyalanan, 'YEDEK_KULLANIMDA');
        $this->assertFileExists(config('yedekleme.klasor').'/'.$this->yedek->dosya_adi);
    }

    public function test_tara_ve_plan_calistir_islenir_olmayan_plan_reddedilir(): void
    {
        $tara = $this->kutu()->yaz('tara', [], $this->yonetici->id);
        $this->tur();

        $this->assertSame(1, $this->sonuc($tara)['tarama']['guvenilir']);

        $plan = YedekPlani::factory()->create();
        $calistir = $this->kutu()->yaz('plan_calistir', ['plan_id' => $plan->id], $this->yonetici->id);
        $yok = $this->kutu()->yaz('plan_calistir', ['plan_id' => $plan->id + 100], $this->yonetici->id);
        $this->tur();

        $sonuc = $this->sonuc($calistir);
        $this->assertSame('tamam', $sonuc['durum'], json_encode($sonuc, JSON_UNESCAPED_UNICODE));
        $this->assertSame(YedekKaynagi::Plan, Yedek::query()->where('kimlik', $sonuc['yedek_kimligi'])->sole()->kaynak);
        $this->assertNotNull($plan->refresh()->son_calisma);
        $this->reddedildi($yok, 'YEDEK_PLANI_BULUNAMADI');
    }

    public function test_dogrula_kopya_geri_yukle_ve_kopya_sil_sonuclari_ekranin_alanlarini_tasir(): void
    {
        $dogrula = $this->kutu()->yaz('dogrula', ['yedek_kimligi' => $this->yedek->kimlik], $this->yonetici->id);
        $kopya = $this->kutu()->yaz('kopya_geri_yukle', ['yedek_kimligi' => $this->yedek->kimlik, 'ek' => 'inceleme'], $this->yonetici->id);
        $this->tur();

        $this->assertSame('tamam', $this->sonuc($dogrula)['durum']);
        $this->assertSame(DogrulamaDurumu::SinamaGecti->value, $this->sonuc($dogrula)['dogrulama_durumu']);
        $sonuc = $this->sonuc($kopya);
        $this->assertSame('tamam', $sonuc['durum'], (string) ($sonuc['mesaj'] ?? ''));
        $this->assertSame($this->yedek->kimlik, $sonuc['yedek_kimligi']);
        $this->assertMatchesRegularExpression('/^erp_geri_\d{8}_\d{6}_inceleme$/D', $sonuc['hedef_veritabani']);
        $this->assertContains($sonuc['hedef_veritabani'], $this->veritabanlari);

        $sil = $this->kutu()->yaz('kopya_sil', ['veritabani' => $sonuc['hedef_veritabani']], $this->yonetici->id);
        $this->tur();

        $this->assertSame('tamam', $this->sonuc($sil)['durum']);
        $this->assertSame($sonuc['geri_yukleme_kimligi'], $this->sonuc($sil)['geri_yukleme_kimligi']);
        $this->assertNotContains($sonuc['hedef_veritabani'], $this->veritabanlari);
    }

    public function test_suresi_dolan_kopya_tur_sonunda_silinir_basarisiz_silme_her_turda_yinelenmez(): void
    {
        $ad = 'erp_geri_20261101_100000';
        $this->veritabanlari[] = $ad;
        $geri = GeriYukleme::query()->create([
            'kimlik' => (string) Str::uuid(), 'yedek_id' => $this->yedek->id, 'tur' => GeriYuklemeTuru::Kopya, 'hedef_veritabani' => $ad,
            'durum' => GeriYuklemeDurumu::Tamam, 'bitti' => CarbonImmutable::now()->subDays(10),
        ]);
        $silmeDenemesi = fn (PendingProcess $p): bool => str_contains((string) $p->input, 'DROP DATABASE') && in_array('--set=ad='.$ad, (array) $p->command, true);
        $this->silmeHatasi = 'psql: error: FATAL:  password authentication failed';
        $calistirici = app(IstekCalistiricisi::class);

        $calistirici->tur();
        $calistirici->tur();

        // D8: kalıcı hata servis döngüsünün her turunda yeniden denenmez
        Process::assertRanTimes($silmeDenemesi, 1);
        $this->assertNull($geri->refresh()->veritabani_silinme_zamani);

        $this->silmeHatasi = null;
        app(IstekCalistiricisi::class)->tur();

        $this->assertNotNull($geri->refresh()->veritabani_silinme_zamani);
        $this->assertNotContains($ad, $this->veritabanlari);
    }

    public function test_imzasiz_yedek_dogrulanmaz_ve_geri_yuklenmez(): void
    {
        $imzasiz = Yedek::factory()->create(['imzali' => false]);
        $dogrula = $this->kutu()->yaz('dogrula', ['yedek_kimligi' => $imzasiz->kimlik], $this->yonetici->id);
        $kopya = $this->kutu()->yaz('kopya_geri_yukle', ['yedek_kimligi' => $imzasiz->kimlik, 'ek' => null], $this->yonetici->id);

        $this->tur();

        $this->reddedildi($dogrula, 'YEDEK_DOGRULANAMAZ');
        $this->reddedildi($kopya, 'GERI_YUKLEME_YEDEK_GERI_YUKLENEMEZ');
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));
    }

    public function test_yazan_da_okuyanin_semasindan_gecer(): void
    {
        foreach ([
            ['sabitle', ['yedek_kimligi' => 'x']],
            ['sabitle', []],
            ['tara', ['fazla' => 1]],
            ['yedek_al', ['kaynak' => 'elle']],
            ['bilinmeyen', []],
        ] as [$tur, $parametreler]) {
            try {
                $this->kutu()->yaz($tur, $parametreler, $this->yonetici->id);
                $this->fail("Geçersiz istek yazıldı: {$tur}");
            } catch (\InvalidArgumentException) {
                // beklenen
            }
        }

        $this->assertSame([], $this->kutu()->bekleyenler());
    }

    public function test_modul_kapaliyken_tek_tur_hicbir_istegi_islemez(): void
    {
        $kimlik = $this->kutu()->yaz('tara', [], $this->yonetici->id);
        config(['yedekleme.aktif' => false]);

        $this->artisan('yedek:calistir', ['--tek-tur' => true])->assertSuccessful();

        $this->assertSame('kuyrukta', $this->sonuc($kimlik)['durum']);
    }

    public function test_servis_dongusu_istekleri_isler_ve_tur_siniri_dolunca_cikar(): void
    {
        Sleep::fake();
        $kimlik = $this->kutu()->yaz('tara', [], $this->yonetici->id);

        $this->artisan('yedek:calistir', ['--en-cok-tur' => 3])->assertSuccessful();

        $this->assertSame('tamam', $this->sonuc($kimlik)['durum']);
        Sleep::assertSleptTimes(3);
        $this->artisan('yedek:calistir', ['--en-cok-tur' => '0'])->assertExitCode(2);
    }

    public function test_zamanlayici_tek_turu_yalniz_modul_acik_ve_gecis_donemindeyken_calistirir(): void
    {
        Artisan::all();
        $olay = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'yedek:calistir --tek-tur'));
        $this->assertNotNull($olay);

        $this->assertTrue($olay->filtersPass($this->app));
        config(['yedekleme.calistirici' => 'servis']);
        $this->assertFalse($olay->filtersPass($this->app), 'Servis varken istekleri servis işler');
        config(['yedekleme.calistirici' => 'dogrudan', 'yedekleme.aktif' => false]);
        $this->assertFalse($olay->filtersPass($this->app), 'Kapalıyken etkisiz');
    }

    public function test_zamanlayici_planli_yedegi_ve_temizligi_servis_kipinde_calistirmaz(): void
    {
        Artisan::all();
        foreach (['yedek:zamanla', 'yedek:temizle'] as $komut) {
            $olay = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, $komut));
            $this->assertNotNull($olay);

            config(['yedekleme.calistirici' => 'dogrudan']);
            $this->assertTrue($olay->filtersPass($this->app), "{$komut}: geçiş kipinde zamanlayıcı çalıştırır");
            config(['yedekleme.calistirici' => 'servis']);
            $this->assertFalse($olay->filtersPass($this->app), "{$komut}: servis kipinde web hesabıyla çalışmaz");
        }
    }

    /**
     * Servis döngüsünün bir çalışması (systemd'nin başlattığı yeni süreç); çıktısı
     * döner. Artisan uygulaması sıfırlanır: komut nesnesi (bellekteki durumu)
     * çalışmalar arasında paylaşılmasın.
     */
    private function servisDongusu(): string
    {
        app(ConsoleKernel::class)->setArtisan(null);
        Artisan::call('yedek:calistir', ['--en-cok-tur' => 3]);

        return Artisan::output();
    }

    public function test_servis_kipinde_planli_yedegi_calistiricinin_dongusu_tetikler(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        Sleep::fake();
        $plan = YedekPlani::factory()->create();
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();

        $this->servisDongusu();

        $planli = Yedek::query()->where('kaynak', YedekKaynagi::Plan->value)->sole();
        $this->assertSame(YedekDurumu::Tamam, $planli->durum);
        $this->assertTrue($planli->imzali);
        $this->assertNotNull($plan->refresh()->son_calisma);
    }

    public function test_servis_kipinde_gunluk_temizlik_vadeden_sonra_gunde_bir_kez_yapilir(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        Sleep::fake();
        $gun = CarbonImmutable::now('Europe/Istanbul')->startOfDay();

        // Servis 04:10'da kapalıydı: açılınca (05:00) bir kez
        $this->travelTo($gun->setTime(5, 0));
        $this->assertSame(1, substr_count($this->servisDongusu(), 'Temizlik:'));
        // Aynı gün yeniden başlayan servis tekrarlamaz
        $this->travelTo($gun->setTime(23, 59));
        $this->assertSame(0, substr_count($this->servisDongusu(), 'Temizlik:'));
        // Ertesi gün vadeden önce yapılmaz, vadeden sonra yapılır
        $this->travelTo($gun->addDay()->setTime(4, 9));
        $this->assertSame(0, substr_count($this->servisDongusu(), 'Temizlik:'));
        $this->travelTo($gun->addDay()->setTime(4, 11));
        $this->assertSame(1, substr_count($this->servisDongusu(), 'Temizlik:'));
    }

    public function test_servis_kipinde_acik_geri_yuklemede_gunluk_temizlik_yapilmaz(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        Sleep::fake();
        $kilit = $this->kilitAl();
        app(YedeklemeYapilandirmasi::class)->islemDurumu()->yaz(new IslemKaydi(
            kimlik: (string) Str::uuid(), tur: IslemTuru::GeriYuklemeUstune, adim: 'onay_bekliyor', acik: true,
            baslangic: CarbonImmutable::now('UTC'), sonGuncelleme: CarbonImmutable::now('UTC'), hata: null, erisimAnahtariOzeti: null,
        ), $kilit);
        $kilit->birak();

        $this->assertSame(0, substr_count($this->servisDongusu(), 'Temizlik:'));
    }

    public function test_yayin_oncesi_yedek_servis_kipinde_calistiriciyla_uctan_uca_alinir(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        $this->yayinKullanicisiOl();
        Sleep::fake();
        // deploy.sh bekledikçe çalıştırıcı turu döner (ayrı süreç taklidi)
        Sleep::whenFakingSleep(fn () => app(IstekCalistiricisi::class)->tur());

        $this->artisan('yedek:yayin-oncesi')->assertSuccessful();

        $yayin = Yedek::query()->where('kaynak', YedekKaynagi::YayinOncesi->value)->sole();
        $this->assertSame(YedekDurumu::Tamam, $yayin->durum);
        $this->assertNull($yayin->alan_kullanici_id);
    }
}
