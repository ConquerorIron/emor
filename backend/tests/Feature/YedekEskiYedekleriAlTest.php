<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Services\Yedekleme\DizinKurucu;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SilmeKarari;
use App\Services\Yedekleme\YedekKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-013: deploy.sh'ın eski imzasız yedeklerinin içeri alınması
 * (`yedek:eski-yedekleri-al`; README §4.5).
 */
final class YedekEskiYedekleriAlTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private string $eski;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 12:00', 'UTC'));
        $this->eski = $this->yedekKoku.'/eski';
        mkdir($this->eski, 0750);
        file_put_contents($this->eski.'/erp_predeploy_2026-11-01_101010.dump', 'PGDMP-bir');
        file_put_contents($this->eski.'/erp_predeploy_2026-11-10_203040.dump', 'PGDMP-iki');
        file_put_contents($this->eski.'/erp_predeploy_2026-11-12_000000.dump', 'bozuk');
        file_put_contents($this->eski.'/baska.dump', 'PGDMP-ilgisiz');
        // Bozuk dosyayı pg_restore okuyamaz
        $this->sahtePgAraclari(['*pg_restore*2026-11-12_000000*' => Process::result(errorOutput: 'pg_restore: error: input file does not appear to be a valid archive', exitCode: 1)]);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    public function test_okunabilen_predeploy_yedekleri_ice_alindi_isaretli_imzali_manifestle_alinir_bozuk_atlanir(): void
    {
        $this->artisan('yedek:eski-yedekleri-al', ['--klasor' => $this->eski])
            ->expectsOutputToContain('İçeri alınan 2, zaten olan 0, okunamayan 1, çakışan 0.')
            ->assertSuccessful();

        $yedekler = Yedek::query()->orderBy('alinma_zamani')->get();
        $this->assertSame(['erp_predeploy_2026-11-01_101010.dump', 'erp_predeploy_2026-11-10_203040.dump'], $yedekler->pluck('dosya_adi')->all());
        foreach ($yedekler as $yedek) {
            $this->assertTrue($yedek->ice_alindi);
            $this->assertTrue($yedek->imzali);
            $this->assertSame(YedekKaynagi::YayinOncesi, $yedek->kaynak);
            $dump = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
            $sonuc = app(ManifestDeposu::class)->oku(ManifestDeposu::jsonYolu($dump));
            $this->assertTrue($sonuc->guvenilirMi());
            $this->assertTrue($sonuc->manifest->iceAlindi);
            $this->assertTrue(ManifestDeposu::dosyaTutuyorMu($sonuc->manifest, $dump));
        }
        // Ad sunucu saatiyle (UTC) yazılmıştı
        $this->assertSame('2026-11-01 10:10:10', $yedekler[0]->alinma_zamani->utc()->format('Y-m-d H:i:s'));
        // Kaynak dosyalar yerinde kalır (kopyalanır, taşınmaz)
        $this->assertFileExists($this->eski.'/erp_predeploy_2026-11-01_101010.dump');
    }

    public function test_iki_kez_calistirmak_yedegi_cogaltmaz(): void
    {
        $this->artisan('yedek:eski-yedekleri-al', ['--klasor' => $this->eski])->assertSuccessful();

        $this->artisan('yedek:eski-yedekleri-al', ['--klasor' => $this->eski])
            ->expectsOutputToContain('İçeri alınan 0, zaten olan 2, okunamayan 1, çakışan 0.')
            ->assertSuccessful();

        $this->assertSame(2, Yedek::query()->count());
    }

    public function test_ice_alinan_yedeklere_yayin_oncesi_saklama_kurali_uygulanir(): void
    {
        YedekAyari::query()->create(['yayin_oncesi_son_adet' => 1, 'yayin_oncesi_gun' => 0, 'enaz_adet' => 1]);
        $this->artisan('yedek:eski-yedekleri-al', ['--klasor' => $this->eski])->assertSuccessful();
        $eskisi = Yedek::query()->where('dosya_adi', 'erp_predeploy_2026-11-01_101010.dump')->sole();

        $silinecek = array_map(fn (SilmeKarari $k): int => $k->yedekId, app(SaklamaPolitikasi::class)->hesapla()['silinecek']);

        $this->assertSame([$eskisi->id], array_values(array_unique($silinecek)));
    }

    /** BY-013-R2 (D4): aynı adla farklı içerikli ikinci arşiv içerideki yedeğin dosyasına ve manifestine dokunmaz. */
    public function test_ayni_adli_farkli_arsiv_icerideki_yedege_dokunmaz_cakisma_olarak_basarisiz_cikar(): void
    {
        $this->artisan('yedek:eski-yedekleri-al', ['--klasor' => $this->eski])->assertSuccessful();
        $dump = config('yedekleme.klasor').'/erp_predeploy_2026-11-01_101010.dump';
        $onceDump = file_get_contents($dump);
        $onceManifest = file_get_contents(ManifestDeposu::jsonYolu($dump));
        $ikinci = $this->yedekKoku.'/eski-2';
        mkdir($ikinci, 0750);
        file_put_contents($ikinci.'/erp_predeploy_2026-11-01_101010.dump', 'PGDMP-baska-icerik');

        $this->artisan('yedek:eski-yedekleri-al', ['--klasor' => $ikinci])
            ->expectsOutputToContain('İçeri alınan 0, zaten olan 0, okunamayan 0, çakışan 1.')
            ->assertFailed();

        $this->assertSame($onceDump, file_get_contents($dump));
        $this->assertSame($onceManifest, file_get_contents(ManifestDeposu::jsonYolu($dump)));
        $this->assertSame(2, Yedek::query()->count());
    }

    public function test_dizin_yeniden_kurulunca_ice_alindi_isareti_manifestten_gelir(): void
    {
        $this->artisan('yedek:eski-yedekleri-al', ['--klasor' => $this->eski])->assertSuccessful();
        Yedek::query()->delete();

        app(DizinKurucu::class)->tara();

        $this->assertSame([true, true], Yedek::query()->pluck('ice_alindi')->all());
    }
}
