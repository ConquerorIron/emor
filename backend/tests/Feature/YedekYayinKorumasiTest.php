<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Yedekleme\BakimModu;
use App\Services\Yedekleme\ErisimAnahtari;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\Istekler\IstekCalistiricisi;
use App\Services\Yedekleme\Istekler\UstuneGeriYukleIsleyicisi;
use App\Services\Yedekleme\UstuneGeriYuklemeAdimi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;
use Tests\TestCase;
use Tests\Yedekleme\SahtePostgres;
use Tests\Yedekleme\UstuneGeriYuklemeOrtami;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-013-R1, BY-029-R1: yayın ile üstüne geri yükleme çakışmaz. `deploy.sh`
 * yayın kilidini betik boyunca tutar (scripts/tests/deploy.test.sh); üstüne
 * geri yükleme başlarken kilidi beklemeden almayı dener. Yayın tarafı kilidin
 * yolunu ve açık işlem denetimini `yedek:yayin-korumasi`'ndan alır.
 */
final class YedekYayinKorumasiTest extends TestCase
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

    private function ustuneIstegi(): string
    {
        return app(IstekKutusu::class)->yaz('ustune_geri_yukle', [
            'yedek_kimligi' => $this->ustuneYedekAl()->kimlik, 'onay_metni' => 'erp', 'erisim_anahtari_ozeti' => ErisimAnahtari::ozet(ErisimAnahtari::uret()),
        ], $this->yonetici->id);
    }

    public function test_yayin_kilidi_tutulurken_ustune_geri_yukleme_baslamaz_bosken_baslar(): void
    {
        // Yayın (deploy.sh'ın flock'u) başka bir açılışla kilidi tutar
        $yayin = app(YedeklemeYapilandirmasi::class)->yayinKilidi();
        $this->assertTrue($yayin->al());
        $kimlik = $this->ustuneIstegi();

        app(IstekCalistiricisi::class)->tur();

        $sonuc = app(IstekKutusu::class)->durum($kimlik);
        $this->assertSame('basarisiz', $sonuc['durum'] ?? null);
        $this->assertSame(UstuneGeriYukleIsleyicisi::YAYIN_SURUYOR, $sonuc['kod'] ?? null);
        // İşlemin ilk durumu yazılmadı (durum dosyasındaki, önceki yedeğin kapalı kaydı)
        $this->assertNotSame($kimlik, $this->durumOku()?->kimlik);
        $this->assertFalse(app(YedeklemeYapilandirmasi::class)->islemDurumu()->acikGeriYuklemeVarMi());
        $this->assertFalse(app(BakimModu::class)->aktifMi());
        $this->assertFalse($this->psqlCalisti('CREATE DATABASE'));

        $yayin->birak();
        $ikinci = $this->ustuneIstegi();

        app(IstekCalistiricisi::class)->tur();

        $this->assertSame('tamam', app(IstekKutusu::class)->durum($ikinci)['durum'] ?? null);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        // İşleyici döndü: yayın kilidi boş, yayın açık işlemi durum dosyasından görür
        $this->assertTrue($yayin->al());
        $yayin->birak();
        $this->artisan('yedek:yayin-korumasi', ['adim' => 'denetle'])->assertFailed();
    }

    /**
     * Codex G1-8-R1: servis döngüsü normal işleri yayın kilidini tutarak yürütür.
     * Döngüde işlenen üstüne geri yükleme aynı kilidi ikinci tutamaçla almaya
     * kalkmaz (kendi sürecine takılıp YAYIN_SURUYOR almazdı); döngü bitince
     * kilit boştur.
     */
    public function test_servis_dongusunde_ustune_geri_yukleme_kendi_yayin_kilidine_takilmaz(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        Sleep::fake();
        $kimlik = $this->ustuneIstegi();

        app(ConsoleKernel::class)->setArtisan(null);
        Artisan::call('yedek:calistir', ['--en-cok-tur' => 1]);

        $this->assertSame('tamam', app(IstekKutusu::class)->durum($kimlik)['durum'] ?? null);
        $this->assertSame(UstuneGeriYuklemeAdimi::OnayBekliyor->value, $this->durumOku()?->adim);
        $yayin = app(YedeklemeYapilandirmasi::class)->yayinKilidi();
        $this->assertTrue($yayin->al());
        $yayin->birak();
    }

    public function test_koruma_komutu_modul_acikken_kilit_yolunu_verir_kapaliyken_hicbir_sey_yazmaz(): void
    {
        $yol = app(YedeklemeYapilandirmasi::class)->yayinKilidiYolu();
        $this->assertFileDoesNotExist($yol);

        $this->artisan('yedek:yayin-korumasi', ['adim' => 'kilit-yolu'])->expectsOutput($yol)->assertSuccessful();
        $this->assertFileExists($yol, 'Geçiş kipinde dosyayı komut açar (deploy.sh salt okunur açar)');
        $this->artisan('yedek:yayin-korumasi', ['adim' => 'denetle'])->assertSuccessful();

        config(['yedekleme.aktif' => false]);
        $this->artisan('yedek:yayin-korumasi', ['adim' => 'kilit-yolu'])->doesntExpectOutput($yol)->assertSuccessful();

        $this->artisan('yedek:yayin-korumasi', ['adim' => 'baska'])->assertExitCode(2);
    }

    public function test_koruma_komutu_durum_okunamazsa_yayini_durdurur(): void
    {
        @mkdir(config('yedekleme.durum_klasoru'), 0750, true);
        file_put_contents(config('yedekleme.durum_klasoru').'/islem.json', '{bozuk');

        $this->artisan('yedek:yayin-korumasi', ['adim' => 'denetle'])
            ->expectsOutputToContain('İşlem durumu okunamadı')
            ->assertFailed();
    }
}
