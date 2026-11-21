<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KopyaSifreleyici;
use App\Services\Yedekleme\KurtarmaPaketi;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\YedekKopyalayici;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
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

    /** Yedek klasöründe dump'ı ve (içeriği önemsiz) manifesti olan yedek */
    private function yedek(string $icerik = 'PGDMP-yedek'): Yedek
    {
        $yedek = Yedek::factory()->create(['sha256' => hash('sha256', $icerik), 'boyut' => strlen($icerik)]);
        @mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(config('yedekleme.klasor').'/'.$yedek->dosya_adi, $icerik);
        file_put_contents(config('yedekleme.klasor').'/'.$this->manifestAdi($yedek), '{"manifest":{},"imza":""}');

        return $yedek;
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
}
