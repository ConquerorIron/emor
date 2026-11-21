<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\DepoIsaretiYok;
use App\Services\Yedekleme\DogrulamaDurumu;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SilmeKarari;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-010: saklama ve temizlik (README §4.3, §6.2; kullanıcı kararları F6, F7).
 */
final class SaklamaPolitikasiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        BellekHedefi::sifirla();
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00', 'UTC'));
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /** Yerel dosyası ve manifesti olan yedek; $gunOnce gün önce alınmış */
    private function yedek(int $gunOnce, YedekKaynagi $kaynak = YedekKaynagi::Elle, array $ek = []): Yedek
    {
        $yedek = Yedek::factory()->create([
            'kaynak' => $kaynak,
            'dosya_adi' => 'erp_'.$kaynak->value.'_'.$gunOnce.'_'.bin2hex(random_bytes(3)).'.dump',
            'alinma_zamani' => CarbonImmutable::now()->subDays($gunOnce),
            ...$ek,
        ]);
        $yol = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        file_put_contents($yol, 'dump');
        file_put_contents(ManifestDeposu::jsonYolu($yol), '{}');

        return $yedek;
    }

    private function bellekHedefi(string $ad): YedekHedefi
    {
        return YedekHedefi::factory()->create(['ad' => $ad, 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
    }

    private function anaYerelHedef(): YedekHedefi
    {
        return YedekHedefi::factory()->create(['ad' => 'Ana', 'baglanti' => ['klasor' => '']]);
    }

    /**
     * @param  list<YedekPlani>  $planlar
     */
    private function kopya(Yedek $yedek, YedekHedefi $hedef, array $planlar = [], KopyaDurumu $durum = KopyaDurumu::Tamam): YedekKopyasi
    {
        $uzak = $hedef->tur === HedefTuru::S3 ? $hedef->id.'/'.$yedek->dosya_adi : $yedek->dosya_adi;
        if ($hedef->tur === HedefTuru::S3) {
            BellekHedefi::$dosyalar[$uzak] = 'dump';
        }
        $kopya = YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => $durum, 'uzak_kimlik' => $uzak, 'sifreli' => $hedef->tur === HedefTuru::S3]);
        foreach ($planlar as $plan) {
            $kopya->planlar()->attach($plan->id, ['yedek_hedefi_id' => $hedef->id]);
        }

        return $kopya;
    }

    private function plan(YedekHedefi $hedef, ?int $son, ?int $gun = null): YedekPlani
    {
        $plan = YedekPlani::factory()->create();
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => $son, 'sakla_gun' => $gun]);

        return $plan;
    }

    /** @return list<int> */
    private function silinecekYedekler(): array
    {
        return array_values(array_map(fn (SilmeKarari $k): int => $k->yedekId, array_filter(
            app(SaklamaPolitikasi::class)->hesapla()['silinecek'],
            fn (SilmeKarari $k): bool => $k->tur === SilmeKarari::DOSYA,
        )));
    }

    public function test_kural_olmadan_silinecek_elle_yedekler_en_eskiden_baslayarak_silinir_en_az_sayi_kalir(): void
    {
        $eski = $this->yedek(5);
        $orta = $this->yedek(3);
        $this->yedek(1);

        $this->assertEqualsCanonicalizing([$eski->id, $orta->id], $this->silinecekYedekler());
    }

    public function test_sabitlenmis_yedek_korunur(): void
    {
        $eski = $this->yedek(5, ek: ['sabit' => true]);
        $orta = $this->yedek(3);
        $this->yedek(1);

        $this->assertSame([$orta->id], $this->silinecekYedekler());
        $this->assertContains('sabit', app(SaklamaPolitikasi::class)->hesapla()['korunan'][$eski->id]);
    }

    public function test_sinama_geri_yuklemesi_gecmis_en_yeni_yedek_korunur(): void
    {
        $eski = $this->yedek(5, ek: ['dogrulama_durumu' => DogrulamaDurumu::SinamaGecti]);
        $orta = $this->yedek(3);
        $this->yedek(1);

        $this->assertSame([$orta->id], $this->silinecekYedekler());
        $this->assertContains('dogrulanmis_en_yeni', app(SaklamaPolitikasi::class)->hesapla()['korunan'][$eski->id]);
    }

    public function test_kullanimdaki_kopyalanan_yedek_korunur(): void
    {
        $eski = $this->yedek(5);
        $orta = $this->yedek(3);
        $this->yedek(1);
        $this->kopya($eski, $this->bellekHedefi('Uzak'), durum: KopyaDurumu::Kopyalaniyor);

        $this->assertSame([$orta->id], $this->silinecekYedekler());
        $this->assertContains('kullanimda', app(SaklamaPolitikasi::class)->hesapla()['korunan'][$eski->id]);
    }

    public function test_son_basarili_yedek_korunma_nedeniyle_isaretlenir(): void
    {
        $this->yedek(5);
        $son = $this->yedek(1);

        $this->assertNotContains($son->id, $this->silinecekYedekler());
        $this->assertContains('son_basarili', app(SaklamaPolitikasi::class)->hesapla()['korunan'][$son->id]);
    }

    public function test_bir_hedefteki_silme_ayni_yedegin_diger_hedefteki_kopyasina_dokunmaz(): void
    {
        $hedef1 = $this->bellekHedefi('Bir');
        $hedef2 = $this->bellekHedefi('İki');
        $kisa = $this->plan($hedef1, 1);
        $uzun = $this->plan($hedef2, 10);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        foreach ([$eski, $yeni] as $y) {
            $this->kopya($y, $hedef1, [$kisa]);
            $this->kopya($y, $hedef2, [$uzun]);
        }

        app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame(KopyaDurumu::Silindi, YedekKopyasi::query()->where(['yedek_id' => $eski->id, 'yedek_hedefi_id' => $hedef1->id])->sole()->durum);
        $this->assertSame(KopyaDurumu::Tamam, YedekKopyasi::query()->where(['yedek_id' => $eski->id, 'yedek_hedefi_id' => $hedef2->id])->sole()->durum);
        $this->assertArrayHasKey($hedef2->id.'/'.$eski->dosya_adi, BellekHedefi::$dosyalar);
        $this->assertArrayNotHasKey($hedef1->id.'/'.$eski->dosya_adi, BellekHedefi::$dosyalar);
    }

    public function test_son_yedekler_basarisizsa_hicbir_basarili_yedek_silinmez(): void
    {
        $this->yedek(30);
        $this->yedek(20);
        $this->yedek(10);
        Yedek::factory()->count(10)->create(['durum' => YedekDurumu::Basarisiz, 'alinma_zamani' => null]);

        $hesap = app(SaklamaPolitikasi::class)->hesapla();

        $this->assertSame([], $hesap['silinecek']);
        $this->assertSame('son_yedek_basarisiz', $hesap['temkin']);
    }

    public function test_onizleme_ile_gercek_calistirma_ayni_listeyi_verir(): void
    {
        $hedef = $this->bellekHedefi('Uzak');
        $plan = $this->plan($hedef, 2);
        foreach ([9, 7, 5, 3, 1] as $gun) {
            $this->kopya($this->yedek($gun, YedekKaynagi::Plan), $hedef, [$plan]);
        }
        $this->yedek(8);
        $this->yedek(6);
        $anahtarlar = fn (array $kararlar): array => array_map(fn (SilmeKarari $k): string => $k->anahtar(), $kararlar);

        $onizleme = $anahtarlar(app(SaklamaPolitikasi::class)->hesapla()['silinecek']);
        $uygulanan = $anahtarlar(app(SaklamaPolitikasi::class)->uygula()['kararlar']);

        $this->assertNotEmpty($onizleme);
        $this->assertEqualsCanonicalizing($onizleme, $uygulanan);
    }

    public function test_erisilemeyen_hedef_atlanir_diger_hedeflerin_temizligi_surer(): void
    {
        $uzak = $this->bellekHedefi('Uzak');
        $disk = YedekHedefi::factory()->create(['ad' => 'Disk 2', 'baglanti' => ['klasor' => $this->yedekKoku.'/disk2']]);
        mkdir($this->yedekKoku.'/disk2');
        $planUzak = $this->plan($uzak, 1);
        $planDisk = $this->plan($disk, 1);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        foreach ([$eski, $yeni] as $y) {
            $this->kopya($y, $uzak, [$planUzak]);
            file_put_contents($this->yedekKoku.'/disk2/'.$y->dosya_adi, 'dump');
            $this->kopya($y, $disk, [$planDisk]);
        }
        BellekHedefi::$hataAdimi = 'sil';

        $sonuc = app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame([$uzak->id], $sonuc['atlanan_hedefler']);
        $this->assertFileDoesNotExist($this->yedekKoku.'/disk2/'.$eski->dosya_adi);
        $this->assertSame(KopyaDurumu::Tamam, YedekKopyasi::query()->where(['yedek_id' => $eski->id, 'yedek_hedefi_id' => $uzak->id])->sole()->durum);
    }

    /** BY-023: S3 nesne kilidi gibi silme yetkisi verilmeyen hedefte temizlik hata saymaz, bilgi yazar. */
    public function test_silme_yetkisi_olmayan_hedefte_temizlik_hata_saymaz_bilgi_yazar(): void
    {
        $uzak = $this->bellekHedefi('Kilitli S3');
        $plan = $this->plan($uzak, 1);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        $this->kopya($eski, $uzak, [$plan]);
        $this->kopya($yeni, $uzak, [$plan]);
        BellekHedefi::$hataAdimi = 'sil';
        BellekHedefi::$hataKodu = HedefHatasi::SILME_YETKISI_YOK;

        $sonuc = app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame([], $sonuc['atlanan_hedefler']);
        $this->assertCount(1, $sonuc['bilgi']);
        $this->assertStringContainsString('silme yetkisi yok', $sonuc['bilgi'][0]);
        $kopya = YedekKopyasi::query()->where(['yedek_id' => $eski->id, 'yedek_hedefi_id' => $uzak->id])->sole();
        $this->assertSame([KopyaDurumu::Silindi, HedefHatasi::SILME_YETKISI_YOK], [$kopya->durum, $kopya->hata_kodu]);
        $this->assertSame(0, YedekIslemKaydi::query()->where('sonuc', 'basarisiz')->count());
    }

    /** F6: bir planın kuralı yalnız kendi bağını kaldırır; diğer plan tutuyorken dosya silinmez. */
    public function test_ayni_hedefi_paylasan_planlarda_kural_yalniz_kendi_bagini_kaldirir_dosya_kalir(): void
    {
        $hedef = $this->bellekHedefi('Ortak');
        $kisa = $this->plan($hedef, 1);
        $uzun = $this->plan($hedef, 10);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        $eskiKopya = $this->kopya($eski, $hedef, [$kisa, $uzun]);
        $this->kopya($yeni, $hedef, [$kisa, $uzun]);

        app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame([$uzun->id], $eskiKopya->planlar()->pluck('yedek_planlari.id')->all());
        $this->assertSame(KopyaDurumu::Tamam, $eskiKopya->refresh()->durum);
    }

    /** F6: son bağ kalkınca dosya ancak koruma kuralları izin verirse silinir. */
    public function test_son_bagi_kalkan_sabit_kopya_silinmez(): void
    {
        $hedef = $this->bellekHedefi('Uzak');
        $plan = $this->plan($hedef, 1);
        $eski = $this->yedek(5, YedekKaynagi::Plan, ['sabit' => true]);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        $eskiKopya = $this->kopya($eski, $hedef, [$plan]);
        $this->kopya($yeni, $hedef, [$plan]);

        app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame([], $eskiKopya->planlar()->pluck('yedek_planlari.id')->all());
        $this->assertSame(KopyaDurumu::Tamam, $eskiKopya->refresh()->durum);
    }

    /** BY-004-R9: bağsız plan yedeğine zamanlanmamışların kuralı uygulanmaz (kural kaynaktan seçilir). */
    public function test_bagsiz_plan_yedegine_elle_yedek_kurali_uygulanmaz(): void
    {
        YedekAyari::query()->update(['elle_gun' => 1000]);
        $hedef = $this->bellekHedefi('Uzak');
        $plan = $this->plan($hedef, 1);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $this->kopya($eski, $hedef, [$plan]);
        $this->kopya($this->yedek(1, YedekKaynagi::Plan), $hedef, [$plan]);

        $kararlar = app(SaklamaPolitikasi::class)->hesapla()['silinecek'];

        $dosya = array_values(array_filter($kararlar, fn (SilmeKarari $k): bool => $k->tur === SilmeKarari::DOSYA && $k->yedekId === $eski->id && $k->hedefId === $hedef->id));
        $this->assertCount(1, $dosya);
        $this->assertSame(SilmeKarari::NEDEN_BAGSIZ, $dosya[0]->neden);
    }

    /**
     * F7: yerel dosya silinip uzakta kurtarmaya hazır kopya kalınca yedek
     * `silindi` olmaz; manifest kalır. (BY-010-R4: uzak kopya hazır değilse
     * yerel dosya kalır — aşağıdaki veri sağlayıcılı test.)
     */
    public function test_yerel_dosya_silinince_kurtarmaya_hazir_uzak_kopyasi_kalan_yedek_silindi_olmaz(): void
    {
        $ana = $this->anaYerelHedef();
        $uzak = $this->bellekHedefi('Uzak');
        $yerelPlan = $this->plan($ana, 1);
        $uzakPlan = $this->plan($uzak, 10);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        foreach ([$eski, $yeni] as $y) {
            $this->kopya($y, $ana, [$yerelPlan]);
            $this->kopya($y, $uzak, [$uzakPlan])->update(['kurtarma_hazir' => true]);
        }

        app(SaklamaPolitikasi::class)->uygula();

        $yol = config('yedekleme.klasor').'/'.$eski->dosya_adi;
        $this->assertFileDoesNotExist($yol);
        $this->assertFileExists(ManifestDeposu::jsonYolu($yol));
        $eski->refresh();
        $this->assertSame(YedekDurumu::Tamam, $eski->durum);
        $this->assertNotNull($eski->yerel_silinme_zamani);
    }

    /** @return array<string, array{bool, bool, bool}> anaKlasorHedefi, kalanKopyaHazir, yerelDosyaKalir */
    public static function ayniTurdaSilinenHazirKopyaKurgulari(): array
    {
        return [
            'yedek klasörü yolu, kalan kopya hazır değil' => [false, false, true],
            'yedek klasörü yolu, kalan kopya hazır' => [false, true, false],
            'ana klasör hedefi yolu, kalan kopya hazır değil' => [true, false, true],
            'ana klasör hedefi yolu, kalan kopya hazır' => [true, true, false],
        ];
    }

    /**
     * BY-010-R4 (D4, F7): A hedefi (son 1) eski yedeğin HAZIR kopyasını bu
     * turda siler, B hedefi (son 2) aynı yedeğin kopyasını tutar. Yerel
     * dosyanın silinmesi aynı turda silinecek A kopyasına değil, temizlikten
     * sonra kalan B kopyasının hazırlığına bağlı — yerel dosyanın planda ana
     * klasör hedefi olarak yazıldığı yolda da.
     */
    #[DataProvider('ayniTurdaSilinenHazirKopyaKurgulari')]
    public function test_yerel_dosya_yalniz_temizlikten_sonra_kalan_hazir_uzak_kopyaya_dayanilarak_silinir(bool $anaKlasorHedefi, bool $kalanKopyaHazir, bool $yerelDosyaKalir): void
    {
        $a = $this->bellekHedefi('A');
        $b = $this->bellekHedefi('B');
        $plan = $this->plan($a, 1);
        $plan->hedefler()->attach($b, ['sakla_son_adet' => 2, 'sakla_gun' => null]);
        $ana = $anaKlasorHedefi ? $this->anaYerelHedef() : null;
        if ($ana !== null) {
            $plan->hedefler()->attach($ana, ['sakla_son_adet' => 1, 'sakla_gun' => null]);
        }
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        foreach ([$eski, $yeni] as $y) {
            if ($ana !== null) {
                $this->kopya($y, $ana, [$plan]);
            }
            $this->kopya($y, $a, [$plan])->update(['kurtarma_hazir' => true]);
            $this->kopya($y, $b, [$plan])->update(['kurtarma_hazir' => $kalanKopyaHazir]);
        }
        $onizleme = app(SaklamaPolitikasi::class)->hesapla();

        app(SaklamaPolitikasi::class)->uygula();

        $durum = fn (YedekHedefi $hedef): KopyaDurumu => YedekKopyasi::query()->where(['yedek_id' => $eski->id, 'yedek_hedefi_id' => $hedef->id])->sole()->durum;
        $this->assertSame([KopyaDurumu::Silindi, KopyaDurumu::Tamam], [$durum($a), $durum($b)]);
        $this->assertArrayNotHasKey($a->id.'/'.$eski->dosya_adi, BellekHedefi::$dosyalar);
        $this->assertArrayHasKey($b->id.'/'.$eski->dosya_adi, BellekHedefi::$dosyalar);

        $yol = config('yedekleme.klasor').'/'.$eski->dosya_adi;
        $eski->refresh();
        $this->assertSame(YedekDurumu::Tamam, $eski->durum);
        if ($yerelDosyaKalir) {
            $this->assertFileExists($yol);
            $this->assertNull($eski->yerel_silinme_zamani);
            $this->assertContains('kurtarma_paketi_eksik', $onizleme['korunan'][$eski->id]);
            if ($ana !== null) {
                $this->assertSame(KopyaDurumu::Tamam, $durum($ana));
            }
        } else {
            $this->assertFileDoesNotExist($yol);
            $this->assertNotNull($eski->yerel_silinme_zamani);
            $this->assertFileExists(ManifestDeposu::jsonYolu($yol));
        }
    }

    /** F7: uzak kopyası doğrulanmamış (başarısız) yedeğin yerel dosyası silinmez. */
    public function test_uzak_kopyasi_basarisiz_yedegin_yerel_dosyasi_silinmez(): void
    {
        $ana = $this->anaYerelHedef();
        $yerelPlan = $this->plan($ana, 1);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $this->kopya($eski, $ana, [$yerelPlan]);
        $this->kopya($this->yedek(1, YedekKaynagi::Plan), $ana, [$yerelPlan]);
        $this->kopya($eski, $this->bellekHedefi('Uzak'), durum: KopyaDurumu::Basarisiz);

        app(SaklamaPolitikasi::class)->uygula();

        $this->assertFileExists(config('yedekleme.klasor').'/'.$eski->dosya_adi);
        $this->assertContains('uzak_kopya_dogrulanmadi', app(SaklamaPolitikasi::class)->hesapla()['korunan'][$eski->id]);
    }

    /**
     * BY-011-R3 / G1: yedek diski bağlı değilken (boş, okunabilir bağlama
     * noktası) temizlik yerel dosyaya ve ana klasör kopyasına dokunmaz;
     * dosyası "yok" görünen yedek katalogdan düşmez.
     */
    public function test_yedek_diski_bagli_degilken_temizlik_yerel_dosyaya_ve_kataloga_dokunmaz(): void
    {
        $ana = $this->anaYerelHedef();
        $yerelPlan = $this->plan($ana, 1);
        $eskiElle = $this->yedek(5);
        $this->yedek(1);
        $eskiPlan = $this->yedek(5, YedekKaynagi::Plan);
        $anaKopya = $this->kopya($eskiPlan, $ana, [$yerelPlan]);
        $this->kopya($this->yedek(1, YedekKaynagi::Plan), $ana, [$yerelPlan]);
        $this->assertEqualsCanonicalizing([$eskiElle->id, $eskiPlan->id], $this->silinecekYedekler());
        $baglar = DB::table('yedek_kopya_planlari')->count();
        $klasor = config('yedekleme.klasor');
        rename($klasor, $klasor.'-disk');
        mkdir($klasor, 0750);

        try {
            try {
                app(SaklamaPolitikasi::class)->uygula();
                $this->fail('Depo doğrulanmadan temizlik durmalıydı');
            } catch (DepoIsaretiYok) {
            }
            $this->assertSame([], array_values(array_diff(scandir($klasor), ['.', '..'])), 'Bağlama noktasına (olay günlüğü dahil) hiçbir şey yazılmaz');
        } finally {
            array_map('unlink', glob($klasor.'/{,.}[!.]*', GLOB_BRACE) ?: []);
            rmdir($klasor);
            rename($klasor.'-disk', $klasor);
        }

        $this->assertSame($baglar, DB::table('yedek_kopya_planlari')->count());
        $this->assertSame('YEDEK_DEPO_ISARETI_YOK', YedekIslemKaydi::query()->where('islem', 'saklama_sil')->sole()->ayrinti['kod']);
        foreach ([$eskiElle, $eskiPlan] as $yedek) {
            $yedek->refresh();
            $this->assertSame([YedekDurumu::Tamam, null], [$yedek->durum, $yedek->yerel_silinme_zamani]);
            $this->assertFileExists($klasor.'/'.$yedek->dosya_adi);
        }
        $this->assertSame(KopyaDurumu::Tamam, $anaKopya->refresh()->durum);
    }

    /** F7: hiçbir kopyası kalmayan yedek `silindi`. */
    public function test_hicbir_kopyasi_kalmayan_yedek_silindi_olur_kayit_kalir(): void
    {
        $eski = $this->yedek(5);
        $this->yedek(1);

        app(SaklamaPolitikasi::class)->uygula();

        $eski->refresh();
        $this->assertSame(YedekDurumu::Silindi, $eski->durum);
        $this->assertNotNull($eski->silinme_zamani);
    }
}
