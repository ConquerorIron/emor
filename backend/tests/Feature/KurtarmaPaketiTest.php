<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KopyaSifreleyici;
use App\Services\Yedekleme\KurtarmaPaketi;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\YedekImzasi;
use App\Services\Yedekleme\YedekKopyalayici;
use App\Services\Yedekleme\YedekOzeti;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\GpgAnahtarlari;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-024: olay günlüğü, ayar kopyası, denetim günlüğü ve manifestlerin sunucu
 * dışı hedefe şifreli, sürümlü bir paket olarak gitmesi (README §4.6 son madde,
 * §9 "Kurtarma paketi"; BY-024-R1).
 */
final class KurtarmaPaketiTest extends TestCase
{
    use GpgAnahtarlari;
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const BILESENLER = [KurtarmaPaketi::OLAYLAR, KurtarmaPaketi::AYARLAR, KurtarmaPaketi::DENETIM, KurtarmaPaketi::MANIFESTLER];

    private string $gunlukKlasoru;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gpgAnahtarlariniHazirla();
        $this->yedeklemeOrtaminiKur();
        BellekHedefi::sifirla();
        BellekHedefi::$sunucuDisi = true;
        Sleep::fake();
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);

        // Denetim günlüğü geçici klasörde; iki eski gün dosyası hazır
        $this->gunlukKlasoru = $this->yedekKoku.'/gunluk';
        mkdir($this->gunlukKlasoru, 0750);
        file_put_contents($this->gunlukKlasoru.'/yedekleme-2025-01-01.log', "[2025-01-01] eski gun 1\n");
        file_put_contents($this->gunlukKlasoru.'/yedekleme-2025-01-02.log', "[2025-01-02] eski gun 2\n");
        config(['logging.channels.yedekleme.path' => $this->gunlukKlasoru.'/yedekleme.log']);
        Log::forgetChannel('yedekleme');

        $bilgi = app(KopyaSifreleyici::class)->acikAnahtarBilgisi(self::$anahtarlar['a']['acik']);
        YedekAyari::query()->create(['sifreleme_acik_anahtari' => self::$anahtarlar['a']['acik'], 'sifreleme_parmak_izi' => $bilgi['parmak_izi']]);
        app(AyarKopyasi::class)->yaz();
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('yedekleme');
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /** Yedek klasöründe dump'ı ve kendisine ait imzalı manifesti olan yedek (T10: geçerli paket bileşeni) */
    private function yedek(string $icerik = 'PGDMP-yedek'): Yedek
    {
        $yedek = Yedek::factory()->create(['sha256' => hash('sha256', $icerik), 'boyut' => strlen($icerik)]);
        file_put_contents(config('yedekleme.klasor').'/'.$yedek->dosya_adi, $icerik);
        $this->manifestYaz($yedek);

        return $yedek;
    }

    /** Yedeğin adıyla imzalı manifest yazar; kimlik / özet verilirse manifestte onlar durur */
    private function manifestYaz(Yedek $yedek, ?string $kimlik = null, ?string $sha256 = null): string
    {
        return app(ManifestDeposu::class)->yaz(
            YedekManifestiTest::manifest(kimlik: $kimlik ?? $yedek->kimlik, sha256: $sha256 ?? (string) $yedek->sha256, boyut: (int) $yedek->boyut, dosyaAdi: $yedek->dosya_adi),
            config('yedekleme.klasor'),
        );
    }

    private function manifestAdi(Yedek $yedek): string
    {
        return preg_replace('/\.dump$/D', '.json', $yedek->dosya_adi);
    }

    private function bellekHedefi(): YedekHedefi
    {
        return YedekHedefi::factory()->create(['tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
    }

    /** Uzak içeriği özel anahtarla çözer */
    private function coz(string $sifreli): string
    {
        $giris = $this->yedekKoku.'/indirilen-'.bin2hex(random_bytes(3));
        file_put_contents($giris, $sifreli);
        app(KopyaSifreleyici::class)->coz($giris, $giris.'.acik', $this->ozelAnahtarDosyasi('a', $this->yedekKoku));

        return (string) file_get_contents($giris.'.acik');
    }

    /**
     * Hedefteki tamam işaretçileri, ada göre sıralı (sonuncusu = yetkili paket).
     *
     * @return list<array{surum: string, dosyalar: list<array{ad: string, sha256: string, boyut: int}>}>
     */
    private function isaretciler(YedekHedefi $hedef): array
    {
        // Üstüne yazmayan hedefte kimliğin sonunda "#<sıra>" var
        $adlar = array_filter(array_keys(BellekHedefi::$dosyalar), fn (string $k): bool => preg_match('/-'.preg_quote(KurtarmaPaketi::ISARETCI, '/').'(#\d+)?$/D', $k) === 1);
        sort($adlar, SORT_STRING);

        return array_map(fn (string $k): array => json_decode(BellekHedefi::$dosyalar[$k], true, 8, JSON_THROW_ON_ERROR), $adlar);
    }

    /**
     * Kurtarmadaki gibi: işaretçideki her dosya hedefte var, özeti tutuyor;
     * çözülmüş içerikler bileşen adıyla.
     *
     * @param  array{surum: string, dosyalar: list<array{ad: string, sha256: string, boyut: int}>}  $isaretci
     * @return array<string, string>
     */
    private function paketiAc(YedekHedefi $hedef, array $isaretci): array
    {
        $acik = [];
        foreach ($isaretci['dosyalar'] as $dosya) {
            $uzak = BellekHedefi::$dosyalar[$hedef->id.'/'.$dosya['ad']];
            $this->assertSame($dosya['sha256'], hash('sha256', $uzak));
            $this->assertSame($dosya['boyut'], strlen($uzak));
            $acik[substr($dosya['ad'], strlen(KurtarmaPaketi::uzakAd($isaretci['surum'], '')))] = $this->coz($uzak);
        }

        return $acik;
    }

    public function test_sunucu_disi_kopyadan_sonra_olay_ayar_denetim_ve_manifestler_ayni_anahtarla_sifreli_gider(): void
    {
        $yedek = $this->yedek();
        $hedef = $this->bellekHedefi();

        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);

        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        [$isaretci] = $this->isaretciler($hedef);
        $acik = $this->paketiAc($hedef, $isaretci);
        $this->assertSame(self::BILESENLER, array_keys($acik));

        $this->assertStringNotContainsString($yedek->kimlik, BellekHedefi::$dosyalar[$hedef->id.'/'.KurtarmaPaketi::uzakAd($isaretci['surum'], KurtarmaPaketi::OLAYLAR)]);
        $this->assertSame(file_get_contents(app(OlayGunlugu::class)->yol()), $acik[KurtarmaPaketi::OLAYLAR]);
        $this->assertStringContainsString($yedek->kimlik, $acik[KurtarmaPaketi::OLAYLAR]);
        $this->assertSame(file_get_contents(config('yedekleme.ayar_kopyasi')), $acik[KurtarmaPaketi::AYARLAR]);
        $this->assertStringStartsWith("[2025-01-01] eski gun 1\n[2025-01-02] eski gun 2\n", $acik[KurtarmaPaketi::DENETIM]);
        $this->assertStringContainsString('yedekleme.kopya', $acik[KurtarmaPaketi::DENETIM]);

        // Manifest arşivi yedek klasörüne göre göreli adlarla açılır
        $arsiv = $this->yedekKoku.'/manifestler.tar';
        file_put_contents($arsiv, $acik[KurtarmaPaketi::MANIFESTLER]);
        $acilan = $this->yedekKoku.'/acilan';
        mkdir($acilan);
        (new Process(['/usr/bin/tar', '-xf', $arsiv, '-C', $acilan]))->mustRun();
        $this->assertSame(['.', '..', $this->manifestAdi($yedek)], scandir($acilan));
        $this->assertFileEquals(config('yedekleme.klasor').'/'.$this->manifestAdi($yedek), $acilan.'/'.$this->manifestAdi($yedek));

        $kayit = YedekIslemKaydi::query()->where('islem', 'kurtarma_paketi')->sole();
        $this->assertSame(IslemSonucu::Basarili, $kayit->sonuc);
        $this->assertSame(self::BILESENLER, $kayit->ayrinti['gonderilen']);
        $this->assertSame($isaretci['surum'], $kayit->ayrinti['surum']);
    }

    public function test_kurtarma_dosyalari_hedefin_yedek_listesine_girmez(): void
    {
        $klasor = $this->yedekKoku.'/ikinci-disk';
        mkdir($klasor, 0750);
        $hedef = YedekHedefi::factory()->create(['baglanti' => ['klasor' => $klasor]]);
        $yedek = $this->yedek();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);

        $this->assertTrue(app(KurtarmaPaketi::class)->gonder($hedef));

        $paket = array_values(array_filter(scandir($klasor) ?: [], fn (string $ad): bool => str_starts_with($ad, KurtarmaPaketi::ON_EK)));
        $this->assertCount(5, $paket);
        $this->assertEqualsCanonicalizing([$yedek->dosya_adi, ...$paket], array_values(array_diff(scandir($klasor) ?: [], ['.', '..'])));
        $this->assertSame([$yedek->dosya_adi], app(HedefKatalogu::class)->olustur($hedef)->listele());
    }

    public function test_kurtarma_dosyasi_gonderilemezse_kopya_yine_tamam_hata_denetime_yazilir(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root izin kısıtını yok sayar; okunamayan dosya kurulamaz.');
        }

        chmod($this->gunlukKlasoru.'/yedekleme-2025-01-02.log', 0000);
        $yedek = $this->yedek();

        try {
            $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $this->bellekHedefi());
        } finally {
            chmod($this->gunlukKlasoru.'/yedekleme-2025-01-02.log', 0640);
        }

        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame(1, $kopya->deneme);
        $kayit = YedekIslemKaydi::query()->where('islem', 'kurtarma_paketi')->sole();
        $this->assertSame(IslemSonucu::Basarisiz, $kayit->sonuc);
        $this->assertStringContainsString('yedekleme-2025-01-02.log', (string) $kayit->hata_mesaji);
        $this->assertSame(IslemSonucu::Basarili, YedekIslemKaydi::query()->where('islem', 'kopya')->sole()->sonuc);
    }

    /**
     * BY-024-R1: ara bileşen yazılamazsa yeni sürüm yarım kalır ve silinir;
     * önceki doğrulanmış paket ve işaretçisi aynen durur, ondan kurtarılır.
     * Uzak yaşam sinyali yalnız paket tamamlanınca gider.
     */
    public function test_ara_bilesen_yazilamazsa_onceki_paket_yetkili_kalir_yasam_sinyali_gitmez(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://izleyici.example.invalid/uzak' => Http::response()]);
        YedekAyari::guncel()->update(['sinyal_uzak_adresi' => 'https://izleyici.example.invalid/uzak']);
        $hedef = $this->bellekHedefi();
        $ilk = $this->yedek('PGDMP-ilk');
        app(YedekKopyalayici::class)->kopyala($ilk, $hedef);
        Http::assertSentCount(1);
        $oncekiDosyalar = BellekHedefi::$dosyalar;
        [$onceki] = $this->isaretciler($hedef);

        BellekHedefi::$yazilamayanAdSonu = '-'.KurtarmaPaketi::DENETIM;
        $ikinci = $this->yedek('PGDMP-ikinci');
        $kopya = app(YedekKopyalayici::class)->kopyala($ikinci, $hedef);

        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        Http::assertSentCount(1);
        // Yeni sürümden geriye dosya kalmadı; hedefte yalnız iki yedek ve önceki paket
        $this->assertEqualsCanonicalizing([...array_keys($oncekiDosyalar), $kopya->uzak_kimlik], array_keys(BellekHedefi::$dosyalar));
        $this->assertSame([$onceki], $this->isaretciler($hedef));
        $acik = $this->paketiAc($hedef, $onceki);
        $this->assertSame(self::BILESENLER, array_keys($acik));
        $this->assertStringContainsString($ilk->kimlik, $acik[KurtarmaPaketi::OLAYLAR]);
        $kayit = YedekIslemKaydi::query()->where('islem', 'kurtarma_paketi')->latest('id')->first();
        $this->assertSame(IslemSonucu::Basarisiz, $kayit->sonuc);
        $this->assertSame([KurtarmaPaketi::OLAYLAR, KurtarmaPaketi::AYARLAR], $kayit->ayrinti['gonderilen']);
    }

    /**
     * Değiştirilemez saklamalı hedef (S3 nesne kilidi) eski sürümü silmeye izin
     * vermez: gönderim yine başarılıdır, sürüm kaydı her gönderimde büyümez.
     */
    public function test_silme_yetkisi_olmayan_hedefte_paket_basarili_surum_kaydi_buyumez(): void
    {
        BellekHedefi::$hataAdimi = 'sil';
        BellekHedefi::$hataKodu = HedefHatasi::SILME_YETKISI_YOK;
        $hedef = $this->bellekHedefi();
        $paket = app(KurtarmaPaketi::class);
        @mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(app(OlayGunlugu::class)->yol(), "{}\n");

        for ($i = 0; $i < 4; $i++) {
            $this->assertTrue($paket->gonder($hedef));
            $this->travel(1)->seconds();
        }

        $kayit = json_decode((string) file_get_contents(config('yedekleme.durum_klasoru').'/kurtarma-paketleri.json'), true);
        $this->assertCount(2, $kayit['hedefler'][$hedef->id]);
        $this->assertSame(0, YedekIslemKaydi::query()->where('islem', 'kurtarma_paketi')->latest('id')->first()->ayrinti['silinemeyen_eski_surum']);
    }

    /**
     * Google Drive gibi üstüne yazmayan hedefte de: son iki tamam sürüm kalır,
     * eskiler imzalı sürüm kaydından bulunup silinir; kayıt bozuksa hiçbir şey silinmez.
     */
    public function test_son_iki_tamam_surum_kalir_eskiler_silinir_kayit_bozuksa_silinmez(): void
    {
        BellekHedefi::$herYazimdaYeniKimlik = true;
        $hedef = $this->bellekHedefi();
        $paket = app(KurtarmaPaketi::class);
        @mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(app(OlayGunlugu::class)->yol(), "{}\n");

        $this->assertTrue($paket->gonder($hedef));
        $ilk = array_keys(BellekHedefi::$dosyalar);
        $this->travel(1)->seconds();
        $this->assertTrue($paket->gonder($hedef));
        $this->travel(1)->seconds();
        $this->assertTrue($paket->gonder($hedef));

        // Her sürüm: 3 bileşen (manifest yok) + işaretçi
        $this->assertCount(8, BellekHedefi::$dosyalar);
        $this->assertSame([], array_intersect($ilk, array_keys(BellekHedefi::$dosyalar)));
        $this->assertCount(2, $this->isaretciler($hedef));

        // İmzası tutmayan kayıt bir yedeği gösteriyor: hiçbir uzak dosya silinmez
        $yedekKimligi = $hedef->id.'/erp_elle_20260101_000000.dump.gpg';
        BellekHedefi::$dosyalar[$yedekKimligi] = 'sifreli-yedek';
        file_put_contents(config('yedekleme.durum_klasoru').'/kurtarma-paketleri.json', json_encode(['hedefler' => [$hedef->id => [
            ['surum' => '20000101T000000Z-000000', 'tamam' => false, 'kimlikler' => [$yedekKimligi], 'isaretci' => null],
        ]], 'imza' => str_repeat('0', 64)]));
        $this->travel(1)->seconds();
        $this->assertTrue($paket->gonder($hedef));

        $this->assertArrayHasKey($yedekKimligi, BellekHedefi::$dosyalar);
        $this->assertCount(13, BellekHedefi::$dosyalar);
    }

    /**
     * BY-010-R2, BY-024-R3: aktarımı doğrulanan şifreli kopya, o hedefe yedeğin
     * manifestini taşıyan paket doğrulanmadan kurtarmaya hazır değildir; tek
     * uzak hedefli planda yerel arşiv silinmez. Sonraki başarılı paket kopyayı
     * hazır yapar, temizlik yereli siler ve uzaktaki tam setle (paketten çıkan
     * imzalı manifest + şifreli dump) SQL'den önce imza doğrulanır.
     */
    public function test_paketi_eksik_sifreli_kopya_yerel_arsivi_tutar_paket_dogrulaninca_temizlik_siler_set_imzayi_dogrular(): void
    {
        $icerik = 'PGDMP-plan-yedegi';
        $yedek = Yedek::factory()->plan()->create(['sha256' => hash('sha256', $icerik), 'boyut' => strlen($icerik), 'alinma_zamani' => now()->subHour()]);
        $klasor = config('yedekleme.klasor');
        @mkdir($klasor, 0750, true);
        file_put_contents($klasor.'/'.$yedek->dosya_adi, $icerik);
        app(ManifestDeposu::class)->yaz(YedekManifestiTest::manifest(kimlik: $yedek->kimlik, sha256: hash('sha256', $icerik), boyut: strlen($icerik), dosyaAdi: $yedek->dosya_adi), $klasor);
        $hedef = $this->bellekHedefi();
        $plan = YedekPlani::factory()->create();
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => 14]);
        BellekHedefi::$yazilamayanAdSonu = '-'.KurtarmaPaketi::DENETIM;

        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef, [$plan->id]);
        $onizleme = app(SaklamaPolitikasi::class)->hesapla();
        app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertFalse($kopya->refresh()->kurtarma_hazir);
        $this->assertSame([], $this->isaretciler($hedef));
        $this->assertContains('kurtarma_paketi_eksik', $onizleme['korunan'][$yedek->id]);
        $this->assertFileExists($klasor.'/'.$yedek->dosya_adi);
        $this->assertNull($yedek->refresh()->yerel_silinme_zamani);
        $this->assertNull(app(YedekOzeti::class)->hesapla()['son_uzak']);

        BellekHedefi::$yazilamayanAdSonu = null;
        $this->assertTrue(app(KurtarmaPaketi::class)->gonder($hedef));
        app(SaklamaPolitikasi::class)->uygula();

        $this->assertTrue($kopya->refresh()->kurtarma_hazir);
        $this->assertFileDoesNotExist($klasor.'/'.$yedek->dosya_adi);
        $this->assertFileExists(ManifestDeposu::jsonYolu($klasor.'/'.$yedek->dosya_adi));
        $this->assertNotNull($yedek->refresh()->yerel_silinme_zamani);
        $this->assertNotNull(app(YedekOzeti::class)->hesapla()['son_uzak']);

        // Boş sunucudaki gibi: yalnız uzaktakilerle (işaretçi → manifest arşivi + şifreli dump)
        [$isaretci] = $this->isaretciler($hedef);
        $acik = $this->paketiAc($hedef, $isaretci);
        $kurtarma = $this->yedekKoku.'/kurtarma';
        mkdir($kurtarma.'/m', 0700, true);
        file_put_contents($kurtarma.'/manifestler.tar', $acik[KurtarmaPaketi::MANIFESTLER]);
        (new Process(['/usr/bin/tar', '-xf', $kurtarma.'/manifestler.tar', '-C', $kurtarma.'/m']))->mustRun();
        file_put_contents($kurtarma.'/'.$yedek->dosya_adi, $this->coz(BellekHedefi::$dosyalar[(string) $kopya->uzak_kimlik]));

        $this->artisan('yedek:manifest-dogrula', ['dump' => $kurtarma.'/'.$yedek->dosya_adi, '--manifest' => ManifestDeposu::jsonYolu($kurtarma.'/m/'.$yedek->dosya_adi)])
            ->expectsOutputToContain('DOGRULANDI')
            ->assertSuccessful();
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function isaretciSilinemeyenHedefler(): array
    {
        return [
            // Kalıcı ret: set hedefin kendi kuralına bırakılır, kayıt büyümez (D8)
            'silme yetkisi yok' => [HedefHatasi::SILME_YETKISI_YOK, 2],
            // Geçici hata: sürüm kayıtta kalır, sonraki gönderimde yeniden denenir
            'geçici silme hatası' => [HedefHatasi::SILINEMEDI, 3],
        ];
    }

    /**
     * BY-024-R4: eski sürümün işaretçisi silinemezse (kova izni ya da nesne
     * koruması yalnız işaretçide) bileşenleri de silinmez: hedefte "tamam"
     * görünen her set eksiksiz ve açılabilir kalır.
     */
    #[DataProvider('isaretciSilinemeyenHedefler')]
    public function test_isaretcisi_silinemeyen_eski_surumun_hicbir_bileseni_silinmez(string $kod, int $kayittakiSurum): void
    {
        BellekHedefi::$silinemeyenAdSonu = '-'.KurtarmaPaketi::ISARETCI;
        BellekHedefi::$hataKodu = $kod;
        $hedef = $this->bellekHedefi();
        $paket = app(KurtarmaPaketi::class);
        @mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(app(OlayGunlugu::class)->yol(), "{}\n");

        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($paket->gonder($hedef));
            $this->travel(1)->seconds();
        }

        $isaretciler = $this->isaretciler($hedef);
        $this->assertCount(3, $isaretciler);
        foreach ($isaretciler as $isaretci) {
            $this->assertSame([KurtarmaPaketi::OLAYLAR, KurtarmaPaketi::AYARLAR, KurtarmaPaketi::DENETIM], array_keys($this->paketiAc($hedef, $isaretci)));
        }
        $this->assertCount(12, BellekHedefi::$dosyalar);
        $kayit = json_decode((string) file_get_contents(config('yedekleme.durum_klasoru').'/kurtarma-paketleri.json'), true);
        $this->assertCount($kayittakiSurum, $kayit['hedefler'][$hedef->id]);
    }

    /**
     * @return array<string, array{\Closure(self, Yedek): void}>
     */
    public static function gecersizManifestler(): array
    {
        return [
            'imzası bozuk (içerik imzadan sonra değişmiş)' => [function (self $test, Yedek $yedek): void {
                $yol = $test->manifestYaz($yedek);
                $veri = json_decode((string) file_get_contents($yol), true, 32, JSON_THROW_ON_ERROR);
                $veri['manifest']['aciklama'] = 'imzadan sonra değişti';
                file_put_contents($yol, json_encode($veri, JSON_THROW_ON_ERROR));
            }],
            'doğru imzalı ama başka yedeğin manifesti (aynı adla)' => [function (self $test, Yedek $yedek): void {
                $baska = Yedek::factory()->create();
                rename($test->manifestYaz($baska), ManifestDeposu::jsonYolu(config('yedekleme.klasor').'/'.$yedek->dosya_adi));
            }],
            'doğru imzalı, kimliği aynı ama özeti katalogla uyuşmayan' => [function (self $test, Yedek $yedek): void {
                $test->manifestYaz($yedek, sha256: hash('sha256', 'baska-dump'));
            }],
        ];
    }

    /**
     * BY-024-R5, BY-010-R3: hazırlık arşive giren manifestin geçerli imzasına,
     * yedek kimliğine ve SHA-256 özetine bağlıdır; adı tutması yetmez. Aynı
     * hedefe giden başarılı pakette geçersiz manifestli kopya hazır olmaz
     * (geçerli manifestli kopya olur), tek uzak hedefli planda yerel arşiv
     * silinmez.
     *
     * @param  \Closure(self, Yedek): void  $boz
     */
    #[DataProvider('gecersizManifestler')]
    public function test_gecersiz_manifestli_kopya_basarili_paketle_kurtarmaya_hazir_olmaz_yerel_arsiv_silinmez(\Closure $boz): void
    {
        $hedef = $this->bellekHedefi();
        $gecerliKopya = app(YedekKopyalayici::class)->kopyala($this->yedek('PGDMP-gecerli'), $hedef);
        $icerik = 'PGDMP-plan-yedegi';
        $yedek = Yedek::factory()->plan()->create(['sha256' => hash('sha256', $icerik), 'boyut' => strlen($icerik), 'alinma_zamani' => now()->subHour()]);
        $klasor = config('yedekleme.klasor');
        file_put_contents($klasor.'/'.$yedek->dosya_adi, $icerik);
        $boz($this, $yedek);
        $plan = YedekPlani::factory()->create();
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => 14]);
        $this->travel(1)->seconds();

        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef, [$plan->id]);
        $onizleme = app(SaklamaPolitikasi::class)->hesapla();
        app(SaklamaPolitikasi::class)->uygula();

        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame(IslemSonucu::Basarili, YedekIslemKaydi::query()->where('islem', 'kurtarma_paketi')->latest('id')->first()->sonuc);
        $this->assertCount(2, $this->isaretciler($hedef));
        $this->assertTrue($gecerliKopya->refresh()->kurtarma_hazir);
        $this->assertFalse($kopya->refresh()->kurtarma_hazir);
        $this->assertContains('kurtarma_paketi_eksik', $onizleme['korunan'][$yedek->id]);
        $this->assertFileExists($klasor.'/'.$yedek->dosya_adi);
        $this->assertNull($yedek->refresh()->yerel_silinme_zamani);
    }

    /**
     * BY-024-R5: hazırlığın dayanağı hedefte kalan tamam sürümlerdir. Yedeğin
     * doğrulanmış manifestini taşıyan sürüm durdukça kopya hazır kalır; son
     * böyle sürüm dönüşümle (son iki tamam sürüm) silinince işaret geri alınır.
     */
    public function test_dogrulanmis_manifesti_tasiyan_son_surum_donusumle_kalkinca_hazir_isareti_geri_alinir(): void
    {
        $hedef = $this->bellekHedefi();
        $yedek = $this->yedek();
        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        $ilkHazir = $kopya->refresh()->kurtarma_hazir;
        unlink(ManifestDeposu::jsonYolu(config('yedekleme.klasor').'/'.$yedek->dosya_adi));
        $paket = app(KurtarmaPaketi::class);

        $this->travel(1)->seconds();
        $this->assertTrue($paket->gonder($hedef));
        $ikincidenSonraHazir = $kopya->refresh()->kurtarma_hazir;
        $this->travel(1)->seconds();
        $this->assertTrue($paket->gonder($hedef));

        $this->assertTrue($ilkHazir);
        $this->assertTrue($ikincidenSonraHazir);
        $this->assertFalse($kopya->refresh()->kurtarma_hazir);
        $this->assertCount(2, $this->isaretciler($hedef));
    }

    /**
     * BY-024-R5: doğrulanmış manifest listesi olmayan (eski biçimli) sürüm
     * kaydı reddedilmez — sürümleri dönüşümle silinir — ama hazırlığa dayanak
     * olmaz (bilinmiyor = hazır değil).
     */
    public function test_eski_bicimli_surum_kaydi_okunur_listesiz_surum_hazirliga_dayanak_olmaz(): void
    {
        $hedef = $this->bellekHedefi();
        $yedek = $this->yedek();
        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        [$eski] = $this->isaretciler($hedef);
        $yol = config('yedekleme.durum_klasoru').'/kurtarma-paketleri.json';
        $hedefler = json_decode((string) file_get_contents($yol), true, 16, JSON_THROW_ON_ERROR)['hedefler'];
        foreach ($hedefler as $id => $surumler) {
            foreach (array_keys($surumler) as $i) {
                unset($hedefler[$id][$i]['yedekler']);
            }
        }
        file_put_contents($yol, json_encode(['hedefler' => $hedefler, 'imza' => app(YedekImzasi::class)->imzala($hedefler)], JSON_THROW_ON_ERROR));
        unlink(ManifestDeposu::jsonYolu(config('yedekleme.klasor').'/'.$yedek->dosya_adi));
        $paket = app(KurtarmaPaketi::class);

        $this->travel(1)->seconds();
        $this->assertTrue($paket->gonder($hedef));
        $ikincidenSonraHazir = $kopya->refresh()->kurtarma_hazir;
        $this->travel(1)->seconds();
        $this->assertTrue($paket->gonder($hedef));

        $this->assertFalse($ikincidenSonraHazir);
        $this->assertNotContains($eski['surum'], array_column($this->isaretciler($hedef), 'surum'));
        $this->assertCount(2, $this->isaretciler($hedef));
    }
}
