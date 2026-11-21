<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SiklikTuru;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKopyalayici;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-009: `yedek:zamanla` (README §4.2, §5.2).
 */
final class YedekZamanlaTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true]);
        Sleep::fake();
        $this->travelTo(CarbonImmutable::parse('2026-11-21 02:00:30', 'Europe/Istanbul'));
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function hedef(string $ad, ?string $klasor = null): YedekHedefi
    {
        if ($klasor !== null) {
            @mkdir($klasor, 0750, true);
        }

        return YedekHedefi::factory()->create(['ad' => $ad, 'baglanti' => ['klasor' => $klasor ?? '']]);
    }

    private function vadesiGelenPlan(string $ad, YedekHedefi $hedef, ?CarbonImmutable $vade = null): YedekPlani
    {
        $plan = YedekPlani::factory()->create(['ad' => $ad, 'siklik_turu' => SiklikTuru::Gunluk, 'saat' => '02:00']);
        $plan->forceFill(['sonraki_calisma' => $vade ?? CarbonImmutable::now()->subSeconds(30)])->save();
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => 14]);

        return $plan;
    }

    private function pgDumpCalisti(int $kez): void
    {
        Process::assertRanTimes(fn (PendingProcess $p): bool => str_ends_with((string) ((array) $p->command)[0], 'pg_dump')
            && ! in_array('--version', (array) $p->command, true), $kez);
    }

    public function test_ayar_kapaliyken_komut_hicbir_surec_baslatmaz(): void
    {
        config(['yedekleme.aktif' => false]);
        $this->sahtePgAraclari();
        $this->vadesiGelenPlan('Günlük', $this->hedef('Ana'));

        $this->artisan('yedek:zamanla')->assertSuccessful();

        Process::assertNothingRan();
        $this->assertSame(0, Yedek::query()->count());
    }

    public function test_ayni_dakikaya_dusen_uc_plan_tek_pg_dump_calistirir_uc_hedefe_kopya_gider(): void
    {
        $this->sahtePgAraclari();
        foreach (['A', 'B', 'C'] as $ad) {
            $this->vadesiGelenPlan("Plan {$ad}", $this->hedef("Hedef {$ad}", $this->yedekKoku.'/disk-'.$ad));
        }

        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->pgDumpCalisti(1);
        $yedek = Yedek::query()->sole();
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertSame(3, YedekKopyasi::query()->where('durum', KopyaDurumu::Tamam->value)->count());
        foreach (['A', 'B', 'C'] as $ad) {
            $this->assertFileExists($this->yedekKoku.'/disk-'.$ad.'/'.$yedek->dosya_adi);
        }
    }

    public function test_sunucu_uc_gun_kapali_kaldiktan_sonra_gunluk_plan_bir_kez_calisir(): void
    {
        $this->sahtePgAraclari();
        $plan = $this->vadesiGelenPlan('Günlük', $this->hedef('Ana'), CarbonImmutable::now()->subDays(3));

        $this->artisan('yedek:zamanla')->assertSuccessful();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->pgDumpCalisti(1);
        $this->assertSame('2026-11-22 02:00', $plan->refresh()->sonraki_calisma?->setTimezone('Europe/Istanbul')->format('Y-m-d H:i'));
    }

    public function test_pasif_plan_atlanir(): void
    {
        $this->sahtePgAraclari();
        $this->vadesiGelenPlan('Günlük', $this->hedef('Ana'))->update(['aktif' => false]);

        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->pgDumpCalisti(0);
    }

    /** README §4.2: planın hedeflerinde yerel disk (yedek klasörü) yoksa yerel dosya kopyadan sonra silinir. */
    public function test_planin_hedefinde_yedek_klasoru_yoksa_yerel_dosya_kopyadan_sonra_silinir_manifest_kalir(): void
    {
        $this->sahtePgAraclari();
        $this->vadesiGelenPlan('İkinci disk', $this->hedef('Disk 2', $this->yedekKoku.'/disk-2'));

        $this->artisan('yedek:zamanla')->assertSuccessful();

        $yedek = Yedek::query()->sole();
        $yerel = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        $this->assertFileDoesNotExist($yerel);
        $this->assertFileExists(ManifestDeposu::jsonYolu($yerel));
        $this->assertNotNull($yedek->yerel_silinme_zamani);
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
    }

    public function test_kopya_basarisizsa_yerel_dosya_silinmez(): void
    {
        $this->sahtePgAraclari();
        // Yazılamayan klasör: kopya başarısız olur
        $this->vadesiGelenPlan('Bozuk disk', $this->hedef('Yok', '/proc/yedek-yazilamaz'));

        $this->artisan('yedek:zamanla')->assertSuccessful();

        $yedek = Yedek::query()->sole();
        $this->assertFileExists(config('yedekleme.klasor').'/'.$yedek->dosya_adi);
        $this->assertNull($yedek->yerel_silinme_zamani);
    }

    /** BY-010-R1: bir hedef tamam, diğeri başarısızken yerel dosya kalır; ikinci hedef doğrulanınca saklama siler. */
    public function test_bir_hedef_basarisizken_yerel_dosya_kalir_ikinci_hedef_dogrulaninca_saklama_siler(): void
    {
        $this->sahtePgAraclari();
        $plan = $this->vadesiGelenPlan('İki hedef', $this->hedef('Disk 2', $this->yedekKoku.'/disk-2'));
        $bozuk = $this->hedef('Bozuk disk', '/proc/yedek-yazilamaz');
        $plan->hedefler()->attach($bozuk, ['sakla_son_adet' => 14]);

        $this->artisan('yedek:zamanla')->assertSuccessful();

        $yedek = Yedek::query()->sole();
        $yerel = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        $this->assertFileExists($yerel);
        $this->assertNull($yedek->yerel_silinme_zamani);

        @mkdir($this->yedekKoku.'/disk-3', 0750, true);
        $bozuk->update(['baglanti' => ['klasor' => $this->yedekKoku.'/disk-3']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $bozuk->refresh(), [$plan->id]);
        app(SaklamaPolitikasi::class)->uygula();

        $this->assertFileDoesNotExist($yerel);
        $this->assertNotNull($yedek->refresh()->yerel_silinme_zamani);
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
    }

    /**
     * BY-008-R1: yedek klasörünün farklı bir yazımı da ana klasördür; aynı dosya
     * bağımsız kopya sayılıp eldeki tek arşiv silinmez (D4, F7).
     */
    #[DataProvider('anaKlasorYazimlari')]
    public function test_ana_klasorun_farkli_yazimini_hedefleyen_planda_yedek_yerinde_kalir(Closure $yazim): void
    {
        $this->sahtePgAraclari();
        $klasor = config('yedekleme.klasor');
        @mkdir($klasor, 0750, true);
        $this->vadesiGelenPlan('Yerel', $this->hedef('Yedek klasörü', $yazim($klasor)));

        $this->artisan('yedek:zamanla')->assertSuccessful();

        $yedek = Yedek::query()->sole();
        $this->assertFileExists($klasor.'/'.$yedek->dosya_adi);
        $this->assertNull($yedek->yerel_silinme_zamani);
    }

    /** @return array<string, array{Closure}> */
    public static function anaKlasorYazimlari(): array
    {
        return [
            'sondaki bölü' => [fn (string $k): string => $k.'/'],
            'üst klasör (..)' => [fn (string $k): string => $k.'/../'.basename($k)],
            'sembolik bağlantı' => [function (string $k): string {
                symlink($k, $k.'-baglanti');

                return $k.'-baglanti';
            }],
        ];
    }

    /** BY-008-R1: başka klasördeki aynı dosya (bağlantı) bağımsız kopya sayılmaz, açık kodla reddedilir. */
    public function test_baska_klasorde_yerel_dosyanin_kendisi_kopya_sayilmaz(): void
    {
        $this->sahtePgAraclari();
        $kilit = app(YedeklemeYapilandirmasi::class)->islemDurumu()->kilit();
        $this->assertTrue($kilit->al());
        $yedek = app(YedekAlici::class)->al($kilit, YedekKaynagi::Elle);
        $kilit->birak();
        $yerel = config('yedekleme.klasor').'/'.$yedek->dosya_adi;
        $hedef = $this->hedef('Bağlantılı disk', $this->yedekKoku.'/disk-4');
        symlink($yerel, $this->yedekKoku.'/disk-4/'.$yedek->dosya_adi);

        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);

        $this->assertSame([KopyaDurumu::Basarisiz, HedefHatasi::ANA_DOSYA], [$kopya->durum, $kopya->hata_kodu]);
        $this->assertFileExists($yerel);
    }

    public function test_ilk_kez_gorulen_plan_geriye_donuk_calismaz_siradaki_an_kurulur(): void
    {
        $this->sahtePgAraclari();
        $plan = YedekPlani::factory()->create(['siklik_turu' => SiklikTuru::Gunluk, 'saat' => '02:00']);
        $plan->hedefler()->attach($this->hedef('Ana'), ['sakla_son_adet' => 14]);

        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->pgDumpCalisti(0);
        $this->assertSame('2026-11-22 02:00', $plan->refresh()->sonraki_calisma?->setTimezone('Europe/Istanbul')->format('Y-m-d H:i'));
    }
}
