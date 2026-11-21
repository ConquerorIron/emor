<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeriYukleme;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\DizinKurucu;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\Istekler\Istek;
use App\Services\Yedekleme\Istekler\IstekReddi;
use App\Services\Yedekleme\Istekler\SilIsleyicisi;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\OlayTuru;
use App\Services\Yedekleme\SahipsizYedekler;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKopyalayici;
use App\Services\Yedekleme\YedekOlayi;
use App\Services\Yedekleme\YerelDosyaSilici;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-011: klasör tarama, dizini yeniden kurma (README §5.2, §7.3, §4.5).
 */
final class DizinKurucuTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        $this->sahtePgAraclari();
        Sleep::fake();
        BellekHedefi::sifirla();
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function gercekYedek(): Yedek
    {
        $yedek = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Elle, 'Yıl sonu');
        $this->tutulanKilit?->birak();

        return $yedek;
    }

    private function dizinleriBosalt(): void
    {
        DB::table('yedek_kopya_planlari')->delete();
        YedekKopyasi::query()->delete();
        Yedek::query()->delete();
    }

    private function tara(): array
    {
        return app(DizinKurucu::class)->tara();
    }

    public function test_tablolar_bosaltilip_tarama_calisinca_liste_eski_haline_doner_sabitleme_durur(): void
    {
        $yedek = $this->gercekYedek();
        $yedek->update(['sabit' => true]);
        app(OlayGunlugu::class)->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Sabitlendi));
        $this->dizinleriBosalt();

        $this->tara();

        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $this->assertSame($yedek->dosya_adi, $kurulan->dosya_adi);
        $this->assertTrue($kurulan->sabit);
        $this->assertTrue($kurulan->imzali);
        $this->assertSame('Yıl sonu', $kurulan->aciklama);
        $this->assertSame(YedekDurumu::Tamam, $kurulan->durum);
    }

    public function test_imzasiz_dosya_guvenilmeyen_olarak_listelenir_geri_yuklenemez(): void
    {
        file_put_contents(config('yedekleme.klasor').'/erp_predeploy_2026-08-05_101010.dump', 'PGDMP-eski');

        $sonuc = $this->tara();

        $yedek = Yedek::query()->sole();
        $this->assertSame(1, $sonuc['guvenilmeyen']);
        $this->assertFalse($yedek->imzali);
        $this->assertFalse($yedek->geriYuklenebilirMi());
        $this->assertSame(YedekKaynagi::YayinOncesi, $yedek->kaynak);
    }

    public function test_tarama_iki_kez_calisinca_kayit_cogalmaz(): void
    {
        $this->gercekYedek();
        file_put_contents(config('yedekleme.klasor').'/erp_predeploy_2026-08-05_101010.dump', 'PGDMP-eski');

        $this->tara();
        $this->tara();

        $this->assertSame(2, Yedek::query()->count());
    }

    public function test_tek_bozuk_manifest_taramanin_tamamini_durdurmaz(): void
    {
        $yedek = $this->gercekYedek();
        file_put_contents(config('yedekleme.klasor').'/erp_bozuk.json', '{"manifest": {');
        $this->dizinleriBosalt();

        $sonuc = $this->tara();

        $this->assertSame(1, $sonuc['bozuk']);
        $this->assertTrue(Yedek::query()->where('kimlik', $yedek->kimlik)->exists());
    }

    /** Kayıt silinip açılmaz: geri yükleme geçmişi (RESTRICT) olan yedekte tarama hatasız (Fable F19). */
    public function test_geri_yukleme_gecmisi_olan_yedekte_tarama_kaydi_kimligiyle_gunceller(): void
    {
        $yedek = $this->gercekYedek();
        GeriYukleme::query()->create([
            'kimlik' => (string) Str::uuid(), 'yedek_id' => $yedek->id, 'tur' => GeriYuklemeTuru::Kopya,
            'hedef_veritabani' => 'erp_geri_20261121_101500', 'durum' => GeriYuklemeDurumu::Tamam,
        ]);

        $this->tara();

        $this->assertSame($yedek->id, Yedek::query()->where('kimlik', $yedek->kimlik)->sole()->id);
    }

    public function test_diskte_olmayan_kayit_silinmez_dosya_yok_olur(): void
    {
        $yedek = $this->gercekYedek();
        unlink(config('yedekleme.klasor').'/'.$yedek->dosya_adi);
        unlink(config('yedekleme.klasor').'/'.str_replace('.dump', '.json', $yedek->dosya_adi));

        $sonuc = $this->tara();

        $yedek->refresh();
        $this->assertSame(1, $sonuc['dosyasi_yok']);
        $this->assertNotNull($yedek->yerel_silinme_zamani);
        $this->assertSame(YedekDurumu::Silindi, $yedek->durum);
    }

    /** F7: yerel dosyası silinmiş, uzakta doğrulanmış kopyası olan yedek dizin yeniden kurulunca listede ve `silindi` değil. */
    public function test_yerel_dosyasi_silinmis_uzak_kopyali_yedek_yeniden_kurulunca_bulunur(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        $this->dizinleriBosalt();

        $this->tara();

        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $this->assertSame(YedekDurumu::Tamam, $kurulan->durum);
        $this->assertNotNull($kurulan->yerel_silinme_zamani);
        $kopya = YedekKopyasi::query()->where('yedek_id', $kurulan->id)->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame($hedef->id, $kopya->yedek_hedefi_id);
    }

    public function test_dosya_kimligi_ayni_dosya_icin_hep_ayni_uuid(): void
    {
        $kimlik = DizinKurucu::dosyaKimligi('erp_predeploy_2026-08-05_101010.dump');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $kimlik);
        $this->assertSame($kimlik, DizinKurucu::dosyaKimligi('erp_predeploy_2026-08-05_101010.dump'));
    }

    /** BY-006-R1: satır ortasında kesilen yazımdan sonra eklenen olay yarım parçaya bitişip kaybolmaz. */
    public function test_yarim_satirdan_sonra_yazilan_olay_yeniden_taramada_uygulanir(): void
    {
        $yedek = $this->gercekYedek();
        $gunluk = app(OlayGunlugu::class);
        file_put_contents($gunluk->yol(), '{"olay":{"zaman":"2026-11-21T10:00:00.000000+00:00","yedek_kim', FILE_APPEND);
        $gunluk->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Sabitlendi));
        $this->dizinleriBosalt();

        $sonuc = $this->tara();

        $this->assertTrue(Yedek::query()->where('kimlik', $yedek->kimlik)->sole()->sabit);
        $this->assertSame(1, $sonuc['gecersiz_olay']);
    }

    /** BY-011-R1 (D6): erişilemeyen yedek klasörü boş klasör sayılmaz; dizin değişmeden tarama durur. */
    #[DataProvider('erisilemeyenKlasorler')]
    public function test_yedek_klasoru_erisilemezken_tarama_kayitlara_ve_kopyalara_dokunmaz(Closure $erisimiKes): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $klasor = config('yedekleme.klasor');
        $erisimiKes($this, $klasor);
        $hata = null;

        try {
            $this->tara();
        } catch (RuntimeException $e) {
            $hata = $e;
        } finally {
            @chmod($klasor, 0750);
        }

        $this->assertInstanceOf(RuntimeException::class, $hata);
        $this->assertStringContainsString('dizin değiştirilmedi', $hata->getMessage());
        $yedek->refresh();
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertNull($yedek->yerel_silinme_zamani);
        $this->assertSame(KopyaDurumu::Tamam, YedekKopyasi::query()->where('yedek_id', $yedek->id)->sole()->durum);
    }

    /** @return array<string, array{Closure}> */
    public static function erisilemeyenKlasorler(): array
    {
        return [
            'bağlanmamış (yok)' => [function (self $test, string $klasor): void {
                rename($klasor, $klasor.'-baglanmamis');
            }],
            'okuma izni yok' => [function (self $test, string $klasor): void {
                if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                    $test->markTestSkipped('root izin denetimine takılmaz (T11).');
                }
                chmod($klasor, 0300);
            }],
            // BY-011-R3: disk ayrıldı; bağlama noktası alttaki boş, okunabilir, işaretsiz klasör
            'diski ayrılmış boş bağlama noktası' => [function (self $test, string $klasor): void {
                rename($klasor, $klasor.'-disk');
                mkdir($klasor, 0750);
            }],
        ];
    }

    /**
     * BY-011-R1, R3: okunabilen, depo işareti duran ama gerçekten boş klasör
     * olağan taramadır — kayıt "dosya yok" olur.
     */
    public function test_erisilebilen_bos_klasorde_kayit_dosya_yok_olur(): void
    {
        $yedek = $this->gercekYedek();
        foreach ($this->klasordekiDosyalar() as $ad) {
            unlink(config('yedekleme.klasor').'/'.$ad);
        }

        $sonuc = $this->tara();

        $this->assertSame(1, $sonuc['dosyasi_yok']);
        $this->assertNotNull($yedek->refresh()->yerel_silinme_zamani);
    }

    /** BY-007-R1: kilidi tutan tarama, dump sırasında ölen süreçten kalan `calisiyor` kaydı sonuçlandırır. */
    public function test_kilitli_tarama_sahipsiz_calisan_yedegi_yarida_kaldi_yapar(): void
    {
        $this->gercekYedek();
        $sahipsiz = Yedek::factory()->create(['durum' => YedekDurumu::Calisiyor, 'dosya_adi' => 'erp_elle_2026-11-21_101010+0300.dump']);
        $gecici = config('yedekleme.klasor').'/.erp_elle_2026-11-21_101010+0300.dump.a1b2c3d4e5f6.tmp';
        file_put_contents($gecici, 'PGDMP-yarim');

        $sonuc = app(DizinKurucu::class)->tara($this->kilitAl());

        $this->assertSame(1, $sonuc['sahipsiz']);
        $this->assertSame([YedekDurumu::Basarisiz, SahipsizYedekler::YARIDA_KALDI], [$sahipsiz->refresh()->durum, $sahipsiz->hata_kodu]);
        $this->assertFileDoesNotExist($gecici);
    }

    /** BY-023-R1: silme yetkisi reddedilen (mantıksal silinen) kopya boş katalogdan yeniden kurulunca geri gelmez. */
    public function test_silme_yetkisi_reddedilen_kopya_yeniden_kurulunca_kullanilabilir_gorunmez(): void
    {
        YedekAyari::query()->create(['enaz_adet' => 1]);
        $hedef = YedekHedefi::factory()->create(['ad' => 'Kilitli S3', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        $plan = YedekPlani::factory()->create();
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => 1]);
        $eski = $this->planYedegi($hedef, $plan);
        $this->travel(1)->hours();
        $this->planYedegi($hedef, $plan);
        BellekHedefi::$hataAdimi = 'sil';
        BellekHedefi::$hataKodu = HedefHatasi::SILME_YETKISI_YOK;
        app(SaklamaPolitikasi::class)->uygula();
        $this->dizinleriBosalt();

        $this->tara();

        $kopya = YedekKopyasi::query()->whereHas('yedek', fn ($q) => $q->where('kimlik', $eski->kimlik))->sole();
        $this->assertSame([KopyaDurumu::Silindi, HedefHatasi::SILME_YETKISI_YOK], [$kopya->durum, $kopya->hata_kodu]);
    }

    /** BY-025-R3: elle silme, saklamayla aynı kayıt adımıyla olay yazar; yeniden kurulunca yedek ve kopya silinmiş kalır. */
    public function test_elle_silinen_yedek_ve_kopyasi_yeniden_kurulunca_silinmis_kalir(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $istek = new Istek((string) Str::uuid(), 'sil', ['yedek_kimligi' => $yedek->kimlik], null, CarbonImmutable::now(), 0);
        app(SilIsleyicisi::class)->isle($istek, $this->kilitAl());
        $this->tutulanKilit?->birak();
        $this->dizinleriBosalt();

        $this->tara();

        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $this->assertSame(YedekDurumu::Silindi, $kurulan->durum);
        $this->assertSame(KopyaDurumu::Silindi, YedekKopyasi::query()->where('yedek_id', $kurulan->id)->sole()->durum);
    }

    /** BY-011-R3 / G1: yedek diski bağlı değilken elle silme hiçbir kopyaya ve kataloğa dokunmaz. */
    public function test_yedek_diski_bagli_degilken_elle_silme_reddedilir_kopyalar_ve_katalog_aynen_kalir(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $uzak = BellekHedefi::$dosyalar;
        $klasor = config('yedekleme.klasor');
        // Disk ayrıldı: bağlama noktası alttaki boş, okunabilir, işaretsiz klasör
        rename($klasor, $klasor.'-disk');
        mkdir($klasor, 0750);

        try {
            $istek = new Istek((string) Str::uuid(), 'sil', ['yedek_kimligi' => $yedek->kimlik], null, CarbonImmutable::now(), 0);
            try {
                app(SilIsleyicisi::class)->isle($istek, $this->kilitAl());
                $this->fail('Depo doğrulanmadan silme reddedilmeliydi');
            } catch (IstekReddi $red) {
                $this->assertSame('YEDEK_SILME_DEPO_DOGRULANAMADI', $red->kod);
            } finally {
                $this->tutulanKilit?->birak();
            }
            $this->assertSame([], array_values(array_diff(scandir($klasor), ['.', '..'])), 'Bağlama noktasına hiçbir şey yazılmaz');
        } finally {
            array_map('unlink', glob($klasor.'/{,.}[!.]*', GLOB_BRACE) ?: []);
            rmdir($klasor);
            rename($klasor.'-disk', $klasor);
        }

        $yedek->refresh();
        $this->assertSame([YedekDurumu::Tamam, null], [$yedek->durum, $yedek->yerel_silinme_zamani]);
        $this->assertSame(KopyaDurumu::Tamam, $kopya->refresh()->durum);
        $this->assertSame($uzak, BellekHedefi::$dosyalar);
    }

    private function planYedegi(YedekHedefi $hedef, YedekPlani $plan): Yedek
    {
        $yedek = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Plan, null, null, [$plan->ad]);
        $this->tutulanKilit?->birak();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef, [$plan->id]);

        return $yedek;
    }
}
