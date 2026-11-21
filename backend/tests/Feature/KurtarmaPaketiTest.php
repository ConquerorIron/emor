<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KopyaSifreleyici;
use App\Services\Yedekleme\KurtarmaPaketi;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\YedekKopyalayici;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\GpgAnahtarlari;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-024: olay günlüğü, ayar kopyası ve denetim günlüğünün sunucu dışı
 * hedefe şifreli gitmesi (README §4.6 son madde, §9 "Kurtarma paketi").
 */
final class KurtarmaPaketiTest extends TestCase
{
    use GpgAnahtarlari;
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const DOSYALAR = [KurtarmaPaketi::OLAYLAR, KurtarmaPaketi::AYARLAR, KurtarmaPaketi::DENETIM, KurtarmaPaketi::MANIFESTLER];

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

    public function test_sunucu_disi_kopyadan_sonra_olay_ayar_denetim_ve_manifestler_ayni_anahtarla_sifreli_gider(): void
    {
        $yedek = $this->yedek();
        $hedef = $this->bellekHedefi();

        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);

        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $uzak = fn (string $ad): string => BellekHedefi::$dosyalar[$hedef->id.'/'.$ad];

        $this->assertStringNotContainsString($yedek->kimlik, $uzak(KurtarmaPaketi::OLAYLAR));
        $this->assertSame(file_get_contents(app(OlayGunlugu::class)->yol()), $this->coz($uzak(KurtarmaPaketi::OLAYLAR)));
        $this->assertStringContainsString($yedek->kimlik, $this->coz($uzak(KurtarmaPaketi::OLAYLAR)));
        $this->assertSame(file_get_contents(config('yedekleme.ayar_kopyasi')), $this->coz($uzak(KurtarmaPaketi::AYARLAR)));

        $denetim = $this->coz($uzak(KurtarmaPaketi::DENETIM));
        $this->assertStringStartsWith("[2025-01-01] eski gun 1\n[2025-01-02] eski gun 2\n", $denetim);
        $this->assertStringContainsString('yedekleme.kopya', $denetim);

        // Manifest arşivi yedek klasörüne göre göreli adlarla açılır
        $arsiv = $this->yedekKoku.'/manifestler.tar';
        file_put_contents($arsiv, $this->coz($uzak(KurtarmaPaketi::MANIFESTLER)));
        $acilan = $this->yedekKoku.'/acilan';
        mkdir($acilan);
        (new Process(['/usr/bin/tar', '-xf', $arsiv, '-C', $acilan]))->mustRun();
        $this->assertSame(['.', '..', $this->manifestAdi($yedek)], scandir($acilan));
        $this->assertFileEquals(config('yedekleme.klasor').'/'.$this->manifestAdi($yedek), $acilan.'/'.$this->manifestAdi($yedek));

        $kayit = YedekIslemKaydi::query()->where('islem', 'kurtarma_paketi')->sole();
        $this->assertSame(IslemSonucu::Basarili, $kayit->sonuc);
        $this->assertSame(self::DOSYALAR, $kayit->ayrinti['gonderilen']);
    }

    public function test_kurtarma_dosyalari_hedefin_yedek_listesine_girmez(): void
    {
        $klasor = $this->yedekKoku.'/ikinci-disk';
        mkdir($klasor, 0750);
        $hedef = YedekHedefi::factory()->create(['baglanti' => ['klasor' => $klasor]]);
        $yedek = $this->yedek();
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);

        $this->assertTrue(app(KurtarmaPaketi::class)->gonder($hedef));

        $this->assertEqualsCanonicalizing([$yedek->dosya_adi, ...self::DOSYALAR], array_values(array_diff(scandir($klasor) ?: [], ['.', '..'])));
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

    /** Google Drive gibi adla üstüne yazmayan hedef: önceki dosya, imzalı kimlik kaydından bulunup silinir */
    public function test_ustune_yazmayan_hedefte_onceki_kurtarma_dosyalari_silinir_kayit_bozuksa_silinmez(): void
    {
        BellekHedefi::$herYazimdaYeniKimlik = true;
        $hedef = $this->bellekHedefi();
        $paket = app(KurtarmaPaketi::class);
        @mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(app(OlayGunlugu::class)->yol(), "{}\n");

        $paket->gonder($hedef);
        $paket->gonder($hedef);

        $this->assertCount(3, BellekHedefi::$dosyalar);
        $this->assertSame(['#4', '#5', '#6'], array_map(fn (string $k): string => strrchr($k, '#'), array_keys(BellekHedefi::$dosyalar)));

        // İmzası tutmayan kayıt bir yedeği gösteriyor: hiçbir uzak dosya silinmez
        $yedekKimligi = $hedef->id.'/erp_elle_20260101_000000.dump.gpg';
        BellekHedefi::$dosyalar[$yedekKimligi] = 'sifreli-yedek';
        file_put_contents(config('yedekleme.durum_klasoru').'/kurtarma-kimlikleri.json', json_encode(['kimlikler' => [$hedef->id => array_fill_keys(self::DOSYALAR, $yedekKimligi)], 'imza' => str_repeat('0', 64)]));
        $paket->gonder($hedef);

        $this->assertArrayHasKey($yedekKimligi, BellekHedefi::$dosyalar);
        $this->assertCount(7, BellekHedefi::$dosyalar);
    }
}
