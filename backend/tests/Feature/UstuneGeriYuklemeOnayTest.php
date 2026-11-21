<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\YedeklemeMaili;
use App\Models\GeriYukleme;
use App\Models\MailAyari;
use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\BakimModu;
use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\Istekler\IstekCalistiricisi;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\OnayBilgisi;
use App\Services\Yedekleme\SiklikTuru;
use App\Services\Yedekleme\UstuneGeriYuklemeAdimi;
use App\Services\Yedekleme\UstuneGeriYukleyici;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event as ZamanlanmisGorev;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;
use Tests\Yedekleme\SahtePostgres;
use Tests\Yedekleme\UstuneGeriYuklemeOrtami;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-029: "onay bekleniyor" korumaları, onay / geri dönüş, onay bilgisi ve
 * acil durum komutu (README §6.4 9–11, §7.6, §4.8).
 */
final class UstuneGeriYuklemeOnayTest extends TestCase
{
    use LazilyRefreshDatabase;
    use SahtePostgres;
    use UstuneGeriYuklemeOrtami;
    use YedeklemeOrtami;

    /** Canlının özgün bağlanma yetkileri: belirli bir role ayrıca CONNECT verilmiş */
    private const OZGUN = '{=c/erp,erp=CTc/erp,emor_inceleme=c/erp}';

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ustuneOrtaminiKur();
        $this->yonetici = User::factory()->yonetici()->create();
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /**
     * Onay bekleyen gerçek bir işlem (akışın kendisiyle kurulur).
     *
     * @return array{0: IslemKaydi, 1: string, 2: Yedek}
     */
    private function onayBekleyen(): array
    {
        $yedek = $this->ustuneYedekAl();
        [$kayit, $anahtar] = $this->ustuneBaslat($yedek, $this->yonetici->id);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $kayit->adim);
        $this->komutlar = [];
        Mail::fake();

        return [$kayit, $anahtar, $yedek];
    }

    public function test_onay_bakim_modundan_cikar_kuyrugu_baslatir_islemi_kapatir_ve_dogrudan_bildirir(): void
    {
        [$kayit] = $this->onayBekleyen();

        $kilit = $this->kilitAl();
        $son = app(UstuneGeriYukleyici::class)->onayla($kilit, $kayit->kimlik);

        $this->assertSame(UstuneGeriYuklemeAdimi::Onaylandi->value, $son->adim);
        $this->assertFalse($son->acik);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertContains('systemctl start emor-queue', $this->komutlar);
        $this->assertSame(GeriYuklemeDurumu::Tamam, GeriYukleme::query()->where('kimlik', $kayit->kimlik)->firstOrFail()->durum);
        // Eski veritabanı silinmedi, kapalı bekliyor
        $this->assertContains($kayit->oncekiVeritabani, $this->veritabanlari);
        $this->assertSame([$kayit->oncekiVeritabani => true], $this->baglantiyaKapali);
        Mail::assertSent(YedeklemeMaili::class, 1);
        $this->assertFalse(app(YedeklemeYapilandirmasi::class)->islemDurumu()->acikGeriYuklemeVarMi());
    }

    public function test_geri_don_eski_veritabanini_canli_yapar_geri_yukleneni_silmeden_adlandirir(): void
    {
        // Canlıda belirli role bağlanma verilmiş; bariyer bunu daraltır
        $this->veritabaniYetkileri['erp'] = self::OZGUN;
        [$kayit] = $this->onayBekleyen();
        $this->assertSame(self::OZGUN, $this->veritabaniYetkileri[$kayit->oncekiVeritabani]);

        $kilit = $this->kilitAl();
        $son = app(UstuneGeriYukleyici::class)->geriDon($kilit, $kayit->kimlik);

        $this->assertSame(UstuneGeriYuklemeAdimi::GeriDonuldu->value, $son->adim);
        $this->assertFalse($son->acik);
        $this->assertSame(['kapat erp', "yer erp>{$kayit->hedefVeritabani} {$kayit->oncekiVeritabani}>erp", 'yetki erp '.self::OZGUN, 'ac erp', 'systemctl start emor-queue'], $this->komutlar);
        // Eski veritabanı özgün yetkileriyle canlı (BY-028-R1)
        $this->assertSame(self::OZGUN, $this->veritabaniYetkileri['erp']);
        // erp = eski veritabanı (yeniden açık); geri yüklenen kopya bilinen adıyla, silinmeden ve kapalı
        $this->assertEqualsCanonicalizing(['erp', 'postgres', (string) $kayit->hedefVeritabani], $this->veritabanlari);
        $this->assertSame([$kayit->hedefVeritabani => true], $this->baglantiyaKapali);
        $this->assertNotContains('sil '.$kayit->hedefVeritabani, $this->komutlar);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertSame(GeriYuklemeDurumu::GeriAlindi, GeriYukleme::query()->where('kimlik', $kayit->kimlik)->firstOrFail()->durum);
    }

    public function test_yer_degistirmeden_once_onay_verilmez_baska_islemin_kimligiyle_de(): void
    {
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::Migration, true);
        $kilit = $this->kilitAl();

        foreach ([$kayit->kimlik, (string) Str::uuid()] as $kimlik) {
            try {
                app(UstuneGeriYukleyici::class)->onayla($kilit, $kimlik);
                $this->fail('Onay verilmemeliydi.');
            } catch (GeriYuklemeHatasi $hata) {
                $this->assertSame(GeriYuklemeHatasi::ISLEM_DURUMU_UYGUN_DEGIL, $hata->kod);
            }
        }

        $this->assertSame(UstuneGeriYuklemeAdimi::Migration->value, $this->durumOku()?->adim);
        $this->assertSame([], $this->komutlar);
    }

    public function test_calistirici_ustune_geri_yuklemeyi_onayda_birakir_onay_istegini_sonraki_turda_isler(): void
    {
        $yedek = $this->ustuneYedekAl();
        $anahtar = ErisimAnahtari::uret();
        $baslat = app(IstekKutusu::class)->yaz('ustune_geri_yukle', ['yedek_kimligi' => $yedek->kimlik, 'onay_metni' => 'erp', 'erisim_anahtari_ozeti' => ErisimAnahtari::ozet($anahtar)], $this->yonetici->id);

        app(IstekCalistiricisi::class)->tur();

        $sonuc = app(IstekKutusu::class)->durum($baslat);
        $this->assertSame('tamam', $sonuc['durum'] ?? null);
        $this->assertSame($baslat, $sonuc['islem_kimligi']);
        $this->assertSame(ErisimAnahtari::ozet($anahtar), $sonuc['erisim_anahtari_ozeti']);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        // İşleyici döndü: kilit boşta (onay işlenebilir)
        $kilit = app(YedeklemeYapilandirmasi::class)->islemDurumu()->kilit();
        $this->assertTrue($kilit->al());
        $kilit->birak();

        // Açık geri yüklemede başka istek işlenmez, onay işlenir
        $sabitle = app(IstekKutusu::class)->yaz('sabitle', ['yedek_kimligi' => $yedek->kimlik], $this->yonetici->id);
        app(IstekCalistiricisi::class)->tur();
        $this->assertSame('YEDEK_ACIK_GERI_YUKLEME', app(IstekKutusu::class)->durum($sabitle)['kod'] ?? null);

        $onay = $this->kararIstegiYaz('onayla', $baslat, $anahtar, $this->yonetici->id);
        app(IstekCalistiricisi::class)->tur();
        $this->assertSame('tamam', app(IstekKutusu::class)->durum($onay)['durum'] ?? null);
        $this->assertSame(UstuneGeriYuklemeAdimi::Onaylandi->value, $this->durumOku()?->adim);
        // Anahtar istek dosyasında kalmadı
        $this->assertSame([], app(IstekKutusu::class)->bekleyenler());
        $this->assertSame([], app(IstekKutusu::class)->isleniyorlar());
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function anahtarReddi(): array
    {
        return [
            'başka anahtarla üretilmiş kanıt' => ['yanlis', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ],
            'süresi dolmuş anahtar' => ['suresi', GeriYuklemeHatasi::ERISIM_ANAHTARI_SURESI_DOLDU],
            'başka işlemin kimliği' => ['islem', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ],
            'isteyenden başka kullanıcı' => ['kullanici', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ],
            'dosyadaki seçim kanıtınkinden farklı' => ['secim', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ],
            'geri dönüş kanıtıyla onay' => ['karar', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ],
            'ömrü 5 dakikadan uzun kanıt' => ['uzun', GeriYuklemeHatasi::KARAR_SURESI_DOLDU],
        ];
    }

    #[DataProvider('anahtarReddi')]
    public function test_calistirici_karar_kanitini_islem_durumundaki_ozetle_dogrular(string $durum, string $kod): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, anahtar: $anahtar, bitis: $durum === 'suresi' ? CarbonImmutable::now('UTC')->subMinute() : null, isteyen: $this->yonetici->id);
        $diger = User::factory()->yonetici()->create();
        $islem = $durum === 'islem' ? (string) Str::uuid() : $kayit->kimlik;
        $kullanici = $durum === 'kullanici' ? $diger->id : $this->yonetici->id;

        $istek = match ($durum) {
            'yanlis' => $this->kararIstegiYaz('onayla', $islem, ErisimAnahtari::uret(), $kullanici),
            'secim' => $this->kararIstegiYaz('onayla', $islem, $anahtar, $kullanici, ['mail' => 'guncel'], kanitAyarSecimleri: ['mail' => 'geri_yuklenen']),
            'uzun' => $this->kararIstegiYaz('onayla', $islem, $anahtar, $kullanici, son: CarbonImmutable::now()->getTimestamp() + ErisimAnahtari::KANIT_OMRU_SN + 60),
            // Geri dönüş için üretilmiş kanıt onay isteğine konmuş
            'karar' => (function () use ($islem, $anahtar, $kullanici): string {
                $son = CarbonImmutable::now()->getTimestamp() + 60;
                $nonce = ErisimAnahtari::nonce();

                return app(IstekKutusu::class)->yaz('onayla', ['islem_kimligi' => $islem, 'son_gecerlilik' => $son, 'nonce' => $nonce, 'ayar_secimleri' => '', 'kanit' => ErisimAnahtari::kanit(ErisimAnahtari::ozet($anahtar), $islem, 'geri_don', $son, $nonce, '')], $kullanici);
            })(),
            default => $this->kararIstegiYaz('onayla', $islem, $anahtar, $kullanici),
        };
        app(IstekCalistiricisi::class)->tur();

        $this->assertSame($kod, app(IstekKutusu::class)->durum($istek)['kod'] ?? null);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        $this->assertSame([], $this->komutlar);
    }

    /**
     * BY-030-R1: onay isteğinde, sonucunda, durum / denetim dosyalarında ve
     * günlükte tam anahtar yok; isteğin süresi geçince karar uygulanmaz.
     */
    public function test_karar_istegi_tam_anahtari_hicbir_dosyaya_yazmaz_suresi_gecen_karar_uygulanmaz(): void
    {
        [$kayit, $anahtar] = $this->onayBekleyen();
        $gunluk = (string) config('logging.channels.yedekleme.path');
        $gunlukOnce = is_file($gunluk) ? (int) filesize($gunluk) : 0;

        // Web ucu anahtarı doğrular, isteğe kanıt yazar
        $istek = (string) $this->postJson('/api/v1/yedekleme/ustune-geri-yukleme/'.$kayit->kimlik.'/onayla', [], ['X-Erisim-Anahtari' => $anahtar])->assertAccepted()->json('data.istek_kimligi');
        $istekDosyasi = config('yedekleme.istek_klasoru').'/bekleyen/'.$istek.'.json';
        $this->assertStringNotContainsString($anahtar, (string) file_get_contents($istekDosyasi));

        // Ömrü geçti: çalıştırıcı uygulamaz, uygulama bakım modunda kalır
        $this->travel(ErisimAnahtari::KANIT_OMRU_SN + 1)->seconds();
        app(IstekCalistiricisi::class)->tur();

        $this->assertSame(GeriYuklemeHatasi::KARAR_SURESI_DOLDU, app(IstekKutusu::class)->durum($istek)['kod'] ?? null);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));

        // Taze kararla onaylanır
        $yeni = (string) $this->postJson('/api/v1/yedekleme/ustune-geri-yukleme/'.$kayit->kimlik.'/onayla', [], ['X-Erisim-Anahtari' => $anahtar])->assertAccepted()->json('data.istek_kimligi');
        app(IstekCalistiricisi::class)->tur();
        $this->assertSame('tamam', app(IstekKutusu::class)->durum($yeni)['durum'] ?? null);
        $this->assertSame(UstuneGeriYuklemeAdimi::Onaylandi->value, $this->durumOku()?->adim);

        // Hiçbir dosyada (istek, sonuç, işlem durumu, denetim, ayar kopyası) ve günlükte anahtar yok
        foreach (File::allFiles($this->yedekKoku) as $dosya) {
            $this->assertStringNotContainsString($anahtar, (string) file_get_contents($dosya->getPathname()), $dosya->getPathname());
        }
        $this->assertStringNotContainsString($anahtar, is_file($gunluk) ? (string) file_get_contents($gunluk, offset: $gunlukOnce) : '');
        $this->assertStringNotContainsString($anahtar, (string) json_encode(DB::table('yedek_islem_kayitlari')->get()));
    }

    public function test_ayni_karar_kaniti_ikinci_kez_uygulanmaz(): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, anahtar: $anahtar, isteyen: $this->yonetici->id);
        // Farkı olan grup var, seçim yok: ilk istek kanıtı tüketir ama onay reddedilir
        app(OnayBilgisi::class)->bilgiYaz($kayit->kimlik, ['ayar_gruplari' => [['grup' => 'mail', 'farkli' => true]]]);
        $son = CarbonImmutable::now()->getTimestamp() + 120;
        $nonce = ErisimAnahtari::nonce();

        $ilk = $this->kararIstegiYaz('onayla', $kayit->kimlik, $anahtar, $this->yonetici->id, son: $son, nonce: $nonce);
        app(IstekCalistiricisi::class)->tur();
        $tekrar = $this->kararIstegiYaz('onayla', $kayit->kimlik, $anahtar, $this->yonetici->id, son: $son, nonce: $nonce);
        app(IstekCalistiricisi::class)->tur();

        $this->assertSame(GeriYuklemeHatasi::AYAR_SECIMI_EKSIK, app(IstekKutusu::class)->durum($ilk)['kod'] ?? null);
        $this->assertSame(GeriYuklemeHatasi::KARAR_KANITI_KULLANILDI, app(IstekKutusu::class)->durum($tekrar)['kod'] ?? null);
        $this->assertSame([$nonce => $son], $this->durumOku()?->kullanilanKanitlar);
    }

    /**
     * BY-030-R2: liste kapasitesinde süresi geçmemiş tüketilmiş kanıt
     * unutulmaz; yeni karar açık kodla reddedilir. Süresi geçenler atılır.
     */
    public function test_kanit_listesi_doluyken_suresi_gecmemis_kanit_dusmez_yeni_karar_reddedilir_suresi_gecenler_atilir(): void
    {
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, isteyen: $this->yonetici->id);
        $ustune = app(UstuneGeriYukleyici::class);
        $kilit = $this->kilitAl();
        $simdi = CarbonImmutable::now()->getTimestamp();
        // En erken biten kanıt: eskiden kapasite dolunca ilk o düşüyordu
        $ilk = ErisimAnahtari::nonce();
        $ustune->kanitTuket($kilit, $kayit->kimlik, $ilk, $simdi + 100);
        for ($i = 1; $i < IslemKaydi::EN_COK_KANIT; $i++) {
            $ustune->kanitTuket($kilit, $kayit->kimlik, ErisimAnahtari::nonce(), $simdi + 200);
        }

        foreach ([[ErisimAnahtari::nonce(), GeriYuklemeHatasi::KARAR_KAPASITESI_DOLU], [$ilk, GeriYuklemeHatasi::KARAR_KANITI_KULLANILDI]] as [$nonce, $kod]) {
            try {
                $ustune->kanitTuket($kilit, $kayit->kimlik, $nonce, $simdi + 250);
                $this->fail("Karar kabul edilmemeliydi ({$kod}).");
            } catch (GeriYuklemeHatasi $hata) {
                $this->assertSame($kod, $hata->kod);
            }
        }
        $this->assertCount(IslemKaydi::EN_COK_KANIT, $this->durumOku()?->kullanilanKanitlar ?? []);
        $this->assertArrayHasKey($ilk, $this->durumOku()?->kullanilanKanitlar ?? []);

        // İlk kanıtın süresi geçti: atılır, yeni karara yer açılır
        $this->travel(101)->seconds();
        $yeni = ErisimAnahtari::nonce();
        $ustune->kanitTuket($kilit, $kayit->kimlik, $yeni, $simdi + 400);

        $kanitlar = $this->durumOku()?->kullanilanKanitlar ?? [];
        $this->assertArrayNotHasKey($ilk, $kanitlar);
        $this->assertArrayHasKey($yeni, $kanitlar);
        $this->assertCount(IslemKaydi::EN_COK_KANIT, $kanitlar);
    }

    // -------------------------------------------------- ayar seçimi (BY-028-R2)

    public function test_farkli_ayar_grubunda_secimsiz_onay_reddedilir_secilen_degerler_acilista_gecerli(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'NAS', 'baglanti' => ['klasor' => '/yeni'], 'gizli_bilgiler' => ['parola' => 'hedef-gizli']]);
        $yedek = $this->ustuneYedekAl();
        // Geri yüklenen veritabanı yedeğin anındadır: mail ve hedef eski hâlinde
        $this->yerDegistirmeSonrasi = function () use ($hedef): void {
            MailAyari::query()->update(['sunucu' => 'eski.ornek.test']);
            $hedef->forceFill(['baglanti' => ['klasor' => '/eski']])->save();
        };
        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);
        $this->assertNull($this->kancaHatasi);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $kayit->adim);
        $this->assertSame([
            ['grup' => 'mail', 'farkli' => true], ['grup' => 'yedek_ayarlari', 'farkli' => false],
            ['grup' => 'planlar', 'farkli' => false], ['grup' => 'hedefler', 'farkli' => true],
        ], app(OnayBilgisi::class)->bilgiOku($kayit->kimlik)['ayar_gruplari'] ?? null);

        // Seçimsiz ya da eksik seçimle onay yok; hiçbir şey değişmez
        foreach ([[], ['mail' => 'guncel']] as $eksik) {
            try {
                app(UstuneGeriYukleyici::class)->onayla($this->kilitAl(), $kayit->kimlik, $eksik);
                $this->fail('Seçimi eksik onay kabul edildi.');
            } catch (GeriYuklemeHatasi $hata) {
                $this->assertSame(GeriYuklemeHatasi::AYAR_SECIMI_EKSIK, $hata->kod);
            } finally {
                $this->tutulanKilit?->birak();
            }
            $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
            $this->assertTrue($this->bakimdaBuIslemle($kayit->kimlik));
            $this->assertSame('eski.ornek.test', MailAyari::query()->value('sunucu'));
        }

        $son = app(UstuneGeriYukleyici::class)->onayla($this->kilitAl(), $kayit->kimlik, ['mail' => 'guncel', 'hedefler' => 'geri_yuklenen']);

        $this->assertSame(UstuneGeriYuklemeAdimi::Onaylandi->value, $son->adim);
        $this->assertSame(['mail' => 'guncel', 'hedefler' => 'geri_yuklenen'], $son->ayarSecimleri);
        // Açılışta geçerli olanlar: mail güncel (parolası dahil), hedef geri yüklenen
        $mail = MailAyari::query()->firstOrFail();
        $this->assertSame('smtp.ornek.test', $mail->sunucu);
        $this->assertSame('smtp-gizli', $mail->sifre);
        $this->assertSame(['klasor' => '/eski'], $hedef->refresh()->baglanti);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertSame(1, DB::table('yedek_islem_kayitlari')->where('islem', 'geri_yukle_ustune_ayar_secimi')->where('sonuc', 'basarili')->count());
    }

    public function test_guncel_hedefler_yedekten_sonra_eklenen_hedefi_sirsiz_ve_pasif_acar_sir_dosyaya_yazilmaz(): void
    {
        $eski = YedekHedefi::factory()->create(['ad' => 'NAS', 'aktif' => true, 'gizli_bilgiler' => ['parola' => 'eski-hedef-gizli']]);
        $yeni = YedekHedefi::factory()->create(['ad' => 'Drive', 'aktif' => true, 'gizli_bilgiler' => ['parola' => 'yeni-hedef-gizli']]);
        $yedek = $this->ustuneYedekAl();
        // Yedeğin anında yalnız eski hedef vardı
        $this->yerDegistirmeSonrasi = fn () => DB::table('yedek_hedefleri')->where('id', $yeni->id)->delete();
        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);
        $this->assertNull($this->kancaHatasi);

        // Hedef sırları dosya kopyasına hiç yazılmadı
        $kopya = (string) file_get_contents((string) config('yedekleme.ayar_kopyasi'));
        $this->assertStringNotContainsString('yeni-hedef-gizli', $kopya);
        $this->assertStringNotContainsString('eski-hedef-gizli', $kopya);

        $son = app(UstuneGeriYukleyici::class)->onayla($this->kilitAl(), $kayit->kimlik, ['hedefler' => 'guncel']);

        $this->assertSame(UstuneGeriYuklemeAdimi::Onaylandi->value, $son->adim);
        $acilan = YedekHedefi::query()->findOrFail($yeni->id);
        $this->assertSame('Drive', $acilan->ad);
        $this->assertFalse($acilan->aktif);
        $this->assertNull($acilan->gizli_bilgiler);
        // Geri yüklenen veritabanında olan hedefin sırları olduğu gibi
        $this->assertSame(['parola' => 'eski-hedef-gizli'], $eski->refresh()->gizli_bilgiler);
        $ayrinti = json_decode((string) DB::table('yedek_islem_kayitlari')->where('islem', 'geri_yukle_ustune_ayar_secimi')->value('ayrinti'), true);
        $this->assertStringContainsString('"Drive" yedekten sonra eklenmiş', implode(' ', $ayrinti['uyarilar'] ?? []));
    }

    /** BY-028-R3: "güncel" hedef uzlaştırması normal güncellemenin konum ve sır kurallarını uygular */
    public function test_guncel_hedeflerde_yeri_ya_da_adresi_degisen_hedef_eski_sirla_etkinlesmez_kopyalar_eski_yerdeki_hedefte_kalir(): void
    {
        // Bugün: disk hedefi /yeni'de; FTP hedefi yeni sunucuda yeni parolayla
        $disk = YedekHedefi::factory()->create(['ad' => 'Disk', 'aktif' => true, 'baglanti' => ['klasor' => '/yeni']]);
        $ftp = YedekHedefi::factory()->ftp()->create(['ad' => 'FTP', 'aktif' => true, 'baglanti' => ['sunucu' => 'yeni.ornek.test', 'port' => 21, 'kullanici_adi' => 'yedek', 'klasor' => '/emor', 'pasif' => true], 'gizli_bilgiler' => ['sifre' => 'yeni-parola']]);
        $yedek = $this->ustuneYedekAl();
        // Geri yüklenen veritabanı (yedeğin anı): disk /eski'deydi ve orada kopyası vardı; FTP eski sunucuda eski parolayla
        $this->yerDegistirmeSonrasi = function () use ($disk, $ftp, $yedek): void {
            $disk->forceFill(['baglanti' => ['klasor' => '/eski']])->save();
            YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $disk->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => $yedek->dosya_adi, 'sifreli' => false]);
            $ftp->forceFill(['baglanti' => [...$ftp->baglanti, 'sunucu' => 'eski.ornek.test'], 'gizli_bilgiler' => ['sifre' => 'eski-parola']])->save();
        };
        [$kayit] = $this->ustuneBaslat($yedek, $this->yonetici->id);
        $this->assertNull($this->kancaHatasi);

        $son = app(UstuneGeriYukleyici::class)->onayla($this->kilitAl(), $kayit->kimlik, ['hedefler' => 'guncel']);

        $this->assertSame(UstuneGeriYuklemeAdimi::Onaylandi->value, $son->adim);
        // FTP: bugünkü adres yazıldı ama eski parola o adrese gönderilemez — sırsız ve pasif
        $ftp->refresh();
        $this->assertSame('yeni.ornek.test', $ftp->baglanti['sunucu']);
        $this->assertNull($ftp->gizli_bilgiler);
        $this->assertFalse($ftp->aktif);
        // Disk: kopya kaydı eski yeri gösteren hedefte; hedef o yerde ama pasif
        $disk->refresh();
        $this->assertSame('/eski', $disk->baglanti['klasor']);
        $this->assertFalse($disk->aktif);
        $this->assertSame([$disk->id], YedekKopyasi::query()->where('yedek_id', $yedek->id)->pluck('yedek_hedefi_id')->map(fn (mixed $id): int => (int) $id)->all());
        $uyarilar = implode(' ', json_decode((string) DB::table('yedek_islem_kayitlari')->where('islem', 'geri_yukle_ustune_ayar_secimi')->value('ayrinti'), true)['uyarilar'] ?? []);
        $this->assertStringContainsString('"Disk": kopyaların durduğu yer (klasor)', $uyarilar);
        $this->assertStringContainsString('"FTP" bağlantı adresi yedekten sonra değişmiş', $uyarilar);
    }

    public function test_onay_bilgisi_ortam_kullanici_rol_ayar_ve_kaybolacak_yukleme_farkini_verir(): void
    {
        $bilgi = app(OnayBilgisi::class);
        DB::table('sql_baglantilari')->insert(['ortam' => 'canli', 'sunucu' => 's', 'veritabani' => 'v', 'kullanici_adi' => 'k', 'sifre' => 'x', 'aktif' => true, 'created_at' => now(), 'updated_at' => now()]);
        $rol = DB::table('roller')->insertGetId(['ad' => 'Muhasebe', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('rol_izinleri')->insert([['rol_id' => $rol, 'izin' => 'efatura.goruntule']]);
        $ayse = User::factory()->create(['kullanici_adi' => 'ayse', 'aktif_mi' => false]);
        DB::table('kesinti_yuklemeleri')->insert([
            ['icmal' => 'isgucu', 'kaynak' => 'excel', 'satir_sayisi' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['icmal' => 'isgucu', 'kaynak' => 'excel', 'satir_sayisi' => 4, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $onceki = json_decode((string) json_encode($bilgi->ozet()), true);
        // Bugünkü ayarların dosya kopyası: "güncel" ayar seçiminin kaynağı (BY-028-R2)
        app(AyarKopyasi::class)->yaz();

        // "Geri yüklenen": yedeğin anı — test ortamı, aktif ERP, Ayşe aktif,
        // rolün izni fazla, yüklemeler yok, plan farklı
        DB::table('sql_baglantilari')->update(['ortam' => 'test']);
        $ayse->update(['aktif_mi' => true]);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol, 'izin' => 'kullanicilar.guncelle']);
        DB::table('kesinti_yuklemeleri')->delete();
        YedekPlani::factory()->create(['ad' => 'Eski plan']);

        $fark = $bilgi->karsilastir($onceki, $bilgi->ozet());

        $this->assertSame(['onceki' => 'canli', 'geri_yuklenen' => 'test', 'farkli' => true], $fark['aktif_erp_ortami']);
        $this->assertSame(2, $fark['kaybolacak_yukleme_sayisi']);
        $this->assertSame([['kullanici_adi' => 'ayse', 'degisiklik' => 'degisti', 'alanlar' => ['aktif']]], array_map(fn (array $k): array => array_intersect_key($k, array_flip(['kullanici_adi', 'degisiklik', 'alanlar'])), $fark['kullanici_farklari']));
        $this->assertSame([['rol' => 'Muhasebe', 'degisiklik' => 'degisti', 'geri_gelen_izinler' => ['kullanicilar.guncelle'], 'kaybolan_izinler' => []]], $fark['rol_farklari']);
        $this->assertSame([['bolum' => 'planlar', 'ad' => 'Eski plan', 'degisiklik' => 'yalniz_geri_yuklenende', 'alanlar' => []]], $fark['ayar_farklari']);
        $this->assertSame([
            ['grup' => 'mail', 'farkli' => false], ['grup' => 'yedek_ayarlari', 'farkli' => false],
            ['grup' => 'planlar', 'farkli' => true], ['grup' => 'hedefler', 'farkli' => false],
        ], $fark['ayar_gruplari']);
        $this->assertFalse($fark['ayar_kopyasi_yok']);
    }

    // ------------------------------------------------------- korumalar

    public function test_acik_geri_yuklemede_zamanlayici_hicbir_gorevi_baslatmaz_e_fatura_ve_alarm_dahil(): void
    {
        $gorevler = $this->zamanlayiciCalisirkenGorevler();
        $komutlar = implode(' | ', array_map(fn (ZamanlanmisGorev $g): string => (string) $g->command, $gorevler));
        $this->assertStringContainsString('efatura:senkron', $komutlar);
        $this->assertStringContainsString('efatura:alarmlar', $komutlar);

        // Kapalıyken yalnız kendi filtresi olmayan görevler geçer (yedek görevlerinin
        // filtresi geçiş kipidir: servis kipinde servis döngüsü yürütür — BY-009-R1)
        foreach ($gorevler as $gorev) {
            $this->assertTrue($gorev->filtersPass($this->app) || preg_match('/yedek:(calistir|zamanla|temizle)/', (string) $gorev->command) === 1, (string) $gorev->command);
        }

        $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true);
        foreach ($gorevler as $gorev) {
            $this->assertFalse($gorev->filtersPass($this->app), 'Açık geri yüklemede çalışmamalı: '.$gorev->command);
        }
    }

    public function test_acik_geri_yuklemede_kuyruk_iscisi_is_almaz(): void
    {
        config(['queue.default' => 'database']);
        $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true);
        dispatch(fn () => DB::table('kesinti_yuklemeleri')->insert(['icmal' => 'is', 'kaynak' => 'kuyruk', 'satir_sayisi' => 1, 'created_at' => now(), 'updated_at' => now()]));

        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('kesinti_yuklemeleri')->count());

        // Onaydan sonra iş alınır
        $this->durumYaz(UstuneGeriYuklemeAdimi::Onaylandi, false);

        Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0]);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('kesinti_yuklemeleri')->count());
    }

    public function test_acik_geri_yuklemede_planli_yedek_baslamaz(): void
    {
        $hedef = YedekHedefi::factory()->create(['baglanti' => ['klasor' => '']]);
        $plan = YedekPlani::factory()->create(['siklik_turu' => SiklikTuru::Gunluk, 'saat' => '02:00']);
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subMinute()])->save();
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => 3]);
        $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true);
        $yedekSayisi = Yedek::query()->count();

        $this->artisan('yedek:zamanla')->expectsOutputToContain('Açık bir geri yükleme var')->assertSuccessful();

        Process::assertNotRan(fn (PendingProcess $p): bool => str_ends_with((string) ((array) $p->command)[0], 'pg_dump') && ! in_array('--version', (array) $p->command, true));
        $this->assertSame($yedekSayisi, Yedek::query()->count());
    }

    // -------------------------------------------------- acil durum komutu

    public function test_acil_durum_komutu_veritabani_erisilemezken_onaylar(): void
    {
        $this->onayBekleyen();

        $this->veritabaniErisilemezken(function (): void {
            $this->artisan('yedek:acil', ['islem' => 'onayla', '--force' => true])->assertSuccessful();
        });

        $this->assertSame(UstuneGeriYuklemeAdimi::Onaylandi->value, $this->durumOku()?->adim);
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertContains('systemctl start emor-queue', $this->komutlar);
    }

    public function test_acil_durum_komutu_kilit_baskasindayken_beklemeden_durur(): void
    {
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true);
        $this->bakimDosyasiYaz($kayit->kimlik);
        $this->kilitAl();
        $baslangic = hrtime(true);

        $this->artisan('yedek:acil', ['islem' => 'geri-don', '--force' => true])
            ->expectsOutputToContain('Yedekleme kilidi başka bir süreçte')
            ->assertFailed();

        $this->assertLessThan(2_000_000_000, hrtime(true) - $baslangic);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        $this->assertSame([], $this->komutlar);
    }

    public function test_acil_durum_komutu_anahtarin_suresi_dolsa_da_geri_doner(): void
    {
        $yedek = $this->ustuneYedekAl();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, bitis: CarbonImmutable::now('UTC')->subHour(), yedekKimligi: $yedek->kimlik, isteyen: $this->yonetici->id);
        $this->bakimDosyasiYaz($kayit->kimlik);
        $this->veritabanlari = ['erp', 'postgres', (string) $kayit->oncekiVeritabani];

        $this->artisan('yedek:acil', ['islem' => 'durum'])->expectsOutputToContain('onay_bekliyor')->assertSuccessful();
        $this->artisan('yedek:acil', ['islem' => 'geri-don', '--force' => true])->assertSuccessful();

        $this->assertSame(UstuneGeriYuklemeAdimi::GeriDonuldu->value, $this->durumOku()?->adim);
        $this->assertEqualsCanonicalizing(['erp', 'postgres', (string) $kayit->hedefVeritabani], $this->veritabanlari);
    }

    /**
     * `schedule:run` başlarken korumanın eklendiği görevler (gerçek olay ile).
     *
     * @return list<ZamanlanmisGorev>
     */
    private function zamanlayiciCalisirkenGorevler(): array
    {
        event(new CommandStarting('schedule:run', new ArrayInput([]), new NullOutput));

        return array_values(app(Schedule::class)->events());
    }
}
