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
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SilmeKarari;
use App\Services\Yedekleme\YedekDeposu;
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

    /**
     * YF-06 (D4): geri yüklenemeyen imzasız yedekler "son başarılı" ve en az
     * sayıyı doldurmaz; imzalı yedekler en az sayının altına silinmez.
     */
    public function test_imzasiz_yedekler_son_basarili_ve_en_az_sayiyi_doldurmaz(): void
    {
        YedekAyari::query()->update(['enaz_adet' => 3, 'elle_gun' => 30]);
        $this->yedek(60);
        $yeniImzali = $this->yedek(50);
        foreach ([3, 2, 1] as $gun) {
            $this->yedek($gun, ek: ['imzali' => false]);
        }

        $this->assertSame([], $this->silinecekYedekler());
        $this->assertContains('son_basarili', app(SaklamaPolitikasi::class)->hesapla()['korunan'][$yeniImzali->id]);
    }

    /**
     * YF-35: bir kararın beklenmeyen hatası (disk dolu → olay günlüğü
     * yazılamıyor) turu kesmez: sonraki yerel silme yine uygulanır ve hata
     * denetim kaydında görünür.
     */
    public function test_bag_kararinda_beklenmeyen_hata_turu_kesmez_yerel_silme_uygulanir(): void
    {
        $uzak = $this->bellekHedefi('Uzak');
        $plan = $this->plan($uzak, 1);
        foreach ([5, 1] as $gun) {
            $this->kopya($this->yedek($gun, YedekKaynagi::Plan), $uzak, [$plan]);
        }
        $elle = $this->yedek(9);
        $this->yedek(8);
        // Olay günlüğünün yerinde klasör: her olay eklemesi hata verir
        $olaylar = config('yedekleme.klasor').'/olaylar.jsonl';
        @unlink($olaylar);
        mkdir($olaylar);

        app(SaklamaPolitikasi::class)->uygula();

        $this->assertFileDoesNotExist(config('yedekleme.klasor').'/'.$elle->dosya_adi);
        $this->assertTrue(YedekIslemKaydi::query()->where('islem', 'saklama_bag')->where('sonuc', IslemSonucu::Basarisiz->value)
            ->get()->contains(fn (YedekIslemKaydi $k): bool => $k->ayrinti['kod'] === SaklamaPolitikasi::BEKLENMEYEN_HATA));
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

    /**
     * @return array<string, array{string}>
     */
    public static function ayniKlasorunIkinciYazimi(): array
    {
        return [
            'aynı yazım, farklı ad' => [''],
            'noktalı yazım' => ['/.'],
            'üst klasörden dönen yazım' => ['/../disk2'],
            'sembolik bağlantı' => ['bag'],
        ];
    }

    /**
     * BY-010-R7: eskiden kalmış iki hedef ana yedek klasörü DIŞINDAKİ aynı
     * dizini gösteriyor (API artık reddediyor). Kısa kurallı hedefin ortak
     * dosyayı silmesi uzun kurallı hedefin kopyasını `tamam` bırakır, yerel
     * dosya da ona dayanılarak silinirdi: bütün fiziksel kopyalar giderdi.
     */
    #[DataProvider('ayniKlasorunIkinciYazimi')]
    public function test_ayni_klasoru_gosteren_iki_hedefte_saklama_ortak_dosyayi_silmez_gorunur_hata_yazar(string $ikinciYazim): void
    {
        $disk = $this->yedekKoku.'/disk2';
        mkdir($disk);
        if ($ikinciYazim === 'bag') {
            symlink($disk, $this->yedekKoku.'/bag');
        }
        $uzunHedef = YedekHedefi::factory()->create(['ad' => 'Disk uzun', 'baglanti' => ['klasor' => $disk]]);
        $kisaHedef = YedekHedefi::factory()->create(['ad' => 'Disk kısa', 'baglanti' => ['klasor' => $ikinciYazim === 'bag' ? $this->yedekKoku.'/bag' : $disk.$ikinciYazim]]);
        $uzun = $this->plan($uzunHedef, 10);
        $kisa = $this->plan($kisaHedef, 1);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        foreach ([$eski, $yeni] as $y) {
            file_put_contents($disk.'/'.$y->dosya_adi, 'dump');
            $this->kopya($y, $uzunHedef, [$uzun]);
            $this->kopya($y, $kisaHedef, [$kisa]);
        }
        // Yalnız kısa kurallı hedefte kopyası olan dosya ortak değildir: kuralıyla silinir
        $tekHedefli = $this->yedek(7, YedekKaynagi::Plan);
        file_put_contents($disk.'/'.$tekHedefli->dosya_adi, 'dump');
        $this->kopya($tekHedefli, $kisaHedef, [$kisa]);

        $onizleme = app(SaklamaPolitikasi::class)->hesapla();
        $sonuc = app(SaklamaPolitikasi::class)->uygula();

        $this->assertFileExists($disk.'/'.$eski->dosya_adi);
        $this->assertFileExists($disk.'/'.$yeni->dosya_adi);
        $this->assertContains('ayni_depo', $onizleme['korunan'][$eski->id] ?? []);
        $this->assertSame([KopyaDurumu::Tamam, KopyaDurumu::Tamam], YedekKopyasi::query()->where('yedek_id', $eski->id)->orderBy('yedek_hedefi_id')->get()->map(fn (YedekKopyasi $k) => $k->durum)->all());
        $this->assertSame(YedekDurumu::Tamam, $eski->refresh()->durum);
        $kayit = YedekIslemKaydi::query()->where(['islem' => 'saklama_sil', 'yedek_id' => $eski->id, 'sonuc' => IslemSonucu::Basarisiz])->sole();
        $this->assertSame([HedefHatasi::AYNI_DEPO, $kisaHedef->id, [$uzunHedef->id]],
            [$kayit->ayrinti['kod'], $kayit->ayrinti['hedef_id'], $kayit->ayrinti['ayni_depo_hedefleri']]);
        $this->assertCount(1, $sonuc['bilgi']);
        $this->assertFileDoesNotExist($disk.'/'.$tekHedefli->dosya_adi);
        $this->assertSame(KopyaDurumu::Silindi, YedekKopyasi::query()->where('yedek_id', $tekHedefli->id)->sole()->durum);
    }

    /**
     * BY-010-R7: yedek klasörünü gösteren iki ana hedef (klasörü boş ve
     * noktalı yazımı). Kopyaları yerel dosyanın kendisidir: kısa kural yerel
     * dosyayı silince diğer ana hedefin kopyası yok olan dosyayı `tamam` tutardı.
     */
    public function test_yedek_klasorunu_gosteren_iki_ana_hedefte_saklama_yerel_dosyayi_silmez(): void
    {
        $uzunHedef = $this->anaYerelHedef();
        $kisaHedef = YedekHedefi::factory()->create(['ad' => 'Ana noktalı', 'baglanti' => ['klasor' => config('yedekleme.klasor').'/.']]);
        $uzun = $this->plan($uzunHedef, 10);
        $kisa = $this->plan($kisaHedef, 1);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        foreach ([$eski, $yeni] as $y) {
            $this->kopya($y, $uzunHedef, [$uzun]);
            $this->kopya($y, $kisaHedef, [$kisa]);
        }

        app(SaklamaPolitikasi::class)->uygula();

        $this->assertFileExists(config('yedekleme.klasor').'/'.$eski->dosya_adi);
        $this->assertNull($eski->refresh()->yerel_silinme_zamani);
        $this->assertSame([KopyaDurumu::Tamam, KopyaDurumu::Tamam], YedekKopyasi::query()->where('yedek_id', $eski->id)->orderBy('yedek_hedefi_id')->get()->map(fn (YedekKopyasi $k) => $k->durum)->all());
        $this->assertSame(HedefHatasi::AYNI_DEPO, YedekIslemKaydi::query()->where(['islem' => 'saklama_sil', 'yedek_id' => $eski->id, 'sonuc' => IslemSonucu::Basarisiz])->sole()->ayrinti['kod']);
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
    /**
     * YF-04: hiç kopya kaydı olmayan plan yedeğinin (planın bütün hedefleri
     * pasifti) yerel dosyası elle yedek kuralıyla silinir; süresiz birikmez.
     */
    public function test_kopya_kaydi_olmayan_plan_yedegine_elle_yedek_kurali_uygulanir(): void
    {
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $this->yedek(1, YedekKaynagi::Plan);

        $this->assertSame([$eski->id], $this->silinecekYedekler());
    }

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

    /** @return array<string, array{bool, string|null, bool}> anaKlasorHedefi, silinemeyenHedef (A hazır / B hazır değil), yerelDosyaKalir */
    public static function kismiUzakSilmeKurgulari(): array
    {
        return [
            'yedek klasörü yolu, hazır olmayan hedefte silme hatası' => [false, 'B', true],
            'yedek klasörü yolu, hazır hedefte silme hatası' => [false, 'A', false],
            'yedek klasörü yolu, tüm hedeflerde başarı' => [false, null, false],
            'ana klasör hedefi yolu, hazır olmayan hedefte silme hatası' => [true, 'B', true],
            'ana klasör hedefi yolu, hazır hedefte silme hatası' => [true, 'A', false],
            'ana klasör hedefi yolu, tüm hedeflerde başarı' => [true, null, false],
        ];
    }

    /**
     * BY-010-R5 (D4, F7): kurallar eski yedeğin iki uzak kopyasını da siliyor
     * (A hazır, B hazır değil). Yerel dosya uzak silmelerin GERÇEK sonucuna
     * göre silinir: B'nin silmesi düşerse geride hazır olmayan tek kopya
     * kalır, yerel dosya kalır; A'nınki düşerse hazır A kalır, yerel silinir;
     * hepsi silinirse saklama kararı geçerlidir (yedek her yerden gider).
     */
    #[DataProvider('kismiUzakSilmeKurgulari')]
    public function test_yerel_dosya_uzak_silmelerden_sonra_fiilen_kalan_kopyalara_gore_silinir(bool $anaKlasorHedefi, ?string $silinemeyen, bool $yerelDosyaKalir): void
    {
        $a = $this->bellekHedefi('A');
        $b = $this->bellekHedefi('B');
        $plan = $this->plan($a, 1);
        $plan->hedefler()->attach($b, ['sakla_son_adet' => 1, 'sakla_gun' => null]);
        $ana = $anaKlasorHedefi ? $this->anaYerelHedef() : null;
        if ($ana !== null) {
            $plan->hedefler()->attach($ana, ['sakla_son_adet' => 1, 'sakla_gun' => null]);
        }
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        $eskiKopyalar = [];
        if ($ana !== null) {
            $eskiKopyalar['ana'] = $this->kopya($eski, $ana, [$plan]);
        }
        $eskiKopyalar['A'] = $this->kopya($eski, $a, [$plan]);
        $eskiKopyalar['A']->update(['kurtarma_hazir' => true]);
        $eskiKopyalar['B'] = $this->kopya($eski, $b, [$plan]);
        foreach ([$a, $b, $ana] as $hedef) {
            if ($hedef !== null) {
                $this->kopya($yeni, $hedef, [$plan])->update(['kurtarma_hazir' => true]);
            }
        }
        if ($silinemeyen !== null) {
            $kopya = $eskiKopyalar[$silinemeyen];
            unset(BellekHedefi::$dosyalar[$kopya->uzak_kimlik]);
            $kopya->update(['uzak_kimlik' => $kopya->uzak_kimlik.'.silinemez']);
            BellekHedefi::$dosyalar[$kopya->uzak_kimlik] = 'dump';
            BellekHedefi::$silinemeyenAdSonu = '.silinemez';
        }
        $this->assertContains(
            SilmeKarari::DOSYA.':'.$eski->id.':'.($ana?->id ?? 'klasor').':-',
            array_map(fn (SilmeKarari $k): string => $k->anahtar(), app(SaklamaPolitikasi::class)->hesapla()['silinecek']),
            'Önizleme yerel dosyayı silinecek gösterir (bütün uzak silmeler başarılı varsayımı)',
        );

        $sonuc = app(SaklamaPolitikasi::class)->uygula();

        foreach (['A' => $a, 'B' => $b] as $ad => $hedef) {
            $kopya = $eskiKopyalar[$ad]->refresh();
            $kaldi = $silinemeyen === $ad;
            $this->assertSame($kaldi ? KopyaDurumu::Tamam : KopyaDurumu::Silindi, $kopya->durum, "{$ad} kopyası");
            $this->assertSame($kaldi, isset(BellekHedefi::$dosyalar[$kopya->uzak_kimlik]), "{$ad} hedefindeki dosya");
        }
        $this->assertSame($silinemeyen === null ? [] : [($silinemeyen === 'A' ? $a : $b)->id], $sonuc['atlanan_hedefler']);

        $yol = config('yedekleme.klasor').'/'.$eski->dosya_adi;
        $eski->refresh();
        $this->assertFileExists(ManifestDeposu::jsonYolu($yol));
        $korumaKaydi = YedekIslemKaydi::query()->where(['islem' => 'saklama_sil', 'yedek_id' => $eski->id, 'sonuc' => 'basarisiz'])
            ->get()->filter(fn (YedekIslemKaydi $k): bool => ($k->ayrinti['kod'] ?? null) === 'kurtarma_paketi_eksik');
        if ($yerelDosyaKalir) {
            $this->assertFileExists($yol);
            $this->assertSame([YedekDurumu::Tamam, null], [$eski->durum, $eski->yerel_silinme_zamani]);
            $this->assertCount(1, $korumaKaydi);
            $this->assertSame($ana?->id, $korumaKaydi->sole()->ayrinti['hedef_id']);
            $this->assertCount(1, array_filter($sonuc['bilgi'], fn (string $b): bool => str_contains($b, $eski->dosya_adi)));
            if ($ana !== null) {
                $this->assertSame(KopyaDurumu::Tamam, $eskiKopyalar['ana']->refresh()->durum);
            }
        } else {
            $this->assertFileDoesNotExist($yol);
            $this->assertNotNull($eski->yerel_silinme_zamani);
            $this->assertCount(0, $korumaKaydi);
            // Hazır A kopyası kaldıysa yedek durur; hiçbir kopya kalmadıysa saklama yedeği bitirir (F7)
            $this->assertSame($silinemeyen === null ? YedekDurumu::Silindi : YedekDurumu::Tamam, $eski->durum);
            if ($ana !== null) {
                $this->assertSame(KopyaDurumu::Silindi, $eskiKopyalar['ana']->refresh()->durum);
            }
        }
        $this->assertSame($silinemeyen === null ? 0 : 1, YedekIslemKaydi::query()->where(['islem' => 'saklama_sil', 'yedek_id' => $eski->id, 'sonuc' => 'basarisiz'])
            ->get()->filter(fn (YedekIslemKaydi $k): bool => ($k->ayrinti['kod'] ?? null) === HedefHatasi::SILINEMEDI)->count());
    }

    /**
     * BY-010-R5: kurallar yedeğin bütün uzak kopyalarını siliyorsa ve silmeler
     * gerçekten başarılıysa saklama kararı geçerlidir — silinen kopyalar hazır
     * olmasa da yerel dosya da gider (önceki ölçüt onu korur, sonraki turda
     * uzak kopyasız kalan dosya hiçbir kuralla silinemezdi).
     */
    public function test_butun_uzak_kopyalari_gercekten_silinen_yedegin_yerel_dosyasi_hazirliktan_bagimsiz_silinir(): void
    {
        $a = $this->bellekHedefi('A');
        $b = $this->bellekHedefi('B');
        $plan = $this->plan($a, 1);
        $plan->hedefler()->attach($b, ['sakla_son_adet' => 1, 'sakla_gun' => null]);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        $this->kopya($eski, $a, [$plan]);
        $this->kopya($eski, $b, [$plan]);
        $this->kopya($yeni, $a, [$plan])->update(['kurtarma_hazir' => true]);
        $this->kopya($yeni, $b, [$plan])->update(['kurtarma_hazir' => true]);

        app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame(0, YedekKopyasi::query()->where('yedek_id', $eski->id)->where('durum', KopyaDurumu::Tamam->value)->count());
        $this->assertFileDoesNotExist(config('yedekleme.klasor').'/'.$eski->dosya_adi);
        $this->assertSame(YedekDurumu::Silindi, $eski->refresh()->durum);
    }

    /** @return array<string, array{bool, bool}> anaKlasorHedefi, hazirTamamKopyaKalir */
    public static function konumuBelirsizKalanKopyaKurgulari(): array
    {
        return [
            'yedek klasörü yolu, yalnız konumu belirsiz kopya kalır' => [false, false],
            'yedek klasörü yolu, konumu belirsiz + hazır tamam kopya kalır' => [false, true],
            'ana klasör hedefi yolu, yalnız konumu belirsiz kopya kalır' => [true, false],
            'ana klasör hedefi yolu, konumu belirsiz + hazır tamam kopya kalır' => [true, true],
        ];
    }

    /**
     * BY-010-R5 / G21 (kullanılamaz ≠ yok): A hedefi eski yedeğin hazır kopyasını
     * siler; yeri doğrulanamayan kopya kalan kopyadır ama kurtarma dayanağı
     * değildir. Yalnız o kalırsa yerel dosya kalır; yanında hazır tamam kopya
     * (B) kalırsa yerel dosya silinir.
     */
    #[DataProvider('konumuBelirsizKalanKopyaKurgulari')]
    public function test_yalniz_konumu_belirsiz_kopya_kalan_yedegin_yerel_dosyasi_silinmez(bool $anaKlasorHedefi, bool $hazirTamamKopyaKalir): void
    {
        $a = $this->bellekHedefi('A');
        $plan = $this->plan($a, 1);
        $b = $hazirTamamKopyaKalir ? $this->bellekHedefi('B') : null;
        if ($b !== null) {
            $plan->hedefler()->attach($b, ['sakla_son_adet' => 2, 'sakla_gun' => null]);
        }
        $ana = $anaKlasorHedefi ? $this->anaYerelHedef() : null;
        if ($ana !== null) {
            $plan->hedefler()->attach($ana, ['sakla_son_adet' => 1, 'sakla_gun' => null]);
        }
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        foreach ([$eski, $yeni] as $y) {
            foreach ([$a, $b, $ana] as $hedef) {
                if ($hedef !== null) {
                    $this->kopya($y, $hedef, [$plan])->update(['kurtarma_hazir' => true]);
                }
            }
        }
        $belirsiz = $this->kopya($eski, $this->bellekHedefi('Konumu belirsiz'), durum: KopyaDurumu::KonumBelirsiz);
        $onizleme = app(SaklamaPolitikasi::class)->hesapla();

        app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame(KopyaDurumu::Silindi, YedekKopyasi::query()->where(['yedek_id' => $eski->id, 'yedek_hedefi_id' => $a->id])->sole()->durum);
        $this->assertSame(KopyaDurumu::KonumBelirsiz, $belirsiz->refresh()->durum);
        $yol = config('yedekleme.klasor').'/'.$eski->dosya_adi;
        $eski->refresh();
        $this->assertSame(YedekDurumu::Tamam, $eski->durum);
        if ($hazirTamamKopyaKalir) {
            $this->assertFileDoesNotExist($yol);
            $this->assertNotNull($eski->yerel_silinme_zamani);
        } else {
            $this->assertFileExists($yol);
            $this->assertNull($eski->yerel_silinme_zamani);
            $this->assertContains('kurtarma_paketi_eksik', $onizleme['korunan'][$eski->id]);
            if ($ana !== null) {
                $this->assertSame(KopyaDurumu::Tamam, YedekKopyasi::query()->where(['yedek_id' => $eski->id, 'yedek_hedefi_id' => $ana->id])->sole()->durum);
            }
        }
    }

    /** @return array<string, array{bool}> */
    public static function anaHedefSiralari(): array
    {
        return ['ana klasör hedefi önce işlenir' => [true], 'ana klasör hedefi sonra işlenir' => [false]];
    }

    /**
     * BY-010-R6: yerel dosyanın korumaları (uzak kopyası başarısız →
     * `uzak_kopya_dogrulanmadi`) ve ana klasör grubunun en az sayısı uzak
     * hedeflerin kararına sızmaz; uzak kararlar grupların işlenme sırasına
     * (hedef / kopya kimliği) bağlı değildir.
     */
    #[DataProvider('anaHedefSiralari')]
    public function test_yerel_korumalar_uzak_hedef_kararlarini_islenme_sirasina_gore_degistirmez(bool $anaOnce): void
    {
        $ana = $anaOnce ? $this->anaYerelHedef() : null;
        $uzak = $this->bellekHedefi('Uzak');
        $ana ??= $this->anaYerelHedef();
        $plan = $this->plan($anaOnce ? $ana : $uzak, 1);
        $plan->hedefler()->attach($anaOnce ? $uzak : $ana, ['sakla_son_adet' => 1, 'sakla_gun' => null]);
        $eski = $this->yedek(5, YedekKaynagi::Plan);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        foreach ($anaOnce ? [$ana, $uzak] : [$uzak, $ana] as $hedef) {
            foreach ([$eski, $yeni] as $y) {
                $this->kopya($y, $hedef, [$plan])->update(['kurtarma_hazir' => true]);
            }
        }
        $this->kopya($eski, $this->bellekHedefi('Başarısız'), durum: KopyaDurumu::Basarisiz);
        $this->assertSame($anaOnce, $ana->id < $uzak->id);

        $hesap = app(SaklamaPolitikasi::class)->hesapla();

        $uzakKararlari = array_values(array_map(fn (SilmeKarari $k): string => $k->anahtar(), array_filter($hesap['silinecek'], fn (SilmeKarari $k): bool => $k->hedefId === $uzak->id)));
        $this->assertEqualsCanonicalizing([
            SilmeKarari::BAG.':'.$eski->id.':'.$uzak->id.':'.$plan->id,
            SilmeKarari::DOSYA.':'.$eski->id.':'.$uzak->id.':-',
        ], $uzakKararlari);
        $this->assertSame([], array_values(array_filter($hesap['silinecek'], fn (SilmeKarari $k): bool => $k->tur === SilmeKarari::DOSYA && $k->hedefId === $ana->id)));
        $this->assertContains('uzak_kopya_dogrulanmadi', $hesap['korunan'][$eski->id]);
    }

    /** @return array<string, array{KopyaDurumu, bool}> yeniYedeginKopyasi, yeniYedekKorunur */
    public static function sonBasariliKurgulari(): array
    {
        return [
            'yerel arşivi yok, yalnız konumu belirsiz kopyası var' => [KopyaDurumu::KonumBelirsiz, false],
            'F7: yerel arşivi silinmiş, tamam uzak kopyası var' => [KopyaDurumu::Tamam, true],
        ];
    }

    /**
     * BY-028 beşinci tur notu: "son başarılı" koruması bugün kullanılabilen en
     * yeni yedeğe verilir (yerel arşivi ya da tamam kopyası olan). Yalnız
     * konumu belirsiz kopyası kalan yedek geçmişte başarılıdır (durum `tamam`,
     * temkin yok) ama korumayı almaz: daha eski kullanılabilir yedek korunur.
     * F7 yedeği (yerel silinmiş, tamam uzak kopyalı) korumayı alır.
     */
    #[DataProvider('sonBasariliKurgulari')]
    public function test_son_basarili_korumasi_kullanilabilir_kopyasi_olan_en_yeni_yedege_verilir(KopyaDurumu $kopyaDurumu, bool $yeniKorunur): void
    {
        $this->yedek(10, ek: ['sabit' => true]);
        $eski = $this->yedek(5);
        $yeni = $this->yedek(1, YedekKaynagi::Plan);
        unlink(config('yedekleme.klasor').'/'.$yeni->dosya_adi);
        $yeni->update(['yerel_silinme_zamani' => CarbonImmutable::now()]);
        $yeniKopya = $this->kopya($yeni, $this->bellekHedefi('Uzak'), durum: $kopyaDurumu);

        $hesap = app(SaklamaPolitikasi::class)->hesapla();
        app(SaklamaPolitikasi::class)->uygula();

        $this->assertNull($hesap['temkin']);
        $this->assertSame(YedekDurumu::Tamam, $yeni->refresh()->durum);
        $this->assertSame($kopyaDurumu, $yeniKopya->refresh()->durum);
        $eskiYol = config('yedekleme.klasor').'/'.$eski->dosya_adi;
        if ($yeniKorunur) {
            $this->assertContains('son_basarili', $hesap['korunan'][$yeni->id]);
            $this->assertNotContains('son_basarili', $hesap['korunan'][$eski->id] ?? []);
            $this->assertFileDoesNotExist($eskiYol);
        } else {
            $this->assertNotContains('son_basarili', $hesap['korunan'][$yeni->id] ?? []);
            $this->assertContains('son_basarili', $hesap['korunan'][$eski->id]);
            $this->assertFileExists($eskiYol);
            $this->assertSame(YedekDurumu::Tamam, $eski->refresh()->durum);
        }
    }

    /**
     * G1 (aynı kalıp): "doğrulanmış en yeni" koruması da kullanılabilir kopyası
     * olmayan (yalnız konumu belirsiz kopyalı) yedeğe verilmez; sınaması geçmiş
     * daha eski kullanılabilir yedek korunur.
     */
    public function test_dogrulanmis_en_yeni_korumasi_kullanilabilir_kopyasi_olmayan_yedege_verilmez(): void
    {
        $this->yedek(10, ek: ['sabit' => true]);
        $dogrulanmis = $this->yedek(5, ek: ['dogrulama_durumu' => DogrulamaDurumu::SinamaGecti]);
        $this->yedek(2);
        $belirsiz = $this->yedek(1, YedekKaynagi::Plan, ['dogrulama_durumu' => DogrulamaDurumu::SinamaGecti]);
        unlink(config('yedekleme.klasor').'/'.$belirsiz->dosya_adi);
        $belirsiz->update(['yerel_silinme_zamani' => CarbonImmutable::now()]);
        $this->kopya($belirsiz, $this->bellekHedefi('Uzak'), durum: KopyaDurumu::KonumBelirsiz);

        $hesap = app(SaklamaPolitikasi::class)->hesapla();
        app(SaklamaPolitikasi::class)->uygula();

        $this->assertContains('dogrulanmis_en_yeni', $hesap['korunan'][$dogrulanmis->id]);
        $this->assertNotContains('dogrulanmis_en_yeni', $hesap['korunan'][$belirsiz->id] ?? []);
        $this->assertFileExists(config('yedekleme.klasor').'/'.$dogrulanmis->dosya_adi);
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

    /**
     * Canlı 2026-11-22…27: hiç yedek alınmamış kurulumda yedek klasörü yoktur
     * (ilk yedek açar). Silinecek bir şey yokken temizlik depoya dokunmaz,
     * klasörü açmaz ve başarılı biter (önce işaret yazımında her gece düşüyordu).
     */
    public function test_hic_yedek_yokken_klasor_acilmamissa_temizlik_basarili_biter_ve_depoya_dokunmaz(): void
    {
        $klasor = config('yedekleme.klasor');
        unlink($klasor.'/'.YedekDeposu::ISARET);
        rmdir($klasor);
        config(['yedekleme.aktif' => true]);

        $this->artisan('yedek:temizle')->assertSuccessful();

        $this->assertDirectoryDoesNotExist($klasor);
        $this->assertSame(0, YedekIslemKaydi::query()->where('islem', 'saklama_sil')->count());
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
