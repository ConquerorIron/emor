<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\PlanliYedek;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SiklikTuru;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKopyalayici;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
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

    /**
     * YF-37 (G18): planlar kilitten önce okunur; kilit beklenirken başka süreç
     * planı çalıştırıp vadesini ilerletirse karar kilit altında yeniden okunan
     * vadeden verilir: ikinci yedek alınmaz.
     */
    public function test_kilit_beklenirken_baska_surec_plani_calistirdiysa_yedek_alinmaz(): void
    {
        $this->sahtePgAraclari();
        $plan = $this->vadesiGelenPlan('Günlük', $this->hedef('Ana'));
        $ilerletildi = false;
        // İlk okumada (kilitten önce) başka sürecin çalıştırması taklit edilir
        YedekPlani::retrieved(function (YedekPlani $okunan) use ($plan, &$ilerletildi): void {
            if (! $ilerletildi && $okunan->is($plan)) {
                $ilerletildi = true;
                YedekPlani::query()->whereKey($plan->id)->update(['sonraki_calisma' => CarbonImmutable::now()->addDay()]);
            }
        });

        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->assertTrue($ilerletildi);
        $this->pgDumpCalisti(0);
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
     * YF-04: başarısız kopya üretimde de yeniden denenir. Hedef bir sonraki
     * planlı çalışmada ulaşılabilir olunca önceki yedeğin kopyası da
     * tamamlanır; testin kendisi kopyalayıcıyı çağırmaz.
     */
    public function test_hedef_duzelince_sonraki_planli_calisma_onceki_yedegin_basarisiz_kopyasini_tamamlar(): void
    {
        $this->sahtePgAraclari();
        $klasor = $this->yedekKoku.'/disk-gec';
        // Klasör henüz yok: ilk çalışmada kopya başarısız
        $hedef = YedekHedefi::factory()->create(['ad' => 'Geç bağlanan disk', 'baglanti' => ['klasor' => $klasor]]);
        $plan = $this->vadesiGelenPlan('Günlük', $hedef);

        $this->artisan('yedek:zamanla')->assertSuccessful();
        $ilk = Yedek::query()->sole();
        $this->assertSame(KopyaDurumu::Basarisiz, YedekKopyasi::query()->where('yedek_id', $ilk->id)->sole()->durum);

        mkdir($klasor, 0750, true);
        $this->travel(1)->days();
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->assertSame(KopyaDurumu::Tamam, YedekKopyasi::query()->where('yedek_id', $ilk->id)->sole()->durum);
        $this->assertFileExists($klasor.'/'.$ilk->dosya_adi);
    }

    /**
     * Fable ikinci tur (D8): deneme sınırını dolduran başarısız kopya (kalıcı
     * olarak düşüyor) sonraki çalışmalarda yeniden yüklenmez.
     */
    public function test_deneme_siniri_dolan_basarisiz_kopya_yeniden_denenmez(): void
    {
        $this->sahtePgAraclari();
        $klasor = $this->yedekKoku.'/disk-gec';
        $hedef = YedekHedefi::factory()->create(['ad' => 'Geç bağlanan disk', 'baglanti' => ['klasor' => $klasor]]);
        $plan = $this->vadesiGelenPlan('Günlük', $hedef);
        $this->artisan('yedek:zamanla')->assertSuccessful();
        $kopya = YedekKopyasi::query()->sole();
        $kopya->forceFill(['deneme' => app(YedeklemeYapilandirmasi::class)->kopyaDenemeSayisi() * PlanliYedek::EN_COK_TUR])->save();

        mkdir($klasor, 0750, true);
        $this->travel(1)->days();
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->assertSame(KopyaDurumu::Basarisiz, $kopya->refresh()->durum);
        $this->assertFileDoesNotExist($klasor.'/'.$kopya->yedek->dosya_adi);
    }

    /**
     * G1-2: yeniden denenen kopya yedeği alan planın kuralıyla saklanır.
     * Aynı hedefi paylaşan iki plandan yalnız B çalışırken A'nın yedeğinin
     * başarısız kopyası tamamlanırsa bağ A'da kalır (B'nin kuralı A'nın
     * yedeğini erken silmez).
     */
    public function test_yeniden_denenen_kopya_yedegi_alan_plana_bagli_kalir_o_an_calisan_plana_degil(): void
    {
        $this->sahtePgAraclari();
        $klasor = $this->yedekKoku.'/disk-ortak';
        $hedef = YedekHedefi::factory()->create(['ad' => 'Ortak disk', 'baglanti' => ['klasor' => $klasor]]);
        $a = $this->vadesiGelenPlan('Plan A', $hedef);
        $b = $this->vadesiGelenPlan('Plan B', $hedef, CarbonImmutable::now()->addHour());

        // Klasör henüz yok: A'nın yedeğinin kopyası düşer
        $this->artisan('yedek:zamanla')->assertSuccessful();
        $ilk = Yedek::query()->sole();

        mkdir($klasor, 0750, true);
        $this->travel(2)->hours();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        $kopya = YedekKopyasi::query()->where('yedek_id', $ilk->id)->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame([$a->id], $kopya->planlar()->pluck('yedek_planlari.id')->all());
        // B kendi yedeğini aldı ve kendi kopyasına bağlandı
        $this->assertTrue($b->refresh()->kopyalar()->where('yedek_id', '!=', $ilk->id)->exists());
    }

    /**
     * G1-6: süreç kopya kayıtları açılmadan öldüyse (sınama geri yüklemesi
     * sırasında ya da sahipsiz tamamlanan yedek) plan yedeğinin hiç kopya
     * kaydı olmaz. Aynı planın sonraki çalışması onu hedefe kopyalar ve plana
     * bağlar.
     */
    public function test_kopya_kaydi_hic_acilmamis_plan_yedegi_sonraki_calismada_hedefe_kopyalanir(): void
    {
        $this->sahtePgAraclari();
        $klasor = $this->yedekKoku.'/disk-gec';
        $hedef = YedekHedefi::factory()->create(['ad' => 'Geç bağlanan disk', 'baglanti' => ['klasor' => $klasor]]);
        $plan = $this->vadesiGelenPlan('Günlük', $hedef);
        // İlk çalışmada kopya düşer (yerel dosya kalır); kaydı silinince
        // "kopya kaydı açılmadan ölmüş süreç" kurgusu
        $this->artisan('yedek:zamanla')->assertSuccessful();
        $ilk = Yedek::query()->sole();
        YedekKopyasi::query()->delete();

        mkdir($klasor, 0750, true);
        $this->travel(1)->days();
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        $kopya = YedekKopyasi::query()->where('yedek_id', $ilk->id)->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame([$plan->id], $kopya->planlar()->pluck('yedek_planlari.id')->all());
        $this->assertFileExists($klasor.'/'.$ilk->dosya_adi);
    }

    /**
     * Codex G1-6-R1: kayıtları yarım kalmış yedek (bir hedefin kaydı var,
     * diğerinin yok) sonraki çalışmada eksik hedefe kopyalanır. Plana yedekten
     * SONRA eklenen hedefe eski yedek için kayıt açılmaz. G1-6-R2: çiftleri
     * kayıtsız eski yedekte aynı saniyede eklenen hedef üyelik sayılmaz; başka
     * planın üç yeni yedeği eksik kopyanın önünü kesmez.
     *
     * @return array<string, array{0: string}>
     */
    public static function yarimKayitKurgulari(): array
    {
        return [
            'yeni yedek (plan / hedef çiftleri kayıtlı)' => ['yeni'],
            'eski yedek, hedef aynı saniyede eklendi' => ['eski_ayni_saniye'],
            'başka planın üç yeni yedeği' => ['ilgisiz_yedekler'],
        ];
    }

    #[DataProvider('yarimKayitKurgulari')]
    public function test_kopya_kaydi_yarim_kalan_plan_yedegi_eksik_hedefe_tamamlanir_sonradan_eklenen_hedefe_gitmez(string $kurgu): void
    {
        $this->sahtePgAraclari();
        // Klasörler henüz yok: ilk çalışmada iki kopya da düşer, yerel dosya kalır
        $klasorA = $this->yedekKoku.'/disk-a';
        $klasorB = $this->yedekKoku.'/disk-b';
        $hedefA = YedekHedefi::factory()->create(['ad' => 'Disk A', 'baglanti' => ['klasor' => $klasorA]]);
        $plan = $this->vadesiGelenPlan('Günlük', $hedefA);
        $hedefB = YedekHedefi::factory()->create(['ad' => 'Disk B', 'baglanti' => ['klasor' => $klasorB]]);
        $plan->hedefler()->attach($hedefB, ['sakla_son_adet' => 14]);
        $this->artisan('yedek:zamanla')->assertSuccessful();
        $ilk = Yedek::query()->sole();
        $this->assertEqualsCanonicalizing(
            [['plan' => $plan->id, 'hedef' => $hedefA->id], ['plan' => $plan->id, 'hedef' => $hedefB->id]],
            $ilk->plan_hedefleri,
        );
        // B'nin kaydı açılmadan ölmüş süreç (eski sürüm hedef hedef açıyordu)
        YedekKopyasi::query()->where(['yedek_id' => $ilk->id, 'yedek_hedefi_id' => $hedefB->id])->delete();
        $this->assertSame(1, YedekKopyasi::query()->where('yedek_id', $ilk->id)->count());
        mkdir($klasorA, 0750, true);
        mkdir($klasorB, 0750, true);
        if ($kurgu === 'ilgisiz_yedekler') {
            // Daha yeni, bu hedefte kaydı olmayan ama bu hedefe gitmeyecek üç plan yedeği
            Yedek::factory()->plan()->count(3)->create(['alinma_zamani' => CarbonImmutable::now(), 'plan_hedefleri' => []]);
        }

        $this->travel(1)->days();
        $klasorC = $this->yedekKoku.'/disk-c';
        $hedefC = $this->hedef('Disk C', $klasorC);
        $plan->hedefler()->attach($hedefC, ['sakla_son_adet' => 14]);
        if ($kurgu === 'eski_ayni_saniye') {
            // Çiftleri kaydedilmemiş eski yedek: A ve B yedekten önce, C yedekle aynı saniyede eklenmiş
            $ilk->forceFill(['plan_hedefleri' => null])->save();
            $plan->hedefler()->updateExistingPivot([$hedefA->id, $hedefB->id], ['created_at' => $ilk->alinma_zamani->subMinute()]);
            $plan->hedefler()->updateExistingPivot($hedefC->id, ['created_at' => $ilk->alinma_zamani]);
        }
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        $kopyaB = YedekKopyasi::query()->where(['yedek_id' => $ilk->id, 'yedek_hedefi_id' => $hedefB->id])->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopyaB->durum);
        $this->assertSame([$plan->id], $kopyaB->planlar()->pluck('yedek_planlari.id')->all());
        $this->assertFileExists($klasorB.'/'.$ilk->dosya_adi);
        $this->assertFalse(YedekKopyasi::query()->where(['yedek_id' => $ilk->id, 'yedek_hedefi_id' => $hedefC->id])->exists());
        $this->assertFileDoesNotExist($klasorC.'/'.$ilk->dosya_adi);
    }

    /**
     * Codex G1-6-R3: süreç arşivden SONRA, kopya kayıtları yazılmadan kesilirse
     * yedeğin gideceği hedefler yine bilinir (yedek kaydıyla birlikte, arşivden
     * önce yazıldı). Hedefler plana yedekle aynı saniyede eklenmiş olsa da
     * sonraki çalışma yedeği onlara kopyalar; sonradan eklenen hedefe göndermez.
     */
    public function test_kopya_kayitlari_yazilmadan_kesilen_yedek_baslangictaki_hedeflere_gider_sonradan_eklenene_gitmez(): void
    {
        $this->sahtePgAraclari();
        $hedefA = $this->hedef('Disk A', $this->yedekKoku.'/disk-a');
        $plan = $this->vadesiGelenPlan('Günlük', $hedefA);
        $hedefB = $this->hedef('Disk B', $this->yedekKoku.'/disk-b');
        $plan->hedefler()->attach($hedefB, ['sakla_son_adet' => 14]);
        // Arşiv tamamlandı, kopya kaydı yazılırken süreç kesildi
        $kes = true;
        YedekKopyasi::creating(function () use (&$kes): void {
            if ($kes) {
                throw new RuntimeException('süreç kesildi');
            }
        });
        try {
            $this->artisan('yedek:zamanla');
        } catch (RuntimeException) {
            // Komut kesintiyi raporlayabilir ya da yukarı atabilir; ikisinde de kayıt yok
        }
        $kes = false;
        $ilk = Yedek::query()->sole();
        $this->assertSame(YedekDurumu::Tamam, $ilk->durum);
        $this->assertSame(0, YedekKopyasi::query()->count());
        // Hedefler yedekle aynı saniyede eklendi: eski kayıt kuralı onları elerdi
        $plan->hedefler()->updateExistingPivot([$hedefA->id, $hedefB->id], ['created_at' => $ilk->alinma_zamani]);

        $this->travel(1)->days();
        $hedefC = $this->hedef('Disk C', $this->yedekKoku.'/disk-c');
        $plan->hedefler()->attach($hedefC, ['sakla_son_adet' => 14]);
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        foreach ([$hedefA, $hedefB] as $hedef) {
            $kopya = YedekKopyasi::query()->where(['yedek_id' => $ilk->id, 'yedek_hedefi_id' => $hedef->id])->sole();
            $this->assertSame(KopyaDurumu::Tamam, $kopya->durum, $hedef->ad);
            $this->assertSame([$plan->id], $kopya->planlar()->pluck('yedek_planlari.id')->all());
        }
        $this->assertFalse(YedekKopyasi::query()->where(['yedek_id' => $ilk->id, 'yedek_hedefi_id' => $hedefC->id])->exists());
        $this->assertFileDoesNotExist($this->yedekKoku.'/disk-c/'.$ilk->dosya_adi);
    }

    /**
     * Codex G1-6-R4: kopya kayıtları yazılmadan kesilen plan yedeği tek
     * arşivdir. Hedefleri hâlâ etkin bağlıyken temizlik ona elle yedek kuralını
     * uygulamaz (elle saklama 0 gün, daha yeni elle yedek var); sonraki plan
     * çalışması dosyayı başlangıçtaki hedeflere kopyalar.
     */
    public function test_kopya_kaydi_yazilmadan_kesilen_plan_yedegi_temizlikte_silinmez_sonraki_calismada_kopyalanir(): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
        $hedefA = $this->hedef('Disk A', $this->yedekKoku.'/disk-a');
        $plan = $this->vadesiGelenPlan('Günlük', $hedefA);
        $hedefB = $this->hedef('Disk B', $this->yedekKoku.'/disk-b');
        $plan->hedefler()->attach($hedefB, ['sakla_son_adet' => 14]);
        $ilk = $this->kopyaKaydiYazilmadanKesilen($plan);
        $this->assertSame(0, YedekKopyasi::query()->count());

        $this->elleYedekVeTemizlik();

        $this->assertFileExists($this->yerelDosya($ilk));
        $this->assertNull($ilk->refresh()->yerel_silinme_zamani);

        $this->planiCalistir($plan);

        foreach ([$hedefA, $hedefB] as $hedef) {
            $this->assertKopyalandi($ilk, $hedef);
        }
    }

    /**
     * Codex G1-6-R4: bekleyen hedef bağı kalkınca (hedef plandan çıktı, plan ya
     * da hedef pasif) koruma da kalkar, dosya elle yedek kuralına döner —
     * süresiz birikmez. Bir hedefe kopyası tamamlanmış yedekte, kaydı olmayan
     * diğer etkin hedef dosyayı tutar.
     */
    #[DataProvider('bekleyenBagKurgulari')]
    public function test_kopya_kaydi_bekleyen_plan_yedeginin_dosyasi_yalniz_hedef_bagi_etkinken_kalir(string $kurgu, bool $kalir): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
        $hedefA = $this->hedef('Disk A', $this->yedekKoku.'/disk-a');
        $plan = $this->vadesiGelenPlan('Günlük', $hedefA);
        $hedefB = $this->hedef('Disk B', $this->yedekKoku.'/disk-b');
        $plan->hedefler()->attach($hedefB, ['sakla_son_adet' => 14]);
        $ilk = $this->kopyaKaydiYazilmadanKesilen($plan);
        match ($kurgu) {
            'bir_hedef_tamam' => (function () use ($ilk, $hedefA): void {
                copy($this->yerelDosya($ilk), $hedefA->baglanti['klasor'].'/'.$ilk->dosya_adi);
                YedekKopyasi::query()->create(['yedek_id' => $ilk->id, 'yedek_hedefi_id' => $hedefA->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => $ilk->dosya_adi, 'sifreli' => false]);
            })(),
            'hedef_plandan_cikti' => $plan->hedefler()->detach([$hedefA->id, $hedefB->id]),
            'plan_pasif' => $plan->update(['aktif' => false]),
            'hedef_pasif' => YedekHedefi::query()->whereKey([$hedefA->id, $hedefB->id])->update(['aktif' => false]),
        };

        $this->elleYedekVeTemizlik();

        $this->assertSame($kalir, is_file($this->yerelDosya($ilk)));
    }

    /** @return array<string, array{string, bool}> */
    public static function bekleyenBagKurgulari(): array
    {
        return [
            'bir hedef tamam, diğerinde kayıt yok' => ['bir_hedef_tamam', true],
            'hedefler plandan çıkarıldı' => ['hedef_plandan_cikti', false],
            'plan pasif' => ['plan_pasif', false],
            'hedefler pasif' => ['hedef_pasif', false],
        ];
    }

    /**
     * Codex G1-6-R5: A ve B aynı hedefi kullanır; B'nin üç daha yeni kesilen
     * yedeği A'nın tek arşivini korumanın dışına itmez (pencere plan + hedef
     * başına). Temizlikten sonra yalnız A çalışır ve arşivini kopyalar.
     */
    public function test_ayni_hedefi_paylasan_planin_yeni_yedekleri_diger_planin_bekleyen_arsivini_temizlige_birakmaz(): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
        $hedef = $this->hedef('Ortak disk', $this->yedekKoku.'/ortak');
        $planA = $this->vadesiGelenPlan('A', $hedef);
        $planB = $this->vadesiGelenPlan('B', $hedef, CarbonImmutable::now()->addDays(5));
        $arsivA = $this->kopyaKaydiYazilmadanKesilen($planA);
        foreach (range(1, 3) as $_) {
            $this->travel(1)->hours();
            $this->kopyaKaydiYazilmadanKesilen($planB);
        }

        $this->elleYedekVeTemizlik();

        $this->assertFileExists($this->yerelDosya($arsivA));
        $this->planiCalistir($planA);
        $this->assertKopyalandi($arsivA, $hedef);
    }

    /**
     * Codex G1-6-R5: üç sınırı arşiv ve manifest doğrulandıktan SONRA sayılır.
     * Manifesti eksik üç yeni yedek (plan çalışması onları kopyalamaz) geçerli
     * eski arşivi korumanın dışına itmez; sonraki çalışma onu kopyalar.
     */
    public function test_manifesti_eksik_yeni_yedekler_gecerli_eski_arsivi_temizlige_birakmaz(): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
        $hedef = $this->hedef('Disk', $this->yedekKoku.'/disk');
        $plan = $this->vadesiGelenPlan('Günlük', $hedef);
        $eski = $this->kopyaKaydiYazilmadanKesilen($plan);
        foreach (range(1, 3) as $_) {
            $this->travel(1)->hours();
            $yeni = $this->kopyaKaydiYazilmadanKesilen($plan);
            unlink(ManifestDeposu::jsonYolu($this->yerelDosya($yeni)));
        }

        $this->elleYedekVeTemizlik();

        $this->assertFileExists($this->yerelDosya($eski));
        $this->planiCalistir($plan);
        $this->assertKopyalandi($eski, $hedef);
    }

    /**
     * Codex G1-6-R6: içeriği manifestle uyuşmayan (aynı boyutta bozulmuş) üç
     * yeni arşiv pencereyi doldurmaz — kopyalayıcı onları zaten reddeder.
     * Geçerli eski arşiv temizlikten sağ çıkar ve sonraki çalışmada kopyalanır.
     */
    public function test_icerigi_bozuk_yeni_arsivler_gecerli_eski_arsivi_temizlige_birakmaz(): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
        $hedef = $this->hedef('Disk', $this->yedekKoku.'/disk');
        $plan = $this->vadesiGelenPlan('Günlük', $hedef);
        $eski = $this->kopyaKaydiYazilmadanKesilen($plan);
        foreach (range(1, 3) as $_) {
            $this->travel(1)->hours();
            $yol = $this->yerelDosya($this->kopyaKaydiYazilmadanKesilen($plan));
            $icerik = (string) file_get_contents($yol);
            file_put_contents($yol, strrev($icerik) === $icerik ? str_repeat('x', strlen($icerik)) : strrev($icerik));
        }

        $this->elleYedekVeTemizlik();

        $this->assertFileExists($this->yerelDosya($eski));
        $this->planiCalistir($plan);
        $this->assertKopyalandi($eski, $hedef);
    }

    /**
     * Codex G1-6-R6 (F6): A (son 1) ve B (son 14) aynı hedefe birlikte yedek
     * aldı, kopya kayıtları yazılmadan süreç kesildi. Sonraki çalışmada yalnız
     * A'nın vakti gelir; kopya kaydı açılırken B'nin bağı da yazılır. A'nın
     * kuralı yalnız kendi bağını kaldırır, B'nin kopyası hedefte kalır.
     */
    public function test_kesintiden_sonra_tek_planin_tamamladigi_ortak_yedek_diger_planin_saklama_bagini_tasir(): void
    {
        $this->sahtePgAraclari();
        $hedef = $this->hedef('Ortak disk', $this->yedekKoku.'/ortak');
        $planA = YedekPlani::factory()->create(['ad' => 'A', 'siklik_turu' => SiklikTuru::Gunluk, 'saat' => '02:00']);
        $planA->hedefler()->attach($hedef, ['sakla_son_adet' => 1]);
        $planB = YedekPlani::factory()->create(['ad' => 'B', 'siklik_turu' => SiklikTuru::Gunluk, 'saat' => '02:00']);
        $planB->hedefler()->attach($hedef, ['sakla_son_adet' => 14]);
        $ortak = $this->kopyaKaydiYazilmadanKesilen($planA, $planB);

        $this->planiCalistir($planA);

        $kopya = YedekKopyasi::query()->where(['yedek_id' => $ortak->id, 'yedek_hedefi_id' => $hedef->id])->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame([$planB->id], $kopya->planlar()->pluck('yedek_planlari.id')->all());
        $this->assertFileExists($hedef->baglanti['klasor'].'/'.$ortak->dosya_adi);
    }

    /**
     * Codex G1-6-R7: yeniden denemede kopya kaydı ile saklama bağları tek
     * işlemde yazılır. Arada kesilen süreç bağsız `bekliyor` kaydı bırakmaz
     * (kayıt geri alınır); sonraki çalışma kaydı A ve B'nin bağlarıyla açar,
     * A'nın "son 1" kuralı B'nin kopyasını silmez.
     */
    public function test_yeniden_denemede_kayit_ile_baglar_arasinda_kesilince_kayit_geri_alinir_sonraki_calisma_baglari_tamamlar(): void
    {
        $this->sahtePgAraclari();
        $hedef = $this->hedef('Ortak disk', $this->yedekKoku.'/ortak');
        $planA = YedekPlani::factory()->create(['ad' => 'A', 'siklik_turu' => SiklikTuru::Gunluk, 'saat' => '02:00']);
        $planA->hedefler()->attach($hedef, ['sakla_son_adet' => 1]);
        $planB = YedekPlani::factory()->create(['ad' => 'B', 'siklik_turu' => SiklikTuru::Gunluk, 'saat' => '02:00']);
        $planB->hedefler()->attach($hedef, ['sakla_son_adet' => 14]);
        $ortak = $this->kopyaKaydiYazilmadanKesilen($planA, $planB);
        // Ortak yedeğin kaydı yazıldı, bağları yazılmadan süreç kesildi. Kesinti
        // kaydın kendi yazımından SONRA, bağ tablosunun okunduğu anda: test
        // işleminin içinde createOrFirst kaydı kendi savepoint'inde yazar, created
        // olayındaki hata o savepoint'le birlikte geri alınır (canlıda alınmaz)
        $kes = true;
        $ortakKopya = null;
        YedekKopyasi::created(function (YedekKopyasi $kopya) use (&$ortakKopya, $ortak): void {
            if ((int) $kopya->yedek_id === $ortak->id) {
                $ortakKopya = $kopya->id;
            }
        });
        DB::listen(function (QueryExecuted $sorgu) use (&$kes, &$ortakKopya): void {
            if ($kes && $ortakKopya !== null && str_contains($sorgu->sql, 'yedek_kopya_planlari')
                && str_starts_with(strtolower(ltrim($sorgu->sql)), 'select') && in_array($ortakKopya, $sorgu->bindings, false)) {
                throw new RuntimeException('süreç kesildi');
            }
        });
        $this->travel(1)->days();
        $planA->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        try {
            $this->artisan('yedek:zamanla');
        } catch (RuntimeException) {
            // Komut kesintiyi raporlayabilir ya da yukarı atabilir
        }
        $kes = false;
        $this->assertNotNull($ortakKopya, 'kesinti kurulamadı: ortak yedeğin kaydı hiç yazılmadı');
        $this->assertFalse(YedekKopyasi::query()->where(['yedek_id' => $ortak->id, 'yedek_hedefi_id' => $hedef->id])->exists());

        $this->planiCalistir($planA);

        $kopya = YedekKopyasi::query()->where(['yedek_id' => $ortak->id, 'yedek_hedefi_id' => $hedef->id])->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame([$planB->id], $kopya->planlar()->pluck('yedek_planlari.id')->all());
        $this->assertFileExists($hedef->baglanti['klasor'].'/'.$ortak->dosya_adi);
    }

    /**
     * Codex G1-6-R4: koruma plan çalışmasının tamamlayacağı kadardır — plan +
     * hedef başına en yeni PlanliYedek::YENIDEN_DENEME_ADET yedek. Daha eskisi
     * hiçbir zaman kopyalanmayacağı için elle yedek kuralıyla silinir.
     */
    public function test_kopya_kaydi_bekleyen_yedeklerden_yalniz_plan_calismasinin_tamamlayacagi_kadari_korunur(): void
    {
        $this->sahtePgAraclari();
        YedekAyari::query()->create(['enaz_adet' => 1, 'elle_gun' => 0]);
        $plan = $this->vadesiGelenPlan('Günlük', $this->hedef('Disk', $this->yedekKoku.'/disk'));
        $bekleyenler = [];
        foreach (range(1, PlanliYedek::YENIDEN_DENEME_ADET + 1) as $_) {
            $this->travel(1)->hours();
            $bekleyenler[] = $this->kopyaKaydiYazilmadanKesilen($plan);
        }

        $this->elleYedekVeTemizlik();

        $this->assertSame(
            [false, ...array_fill(0, PlanliYedek::YENIDEN_DENEME_ADET, true)],
            array_map(fn (Yedek $y): bool => is_file($this->yerelDosya($y)), $bekleyenler),
        );
    }

    /**
     * Plan çalışır; arşiv tamamlanır, kopya kayıtları yazılırken süreç kesilir
     * (G1-6). Planın sıradaki çalışması ileriye alınır: kendiliğinden yeniden
     * çalışmaz.
     */
    private function kopyaKaydiYazilmadanKesilen(YedekPlani ...$planlar): Yedek
    {
        foreach ($planlar as $plan) {
            $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        }
        $kes = true;
        YedekKopyasi::creating(function () use (&$kes): void {
            if ($kes) {
                throw new RuntimeException('süreç kesildi');
            }
        });
        try {
            $this->artisan('yedek:zamanla');
        } catch (RuntimeException) {
            // Komut kesintiyi raporlayabilir ya da yukarı atabilir; ikisinde de kayıt yok
        }
        $kes = false;
        foreach ($planlar as $plan) {
            $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->addDays(5)])->save();
        }
        $yedek = Yedek::query()->latest('id')->firstOrFail();
        $this->assertSame(YedekDurumu::Tamam, $yedek->durum);
        $this->assertFalse(YedekKopyasi::query()->where('yedek_id', $yedek->id)->exists());

        return $yedek;
    }

    /** Daha yeni elle yedek (ardından kendi temizliği) ve ayrı temizlik çalışması */
    private function elleYedekVeTemizlik(): void
    {
        $this->travel(1)->hours();
        $this->artisan('yedek:al')->assertSuccessful();
        $this->artisan('yedek:temizle')->assertSuccessful();
    }

    /** Bir gün sonra yalnız bu plan çalışır */
    private function planiCalistir(YedekPlani $plan): void
    {
        $this->travel(1)->days();
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        $this->artisan('yedek:zamanla')->assertSuccessful();
    }

    private function yerelDosya(Yedek $yedek): string
    {
        return config('yedekleme.klasor').'/'.$yedek->dosya_adi;
    }

    private function assertKopyalandi(Yedek $yedek, YedekHedefi $hedef): void
    {
        $kopya = YedekKopyasi::query()->where(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id])->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum, $hedef->ad);
        $this->assertFileExists($hedef->baglanti['klasor'].'/'.$yedek->dosya_adi);
    }

    /**
     * G1-9 (D8): kalıcı hatayla (ör. YAPILANDIRMA — yerel dosya bozuk, hedef
     * tanımı yanlış) düşen kopya her plan çalışmasında yeniden yüklenmez;
     * hedefin tanımı sonradan değişirse yeniden denenir.
     */
    public function test_kalici_hatayla_dusen_kopya_hedef_degismeden_yeniden_denenmez_hedef_guncellenince_denenir(): void
    {
        $this->sahtePgAraclari();
        $klasor = $this->yedekKoku.'/disk-gec';
        $hedef = YedekHedefi::factory()->create(['ad' => 'Geç bağlanan disk', 'baglanti' => ['klasor' => $klasor]]);
        $plan = $this->vadesiGelenPlan('Günlük', $hedef);
        $this->artisan('yedek:zamanla')->assertSuccessful();
        $kopya = YedekKopyasi::query()->sole();
        $kopya->forceFill(['hata_kodu' => HedefHatasi::YAPILANDIRMA, 'deneme' => 1])->save();
        mkdir($klasor, 0750, true);

        $this->travel(1)->days();
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->assertSame(KopyaDurumu::Basarisiz, $kopya->refresh()->durum);
        $this->assertSame(1, $kopya->deneme);

        $this->travel(1)->days();
        $hedef->refresh()->touch();
        $plan->forceFill(['sonraki_calisma' => CarbonImmutable::now()->subSeconds(30)])->save();
        $this->artisan('yedek:zamanla')->assertSuccessful();

        $this->assertSame(KopyaDurumu::Tamam, $kopya->refresh()->durum);
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
