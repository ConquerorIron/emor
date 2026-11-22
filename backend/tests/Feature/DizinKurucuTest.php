<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeriYukleme;
use App\Models\Rol;
use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\DizinKurucu;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeHatasi;
use App\Services\Yedekleme\GeriYuklemeKaynaklari;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\HedefKonumu;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\Istekler\Istek;
use App\Services\Yedekleme\Istekler\IstekReddi;
use App\Services\Yedekleme\Istekler\SilIsleyicisi;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KopyaSilmeKaydi;
use App\Services\Yedekleme\ManifestDeposu;
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

    /**
     * BY-028-R5: eski yedeğin anında kopyasız olan hedef sonra yer değiştirir
     * ve yeni yerde kopya alınır. Eski veritabanı geri yüklenince hedef eski
     * yerindedir: olay günlüğündeki yeni yer kopyası ona `tamam` bağlanmaz,
     * geri yükleme kaynağı sayılmaz, elle silmede o hedeften silinmez. Hedef
     * kopyanın yerine dönünce kopya yeniden `tamam` bağlanır.
     */
    public function test_yer_degistiren_hedefin_yeni_yerdeki_kopyasi_eski_yerine_donen_hedefe_tamam_baglanmaz_kaynak_ve_silme_disinda_kalir(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'eski-kova']]);
        // Kopyasız hedef yer değiştirir; yeni yerde kopya alınır
        $hedef->update(['baglanti' => ['kova' => 'yeni-kova']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $uzak = BellekHedefi::$dosyalar;
        // Eski veritabanı geri yüklendi: hedef eski yerinde, kopya kaydı yok
        $hedef->update(['baglanti' => ['kova' => 'eski-kova']]);
        $this->dizinleriBosalt();

        $this->tara();

        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $kopya = YedekKopyasi::query()->where('yedek_id', $kurulan->id)->sole();
        $this->assertSame([KopyaDurumu::KonumBelirsiz, HedefKonumu::DOGRULANAMADI], [$kopya->durum, $kopya->hata_kodu]);
        // Yerel dosya olmasaydı geri yüklenecek uzak kopya yok
        $kaynaklar = GeriYuklemeKaynaklari::sec(false, $kurulan->kopyalar);
        $this->assertSame([[], false], [$kaynaklar->uzaklar, $kaynaklar->sifreliVar]);

        // Elle silme o kopyayı hedefin bugünkü yerinden silmeye kalkmaz; sonuçta görünür
        $istek = new Istek((string) Str::uuid(), 'sil', ['yedek_kimligi' => $yedek->kimlik], null, CarbonImmutable::now(), 0);
        $sonuc = app(SilIsleyicisi::class)->isle($istek, $this->kilitAl());
        $this->tutulanKilit?->birak();
        $this->assertSame('YEDEK_KOPYA_SILINEMEDI', $sonuc->kod);
        $this->assertStringContainsString('Uzak: '.HedefKonumu::DOGRULANAMADI, (string) $sonuc->mesaj);
        $this->assertSame($uzak, BellekHedefi::$dosyalar);
        $this->assertSame(KopyaDurumu::KonumBelirsiz, $kopya->refresh()->durum);
    }

    /** BY-028-R5: konumu aynı kalan normal yolda kopya bağlanmaya devam eder; hedef kopyanın yerine dönünce yeniden `tamam` olur. */
    public function test_hedef_kopyanin_yerine_donunce_yeniden_kurulan_kopya_tamam_baglanir(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'yeni-kova']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $hedef->update(['baglanti' => ['kova' => 'eski-kova']]);
        $this->tara();
        $this->assertSame(KopyaDurumu::KonumBelirsiz, YedekKopyasi::query()->where('yedek_id', $yedek->id)->sole()->durum);

        $hedef->update(['baglanti' => ['kova' => 'yeni-kova']]);
        $this->tara();

        $kopya = YedekKopyasi::query()->where('yedek_id', $yedek->id)->sole();
        $this->assertSame([KopyaDurumu::Tamam, null], [$kopya->durum, $kopya->hata_kodu]);
        $this->assertSame(app(HedefKonumu::class)->iz($hedef->refresh()), $kopya->konum_izi);
    }

    /** BY-028-R5: saklamanın bağ kaldırma olayı da konum izini taşır; dizin yeniden kurulunca kopya `tamam` ve kalan bağıyla gelir. */
    public function test_saklama_bag_kaldirdiktan_sonra_yeniden_kurulan_kopya_tamam_kalir(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Ortak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        $kisa = YedekPlani::factory()->create();
        $kisa->hedefler()->attach($hedef, ['sakla_son_adet' => 1]);
        $uzun = YedekPlani::factory()->create();
        $uzun->hedefler()->attach($hedef, ['sakla_son_adet' => 10]);
        $eski = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Plan, null, null, [$kisa->ad, $uzun->ad]);
        $this->tutulanKilit?->birak();
        app(YedekKopyalayici::class)->kopyala($eski, $hedef, [$kisa->id, $uzun->id]);
        $this->travel(1)->hours();
        $yeni = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Plan, null, null, [$kisa->ad, $uzun->ad]);
        $this->tutulanKilit?->birak();
        app(YedekKopyalayici::class)->kopyala($yeni, $hedef, [$kisa->id, $uzun->id]);
        app(SaklamaPolitikasi::class)->uygula();
        $this->dizinleriBosalt();

        $this->tara();

        $kopya = YedekKopyasi::query()->whereHas('yedek', fn ($q) => $q->where('kimlik', $eski->kimlik))->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame([$uzun->id], $kopya->planlar()->pluck('yedek_planlari.id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /** BY-028-R5: konum izi olmayan (eski) kopya olayı yeniden kurulunca çözümlenmiş kopya sayılmaz. */
    public function test_konum_izi_olmayan_eski_kopya_olayi_yeniden_kurulunca_tamam_baglanmaz(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        app(OlayGunlugu::class)->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Kopya, [
            'hedef_id' => $hedef->id, 'hedef_ad' => $hedef->ad, 'durum' => 'tamam', 'uzak_kimlik' => $hedef->id.'/'.$yedek->dosya_adi,
            'sifreli' => false, 'sifreli_sha256' => null, 'plan_idleri' => [],
        ]));
        $this->dizinleriBosalt();

        $this->tara();

        $kopya = YedekKopyasi::query()->whereHas('yedek', fn ($q) => $q->where('kimlik', $yedek->kimlik))->sole();
        $this->assertSame([KopyaDurumu::KonumBelirsiz, null], [$kopya->durum, $kopya->konum_izi]);
    }

    /**
     * BY-028-R7 (F7): yerel dosyası silinmiş yedeğin tek uzak kopyasının yeri
     * doğrulanamazsa yedek `silindi` olmaz: varsayılan listede kopyanın konum
     * uyarısıyla görünür, geri yükleme kaynağı yoktur. Hedef kopyanın yerine
     * dönünce aynı yedek kopyasından geri yüklenebilir.
     */
    public function test_yerel_dosyasi_olmayan_yedegin_konumu_belirsiz_kopyasi_yedegi_listede_tutar_konum_dogrulaninca_kullanilir(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'yeni-kova']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        // Eski veritabanı geri yüklendi: hedef eski yerinde
        $hedef->update(['baglanti' => ['kova' => 'eski-kova']]);
        $this->dizinleriBosalt();

        $this->tara();

        $satir = $this->yedekListesi();
        $this->assertSame([$yedek->kimlik, 'tamam', false, false], [$satir['kimlik'], $satir['durum'], $satir['yerel_dosya_var'], $satir['webden_geri_yuklenebilir']]);
        $this->assertSame([['hedef_ad' => 'Uzak', 'durum' => 'konum_belirsiz', 'hata_kodu' => HedefKonumu::DOGRULANAMADI]], array_map(fn (array $k): array => array_intersect_key($k, array_flip(['hedef_ad', 'durum', 'hata_kodu'])), $satir['kopyalar']));

        $hedef->update(['baglanti' => ['kova' => 'yeni-kova']]);
        $this->tara();

        $satir = $this->yedekListesi();
        $this->assertSame([$yedek->kimlik, 'tamam', true, 'tamam'], [$satir['kimlik'], $satir['durum'], $satir['webden_geri_yuklenebilir'], $satir['kopyalar'][0]['durum']]);
    }

    /**
     * BY-028-R7: yeri doğrulanamayan kopyası kalan yedek elle silinince yerel
     * dosya gider ama yedek `silindi` olmaz ve "tamamen silindi" olayı yazılmaz:
     * sonuç kısmi (kopya silinemedi). Konum doğrulanınca aynı yedek kullanılabilir.
     */
    public function test_konumu_belirsiz_kopyasi_kalan_yedek_elle_silinince_silindi_olmaz_tam_silme_olayi_yazilmaz(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'yeni-kova']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $uzak = BellekHedefi::$dosyalar;
        $hedef->update(['baglanti' => ['kova' => 'eski-kova']]);
        $this->tara();

        $istek = new Istek((string) Str::uuid(), 'sil', ['yedek_kimligi' => $yedek->kimlik], null, CarbonImmutable::now(), 0);
        $sonuc = app(SilIsleyicisi::class)->isle($istek, $this->kilitAl());
        $this->tutulanKilit?->birak();

        $yedek->refresh();
        $this->assertSame(['YEDEK_KOPYA_SILINEMEDI', 'tamam'], [$sonuc->kod, $sonuc->ek['yedek_durumu']]);
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertNotNull($yedek->yerel_silinme_zamani);
        $this->assertFileDoesNotExist(config('yedekleme.klasor').'/'.$yedek->dosya_adi);
        $turler = array_map(fn (YedekOlayi $o): OlayTuru => $o->tur, array_values(array_filter(app(OlayGunlugu::class)->oku()['olaylar'], fn (YedekOlayi $o): bool => $o->yedekKimligi === $yedek->kimlik)));
        $this->assertContains(OlayTuru::YerelSilindi, $turler);
        $this->assertNotContains(OlayTuru::Silindi, $turler);
        $this->assertSame($uzak, BellekHedefi::$dosyalar);

        $hedef->update(['baglanti' => ['kova' => 'yeni-kova']]);
        $this->tara();

        $yedek->refresh();
        $this->assertSame([YedekDurumu::Tamam, KopyaDurumu::Tamam], [$yedek->durum, YedekKopyasi::query()->where('yedek_id', $yedek->id)->sole()->durum]);
        $this->assertNull(GeriYuklemeKaynaklari::engel($yedek->load('kopyalar')));
    }

    /** BY-028-R7 / G1: dosyası ve manifesti klasörden kaybolan yedek, yeri doğrulanamayan kopyası durdukça `silindi` olmaz. */
    public function test_dosyasi_ve_manifesti_kaybolan_yedek_konumu_belirsiz_kopyasiyla_silindi_olmaz(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'yeni-kova']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $hedef->update(['baglanti' => ['kova' => 'eski-kova']]);
        $this->tara();
        $dump = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        unlink($dump);
        unlink(ManifestDeposu::jsonYolu($dump));

        $this->tara();

        $yedek->refresh();
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertNotNull($yedek->yerel_silinme_zamani);
    }

    /** BY-028-R7 / G1: yerel dosyası olmayan yedeğin son `tamam` kopyası silinince yeri doğrulanamayan kopya yedeği `silindi` yaptırmaz. */
    public function test_son_tamam_kopya_silinince_konumu_belirsiz_kopya_yedegi_silindi_yaptirmaz(): void
    {
        $yedek = $this->gercekYedek();
        $silinecek = YedekHedefi::factory()->create(['ad' => 'A', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'a']]);
        $tasinan = YedekHedefi::factory()->create(['ad' => 'B', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'b-yeni']]);
        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $silinecek);
        app(YedekKopyalayici::class)->kopyala($yedek, $tasinan);
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        $tasinan->update(['baglanti' => ['kova' => 'b-eski']]);
        $this->tara();

        app(KopyaSilmeKaydi::class)->kaydet($kopya->refresh());

        $this->assertSame(YedekDurumu::Tamam, $yedek->refresh()->durum);
        $this->assertSame(KopyaDurumu::KonumBelirsiz, YedekKopyasi::query()->where('yedek_hedefi_id', $tasinan->id)->sole()->durum);
    }

    /**
     * BY-028-R8 (G21): yerel dosyası silinmiş yedeğin ikinci diske alınmış
     * kopyası, hedefin ana klasöre dönmüş tanımıyla manifestten yeniden
     * kurulur. İz uyuşmadığı için bugünkü tanım kopyanın yerini göstermez:
     * "ana klasör hedefinin kopyası yerel dosyanın kendisidir" sayılmaz, yedek
     * `silindi` olmaz, varsayılan listede konum uyarısıyla görünür, geri
     * yükleme kaynağı yoktur. Hedef ikinci diske dönünce aynı yedek o kopyadan
     * geri yüklenebilir.
     */
    public function test_ana_klasore_donen_yerel_hedefin_konumu_belirsiz_kopyasi_manifestten_kurulunca_yedegi_listede_tutar_konum_eslesince_kaynak_olur(): void
    {
        $yedek = $this->gercekYedek();
        [$hedef, $ikinciDisk] = $this->ikinciDiskHedefi();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        // Eski veritabanı geri yüklendi: hedef o anda yedek klasörünün kendisiydi
        $hedef->update(['baglanti' => ['klasor' => '']]);
        $this->dizinleriBosalt();

        $this->tara();

        $satir = $this->yedekListesi();
        $this->assertSame([$yedek->kimlik, 'tamam', false, false], [$satir['kimlik'], $satir['durum'], $satir['yerel_dosya_var'], $satir['webden_geri_yuklenebilir']]);
        $this->assertSame([['hedef_ad' => 'Disk 2', 'durum' => 'konum_belirsiz', 'hata_kodu' => HedefKonumu::DOGRULANAMADI]], $this->kopyaOzetleri($satir));
        $this->assertFileExists($ikinciDisk.'/'.$yedek->dosya_adi);

        $hedef->update(['baglanti' => ['klasor' => $ikinciDisk]]);
        $this->tara();

        $satir = $this->yedekListesi();
        $this->assertSame([$yedek->kimlik, 'tamam', true], [$satir['kimlik'], $satir['durum'], $satir['webden_geri_yuklenebilir']]);
        $this->assertSame([['hedef_ad' => 'Disk 2', 'durum' => 'tamam', 'hata_kodu' => null]], $this->kopyaOzetleri($satir));
        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $kaynaklar = GeriYuklemeKaynaklari::sec(false, $kurulan->kopyalar);
        $this->assertSame([$hedef->id], array_map(fn (YedekKopyasi $k): int => $k->yedek_hedefi_id, $kaynaklar->uzaklar));
    }

    /**
     * BY-028-R8 / G1: dosyası ve manifesti klasörden kaybolan yedeğin ikinci
     * diskteki kopyasının yeri, hedef ana klasöre dönmüşken doğrulanamaz;
     * manifestsiz `dosyasiYok` yolu da onu "yerel dosyanın kendisi" saymaz:
     * yedek `silindi` olmaz, listede konum uyarısıyla görünür, geri yükleme
     * kaynağı yoktur.
     */
    public function test_ana_klasore_donen_yerel_hedefin_konumu_belirsiz_kopyasi_dosyasi_ve_manifesti_kaybolan_yedegi_listede_tutar(): void
    {
        $yedek = $this->gercekYedek();
        [$hedef] = $this->ikinciDiskHedefi();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $hedef->update(['baglanti' => ['klasor' => '']]);
        $this->tara();
        $dump = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        unlink($dump);
        unlink(ManifestDeposu::jsonYolu($dump));

        $sonuc = $this->tara();

        $this->assertSame(1, $sonuc['dosyasi_yok']);
        $satir = $this->yedekListesi();
        $this->assertSame([$yedek->kimlik, 'tamam', false, false], [$satir['kimlik'], $satir['durum'], $satir['yerel_dosya_var'], $satir['webden_geri_yuklenebilir']]);
        $this->assertSame([['hedef_ad' => 'Disk 2', 'durum' => 'konum_belirsiz', 'hata_kodu' => HedefKonumu::DOGRULANAMADI]], $this->kopyaOzetleri($satir));
        $this->assertSame(GeriYuklemeHatasi::YEDEK_DOSYASI_YOK, GeriYuklemeKaynaklari::engel($yedek->refresh()->load('kopyalar')));
    }

    /**
     * BY-028-R8: ana klasör eşitliği yeri doğrulanmış kopyada sürer. Hedef
     * yedek klasörünün kendisiyken alınan kopya yerel dosyanın aynısıdır:
     * dosya gidince yedeği tutmaz, yedek `silindi` olur.
     */
    public function test_ana_klasor_hedefinin_yeri_dogrulanmis_kopyasi_yerel_dosya_gidince_yedegi_tutmaz(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Ana', 'tur' => HedefTuru::Yerel, 'baglanti' => ['klasor' => '']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        unlink(config('yedekleme.klasor').'/'.$yedek->dosya_adi);
        $this->dizinleriBosalt();

        $this->tara();

        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $this->assertSame(KopyaDurumu::Tamam, YedekKopyasi::query()->where('yedek_id', $kurulan->id)->sole()->durum);
        $this->assertSame(YedekDurumu::Silindi, $kurulan->durum);
    }

    /**
     * BY-028-R9 (F7, G21): hedef yedekten sonra eklenmişti; geri yüklenen
     * eski veritabanında tanımı yok, yerel dosya da yok, yalnız uzak kopya
     * kalmış. İmzalı olaydaki kopya atlanmaz: yedek varsayılan listede
     * hedefsiz, "konumu doğrulanamadı" kopyasının uyarısıyla görünür, geri
     * yükleme kaynağı yoktur, dosyaya dokunulmaz. Kullanıcı aynı yeri yeni
     * kayıtla (başka ad, başka kimlik) ekleyince sonraki tarama aynı yedeği
     * izle o hedefe bağlar ve kopyadan geri yüklenebilir olur.
     */
    public function test_tanimi_olmayan_hedefteki_kopya_yedegi_listede_tutar_ayni_yer_yeni_kayitla_eklenince_ona_baglanir(): void
    {
        $yedek = $this->gercekYedek();
        [$hedef, $ikinciDisk] = $this->ikinciDiskHedefi();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        $this->dizinleriBosalt();
        $hedef->delete();

        $sonuc = $this->tara();
        $this->tara();

        $this->assertSame(1, $sonuc['bulunamayan_hedef']);
        $satir = $this->yedekListesi();
        $this->assertSame([$yedek->kimlik, 'tamam', false, false], [$satir['kimlik'], $satir['durum'], $satir['yerel_dosya_var'], $satir['webden_geri_yuklenebilir']]);
        $this->assertSame([['hedef_ad' => 'Disk 2', 'durum' => 'konum_belirsiz', 'hata_kodu' => HedefKonumu::HEDEFI_TANIMSIZ]], $this->kopyaOzetleri($satir));
        $this->assertNull($satir['kopyalar'][0]['hedef_id']);
        $this->assertFileExists($ikinciDisk.'/'.$yedek->dosya_adi);

        $yeni = YedekHedefi::factory()->create(['ad' => 'Disk 2 (yeniden)', 'tur' => HedefTuru::Yerel, 'baglanti' => ['klasor' => $ikinciDisk.'/']]);
        $this->assertNotSame($hedef->id, $yeni->id);
        $this->tara();

        $satir = $this->yedekListesi();
        $this->assertSame([$yedek->kimlik, 'tamam', true], [$satir['kimlik'], $satir['durum'], $satir['webden_geri_yuklenebilir']]);
        $this->assertSame([['hedef_ad' => 'Disk 2 (yeniden)', 'durum' => 'tamam', 'hata_kodu' => null]], $this->kopyaOzetleri($satir));
        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $kaynaklar = GeriYuklemeKaynaklari::sec(false, $kurulan->kopyalar);
        $this->assertSame([$yeni->id], array_map(fn (YedekKopyasi $k): int => (int) $k->yedek_hedefi_id, $kaynaklar->uzaklar));
    }

    /**
     * BY-028-R9 / G21: geri yüklenen veritabanında hedefin eski kimliği başka
     * bir yere verilmiş. Kimlikle bulunan hedefin izi uyuşmaz: kopya ona
     * `konum_belirsiz` bağlanır (BY-028-R5). Aynı yer yeni kayıtla eklenince
     * kopya izle o hedefe `tamam` bağlanır, eski kimlikteki izdüşümü kalkar.
     */
    public function test_eski_kimligi_baska_yere_verilen_hedefte_kopya_belirsiz_kalir_ayni_yer_eklenince_ona_baglanir(): void
    {
        $yedek = $this->gercekYedek();
        [$hedef, $ikinciDisk] = $this->ikinciDiskHedefi();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        YedekKopyasi::query()->where('yedek_id', $yedek->id)->delete();
        $hedef->delete();
        $baska = YedekHedefi::factory()->create(['id' => $hedef->id, 'ad' => 'Bulut', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);

        $this->tara();

        $kopya = YedekKopyasi::query()->where('yedek_id', $yedek->id)->sole();
        $this->assertSame([$baska->id, KopyaDurumu::KonumBelirsiz, HedefKonumu::DOGRULANAMADI], [$kopya->yedek_hedefi_id, $kopya->durum, $kopya->hata_kodu]);

        $yeni = YedekHedefi::factory()->create(['ad' => 'Disk 2 (yeniden)', 'tur' => HedefTuru::Yerel, 'baglanti' => ['klasor' => $ikinciDisk]]);
        $this->tara();

        $kopya = YedekKopyasi::query()->where('yedek_id', $yedek->id)->sole();
        $this->assertSame([$yeni->id, KopyaDurumu::Tamam, null], [$kopya->yedek_hedefi_id, $kopya->durum, $kopya->hata_kodu]);
    }

    /**
     * BY-028-R9 / G1: hedefi tanımsız kopyası kalan yedek elle silinince yerel
     * dosya gider; kopya silinmez (yeri bilinmiyor), sonuçta adıyla görünür,
     * yedek `silindi` olmaz, "tamamen silindi" olayı yazılmaz.
     */
    public function test_hedefi_tanimsiz_kopyasi_kalan_yedek_elle_silinince_silindi_olmaz_kopya_sonucta_gorunur(): void
    {
        $yedek = $this->gercekYedek();
        [$hedef, $ikinciDisk] = $this->ikinciDiskHedefi();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        YedekKopyasi::query()->where('yedek_id', $yedek->id)->delete();
        $hedef->delete();
        $this->tara();

        $istek = new Istek((string) Str::uuid(), 'sil', ['yedek_kimligi' => $yedek->kimlik], null, CarbonImmutable::now(), 0);
        $sonuc = app(SilIsleyicisi::class)->isle($istek, $this->kilitAl());
        $this->tutulanKilit?->birak();

        $this->assertSame(['YEDEK_KOPYA_SILINEMEDI', 'tamam'], [$sonuc->kod, $sonuc->ek['yedek_durumu']]);
        $this->assertStringContainsString('Disk 2: '.HedefKonumu::HEDEFI_TANIMSIZ, (string) $sonuc->mesaj);
        $this->assertFileDoesNotExist(config('yedekleme.klasor').'/'.$yedek->dosya_adi);
        $this->assertFileExists($ikinciDisk.'/'.$yedek->dosya_adi);
        $turler = array_map(fn (YedekOlayi $o): OlayTuru => $o->tur, array_values(array_filter(app(OlayGunlugu::class)->oku()['olaylar'], fn (YedekOlayi $o): bool => $o->yedekKimligi === $yedek->kimlik)));
        $this->assertNotContains(OlayTuru::Silindi, $turler);
    }

    /** BY-028-R9 / G1: dosyası ve manifesti kaybolan yedek, hedefi tanımsız kopyası durdukça `dosyasiYok` yolunda da `silindi` olmaz. */
    public function test_dosyasi_ve_manifesti_kaybolan_yedek_hedefi_tanimsiz_kopyasiyla_silindi_olmaz(): void
    {
        $yedek = $this->gercekYedek();
        [$hedef] = $this->ikinciDiskHedefi();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        YedekKopyasi::query()->where('yedek_id', $yedek->id)->delete();
        $hedef->delete();
        $this->tara();
        $dump = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        unlink($dump);
        unlink(ManifestDeposu::jsonYolu($dump));

        $sonuc = $this->tara();

        $this->assertSame(1, $sonuc['dosyasi_yok']);
        $this->assertSame(YedekDurumu::Tamam, $yedek->refresh()->durum);
        $this->assertSame(HedefKonumu::HEDEFI_TANIMSIZ, YedekKopyasi::query()->where('yedek_id', $yedek->id)->sole()->hata_kodu);
    }

    /**
     * BY-028-R10 (G21, F7): eski hedefin tanımı geri yüklenen veritabanında
     * yok; tamam kopyası konum iziyle aynı yere yeni kayıtla eklenen hedefe
     * bağlanır. O hedefin kendi kopyası (aynı yerde FARKLI nesne) silinmiş.
     * Silme olayı yaşayan nesnenin kaydını ezmez: olayların iki sırasında da,
     * iki taramada da hedefin kaydı yaşayan nesnedir ve geri yükleme kaynağıdır.
     */
    #[DataProvider('olaySiralari')]
    public function test_ayni_hedefe_eslenen_yasayan_kopya_hedefin_silinmis_baska_nesnesiyle_ezilmez(bool $eskiKopyaOnce): void
    {
        $yedek = $this->gercekYedek();
        $eski = YedekHedefi::factory()->create(['ad' => 'Eski', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        $yeni = YedekHedefi::factory()->create(['ad' => 'Yeni', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        if ($eskiKopyaOnce) {
            app(YedekKopyalayici::class)->kopyala($yedek, $eski);
            $this->travel(1)->minutes();
            $this->kopyalaVeSil($yedek, $yeni);
        } else {
            $this->kopyalaVeSil($yedek, $yeni);
            $this->travel(1)->minutes();
            app(YedekKopyalayici::class)->kopyala($yedek, $eski);
        }
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        $this->dizinleriBosalt();
        $eski->delete();

        foreach (['ilk', 'ikinci'] as $tarama) {
            $this->tara();

            $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
            $kopya = YedekKopyasi::query()->where('yedek_id', $kurulan->id)->sole();
            $this->assertSame([YedekDurumu::Tamam, $yeni->id, KopyaDurumu::Tamam, $eski->id.'/'.$yedek->dosya_adi], [$kurulan->durum, $kopya->yedek_hedefi_id, $kopya->durum, $kopya->uzak_kimlik], "{$tarama} tarama");
            $this->assertSame([$yeni->id], array_map(fn (YedekKopyasi $k): int => (int) $k->yedek_hedefi_id, GeriYuklemeKaynaklari::sec(false, $kurulan->kopyalar)->uzaklar));
        }
    }

    /** @return array<string, array{bool}> */
    public static function olaySiralari(): array
    {
        return [
            'eski hedefin kopyası önce' => [true],
            'yeni hedefin silinen kopyası önce' => [false],
        ];
    }

    /**
     * BY-028-R10: aynı güncel hedefe iki yaşayan FARKLI nesne düşer (eski
     * hedefin ve yeni kayıtla eklenen aynı yerin kopyası). Hedefe kendi
     * olayının nesnesi bağlanır; diğeri kaybolmaz: hedefsiz, kullanılamaz
     * kayıt olarak listede kalır. İki tarama aynı sonucu verir. Bağlı nesne
     * silinince sonraki taramada diğeri hedefe bağlanır.
     */
    public function test_ayni_hedefe_eslenen_ikinci_yasayan_nesne_hedefsiz_korunur_bagli_nesne_silinince_hedefe_baglanir(): void
    {
        $yedek = $this->gercekYedek();
        $eski = YedekHedefi::factory()->create(['ad' => 'Eski', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        $yeni = YedekHedefi::factory()->create(['ad' => 'Yeni', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $eski);
        $this->travel(1)->minutes();
        app(YedekKopyalayici::class)->kopyala($yedek, $yeni);
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        $this->dizinleriBosalt();
        $eski->delete();
        $eskiNesne = $eski->id.'/'.$yedek->dosya_adi;
        $yeniNesne = $yeni->id.'/'.$yedek->dosya_adi;

        foreach (['ilk', 'ikinci'] as $tarama) {
            $this->tara();

            $satir = $this->yedekListesi();
            $this->assertSame([$yedek->kimlik, 'tamam', true], [$satir['kimlik'], $satir['durum'], $satir['webden_geri_yuklenebilir']], "{$tarama} tarama");
            $this->assertEqualsCanonicalizing([
                ['hedef_id' => $yeni->id, 'hedef_ad' => 'Yeni', 'durum' => 'tamam', 'hata_kodu' => null],
                ['hedef_id' => null, 'hedef_ad' => 'Eski', 'durum' => 'konum_belirsiz', 'hata_kodu' => HedefKonumu::HEDEFTE_BASKA_KOPYA],
            ], array_map(fn (array $k): array => array_intersect_key($k, array_flip(['hedef_id', 'hedef_ad', 'durum', 'hata_kodu'])), $satir['kopyalar']));
            $this->assertSame([$yeniNesne, $eskiNesne], YedekKopyasi::query()->orderByRaw('yedek_hedefi_id is null')->pluck('uzak_kimlik')->all());
        }

        unset(BellekHedefi::$dosyalar[$yeniNesne]);
        app(KopyaSilmeKaydi::class)->kaydet(YedekKopyasi::query()->where('yedek_hedefi_id', $yeni->id)->sole());
        $this->tara();

        $kopya = YedekKopyasi::query()->sole();
        $this->assertSame([$yeni->id, KopyaDurumu::Tamam, $eskiNesne], [$kopya->yedek_hedefi_id, $kopya->durum, $kopya->uzak_kimlik]);
    }

    /**
     * BY-028-R10: konum iziyle yeni hedefe bağlanmış eski hedef kopyası elle /
     * saklamayla silinince silme olayı o nesneye uygulanır: yeniden kurmada
     * kopya "tamam" diye geri gelmez (silinmiş dosya geri yükleme kaynağı
     * olmaz), yeni hedefin kendi olayındaki başka nesneye de karışmaz.
     */
    public function test_konum_iziyle_baglanan_kopya_silinince_yeniden_kurmada_silinmis_kalir(): void
    {
        $yedek = $this->gercekYedek();
        $eski = YedekHedefi::factory()->create(['ad' => 'Eski', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        $yeni = YedekHedefi::factory()->create(['ad' => 'Yeni', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $eski);
        $this->travel(1)->minutes();
        $this->kopyalaVeSil($yedek, $yeni);
        app(YerelDosyaSilici::class)->sil($yedek->refresh());
        $this->dizinleriBosalt();
        $eski->delete();
        $this->tara();
        $eskiNesne = $eski->id.'/'.$yedek->dosya_adi;
        $this->travel(1)->minutes();
        unset(BellekHedefi::$dosyalar[$eskiNesne]);
        app(KopyaSilmeKaydi::class)->kaydet(YedekKopyasi::query()->where('yedek_hedefi_id', $yeni->id)->sole());

        foreach (['ilk', 'ikinci'] as $tarama) {
            $this->tara();

            $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
            $kopya = YedekKopyasi::query()->where('yedek_id', $kurulan->id)->sole();
            $this->assertSame([YedekDurumu::Silindi, $yeni->id, KopyaDurumu::Silindi], [$kurulan->durum, $kopya->yedek_hedefi_id, $kopya->durum], "{$tarama} tarama");
        }
    }

    /**
     * BY-028-R10: aynı yerdeki AYNI nesneyi (aynı uzak kimlik) iki olay hedefi
     * anar: tek kayıt olur, ikinci nesne diye hedefsiz kopya açılmaz. Nesnenin
     * sonraki silinmesi (uzak kimliksiz eski silme olayı) onun durumudur:
     * diğer olay hedefinin daha eski "tamam" kaydı onu geri getirmez.
     */
    public function test_ayni_nesneyi_anan_iki_olay_hedefi_tek_kayit_olur_nesne_silinince_silindi_kalir(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Bulut', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        $ortak = [
            'durum' => 'tamam', 'uzak_kimlik' => 'ortak/'.$yedek->dosya_adi, 'sifreli' => false, 'sifreli_sha256' => null,
            'konum_izi' => app(HedefKonumu::class)->iz($hedef), 'plan_idleri' => [],
        ];
        $olaylar = app(OlayGunlugu::class);
        $olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Kopya, ['hedef_id' => $hedef->id, 'hedef_ad' => 'Bulut', ...$ortak]));
        $this->travel(1)->minutes();
        $olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Kopya, ['hedef_id' => $hedef->id + 100, 'hedef_ad' => 'Eski', ...$ortak]));
        unlink(config('yedekleme.klasor').'/'.$yedek->dosya_adi);
        $this->dizinleriBosalt();

        $this->tara();

        $kopya = YedekKopyasi::query()->sole();
        $this->assertSame([$hedef->id, KopyaDurumu::Tamam], [$kopya->yedek_hedefi_id, $kopya->durum]);

        $this->travel(1)->minutes();
        $olaylar->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::KopyaSilindi, ['hedef_id' => $hedef->id, 'hedef_ad' => 'Bulut']));
        $this->tara();

        $kopya = YedekKopyasi::query()->sole();
        $this->assertSame([$hedef->id, KopyaDurumu::Silindi], [$kopya->yedek_hedefi_id, $kopya->durum]);
        $this->assertSame(YedekDurumu::Silindi, Yedek::query()->where('kimlik', $yedek->kimlik)->sole()->durum);
    }

    /**
     * Yedek klasöründen ayrı bir "ikinci disk" yerel hedefi
     *
     * @return array{0: YedekHedefi, 1: string}
     */
    private function ikinciDiskHedefi(): array
    {
        $klasor = $this->yedekKoku.'/disk2';
        mkdir($klasor, 0750);

        return [YedekHedefi::factory()->create(['ad' => 'Disk 2', 'tur' => HedefTuru::Yerel, 'baglanti' => ['klasor' => $klasor]]), $klasor];
    }

    /**
     * Listedeki kopyaların rozetin dayandığı alanları
     *
     * @param  array<string, mixed>  $satir
     * @return list<array{hedef_ad: mixed, durum: mixed, hata_kodu: mixed}>
     */
    private function kopyaOzetleri(array $satir): array
    {
        return array_map(fn (array $k): array => ['hedef_ad' => $k['hedef_ad'], 'durum' => $k['durum'], 'hata_kodu' => $k['hata_kodu']], $satir['kopyalar']);
    }

    /**
     * Yedekler listesinin varsayılan süzgeçle tek satırı (ekranın gördüğü)
     *
     * @return array<string, mixed>
     */
    private function yedekListesi(): array
    {
        config(['yedekleme.aktif' => true]);
        $rol = Rol::query()->create(['ad' => 'Yedek görüntüle '.random_int(1, 99999)]);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => 'yedekler.goruntule']);
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        $veri = $this->actingAs($kullanici)->getJson('/api/v1/ayarlar/yedekler')->assertOk()->json('data');
        $this->assertCount(1, $veri);

        return $veri[0];
    }

    /** Hedefe kopyalar, sonra kopyayı hedefte siler ve silme kaydını yazar (elle silme / saklamanın sonucu) */
    private function kopyalaVeSil(Yedek $yedek, YedekHedefi $hedef): void
    {
        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        unset(BellekHedefi::$dosyalar[$kopya->uzak_kimlik]);
        app(KopyaSilmeKaydi::class)->kaydet($kopya->refresh());
    }

    private function planYedegi(YedekHedefi $hedef, YedekPlani $plan): Yedek
    {
        $yedek = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Plan, null, null, [$plan->ad]);
        $this->tutulanKilit?->birak();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef, [$plan->id]);

        return $yedek;
    }
}
