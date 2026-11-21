<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\YedeklemeMaili;
use App\Models\GeriYukleme;
use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\BakimModu;
use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\Istekler\IstekCalistiricisi;
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
        [$kayit] = $this->onayBekleyen();

        $kilit = $this->kilitAl();
        $son = app(UstuneGeriYukleyici::class)->geriDon($kilit, $kayit->kimlik);

        $this->assertSame(UstuneGeriYuklemeAdimi::GeriDonuldu->value, $son->adim);
        $this->assertFalse($son->acik);
        $this->assertSame(['kapat erp', "yer erp>{$kayit->hedefVeritabani} {$kayit->oncekiVeritabani}>erp", 'ac erp', 'systemctl start emor-queue'], $this->komutlar);
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

        $onay = app(IstekKutusu::class)->yaz('onayla', ['islem_kimligi' => $baslat, 'erisim_anahtari' => $anahtar], $this->yonetici->id);
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
            'yanlış anahtar' => ['yanlis', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ],
            'süresi dolmuş anahtar' => ['suresi', GeriYuklemeHatasi::ERISIM_ANAHTARI_SURESI_DOLDU],
            'başka işlemin kimliği' => ['islem', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ],
            'isteyenden başka kullanıcı' => ['kullanici', GeriYuklemeHatasi::ERISIM_ANAHTARI_GECERSIZ],
        ];
    }

    #[DataProvider('anahtarReddi')]
    public function test_calistirici_onay_isteginin_anahtarini_islem_durumundan_dogrular(string $durum, string $kod): void
    {
        $anahtar = ErisimAnahtari::uret();
        $kayit = $this->durumYaz(UstuneGeriYuklemeAdimi::OnayBekliyor, true, anahtar: $anahtar, bitis: $durum === 'suresi' ? CarbonImmutable::now('UTC')->subMinute() : null, isteyen: $this->yonetici->id);
        $diger = User::factory()->yonetici()->create();

        $istek = app(IstekKutusu::class)->yaz('onayla', [
            'islem_kimligi' => $durum === 'islem' ? (string) Str::uuid() : $kayit->kimlik,
            'erisim_anahtari' => $durum === 'yanlis' ? ErisimAnahtari::uret() : $anahtar,
        ], $durum === 'kullanici' ? $diger->id : $this->yonetici->id);
        app(IstekCalistiricisi::class)->tur();

        $this->assertSame($kod, app(IstekKutusu::class)->durum($istek)['kod'] ?? null);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        $this->assertSame([], $this->komutlar);
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
    }

    // ------------------------------------------------------- korumalar

    public function test_acik_geri_yuklemede_zamanlayici_hicbir_gorevi_baslatmaz_e_fatura_ve_alarm_dahil(): void
    {
        $gorevler = $this->zamanlayiciCalisirkenGorevler();
        $komutlar = implode(' | ', array_map(fn (ZamanlanmisGorev $g): string => (string) $g->command, $gorevler));
        $this->assertStringContainsString('efatura:senkron', $komutlar);
        $this->assertStringContainsString('efatura:alarmlar', $komutlar);

        // Kapalıyken yalnız kendi filtresi olmayan görevler geçer
        foreach ($gorevler as $gorev) {
            $this->assertTrue($gorev->filtersPass($this->app) || str_contains((string) $gorev->command, 'yedek:calistir'), (string) $gorev->command);
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
