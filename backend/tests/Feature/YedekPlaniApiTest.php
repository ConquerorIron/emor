<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\IstekKutusu;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\PlanliYedek;
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SilmeKarari;
use App\Services\Yedekleme\YedekKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-017: Yedek Planları API'si (README §4.2, §4.3; kullanıcı kararı F6).
 */
final class YedekPlaniApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const K = '/api/v1/ayarlar/yedek-planlari';

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true]);
        // Cumartesi 2026-11-21 12:00 İstanbul
        $this->travelTo(CarbonImmutable::parse('2026-11-21 09:00', 'UTC'));
        YedekAyari::query()->create(['enaz_adet' => 1]);
        $this->actingAs(User::factory()->yonetici()->create());
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $siklik
     * @param  list<array<string, int|null>>  $hedefler
     * @return array<string, mixed>
     */
    private function govde(string $ad, string $tur, string $saat, array $siklik, array $hedefler): array
    {
        return ['ad' => $ad, 'aktif' => true, 'siklik_turu' => $tur, 'saat' => $saat, 'siklik' => $siklik, 'hedefler' => $hedefler];
    }

    public function test_kullanicinin_ornegi_kurulur_her_gun_yerel_uc_gunde_bir_ikinci_haftalik_ucuncu_hedef(): void
    {
        [$yerel, $ikinci, $ucuncu] = [YedekHedefi::factory()->create(['ad' => 'Yerel']), YedekHedefi::factory()->create(['ad' => 'İkinci']), YedekHedefi::factory()->create(['ad' => 'Üçüncü'])];

        $this->postJson(self::K, $this->govde('Günlük', 'gunluk', '02:00', [], [['hedef_id' => $yerel->id, 'sakla_son_adet' => 7]]))
            ->assertCreated()->assertJsonPath('data.sonraki_calisma', '2026-11-21T23:00:00+00:00');
        $this->postJson(self::K, $this->govde('Üç günde bir', 'n_gunluk', '03:00', ['aralik' => 3, 'baslangic_tarihi' => '2026-11-22'], [['hedef_id' => $ikinci->id, 'sakla_gun' => 30]]))
            ->assertCreated()->assertJsonPath('data.sonraki_calisma', '2026-11-22T00:00:00+00:00');
        $this->postJson(self::K, $this->govde('Haftalık', 'haftalik', '04:00', ['gunler' => [7]], [['hedef_id' => $ucuncu->id, 'sakla_son_adet' => 4, 'sakla_gun' => 60]]))
            ->assertCreated()->assertJsonPath('data.sonraki_calisma', '2026-11-22T01:00:00+00:00');

        $liste = $this->getJson(self::K)->assertOk()->json('data');
        $this->assertSame(['Günlük', 'Haftalık', 'Üç günde bir'], array_column($liste, 'ad'));
        $this->assertSame([['hedef_id' => $ucuncu->id, 'ad' => 'Üçüncü', 'tur' => 'yerel', 'sakla_son_adet' => 4, 'sakla_gun' => 60, 'kopya_sayisi' => 0]], $liste[1]['hedefler']);
    }

    public function test_hedefsiz_ya_da_saklama_kuralsiz_plan_kaydedilemez(): void
    {
        $hedef = YedekHedefi::factory()->create();

        $this->postJson(self::K, $this->govde('Boş', 'gunluk', '02:00', [], []))
            ->assertStatus(422)->assertJsonValidationErrors('hedefler', 'hatalar');
        $this->postJson(self::K, $this->govde('Kuralsız', 'gunluk', '02:00', [], [['hedef_id' => $hedef->id, 'sakla_son_adet' => null, 'sakla_gun' => null]]))
            ->assertStatus(422)->assertJsonValidationErrors(['hedefler.0.sakla_son_adet', 'hedefler.0.sakla_gun'], 'hatalar');
        $this->postJson(self::K, $this->govde('Saat', 'gunluk', '24:00', [], [['hedef_id' => $hedef->id, 'sakla_son_adet' => 3]]))
            ->assertStatus(422)->assertJsonValidationErrors('saat', 'hatalar');
        $this->assertSame(0, YedekPlani::query()->count());
    }

    public function test_onizleme_kaydetmez_siradaki_calisma_kayittan_sonraki_ile_ayni(): void
    {
        $hedef = YedekHedefi::factory()->create();
        $govde = $this->govde('Saatlik', 'saatlik', '08:00', ['aralik' => 4, 'saat_baslangic' => '08:00', 'saat_bitis' => '20:00', 'gunler' => [1, 2, 3, 4, 5]], [['hedef_id' => $hedef->id, 'sakla_son_adet' => 10]]);

        $onizleme = $this->postJson(self::K.'/onizleme', $govde)->assertOk()->json('data');
        $this->assertSame(0, YedekPlani::query()->count());
        // Cumartesi: ilk çalışma pazartesi 08:00 İstanbul; 08–20 arası 4 saatte bir (20:00 dahil)
        $this->assertSame(['2026-11-23T05:00:00+00:00', '2026-11-23T09:00:00+00:00', '2026-11-23T13:00:00+00:00', '2026-11-23T17:00:00+00:00', '2026-11-24T05:00:00+00:00'], $onizleme['siradakiler']);

        $kayit = $this->postJson(self::K, $govde)->assertCreated()->json('data');
        $this->assertSame($onizleme['siradakiler'][0], $kayit['sonraki_calisma']);
    }

    /**
     * YF-31 (G10): saatlik planın günleri metin olarak gelse de ("1") tam sayı
     * saklanır; takvim onları yok sayıp planı her gün çalıştırmaz.
     */
    public function test_saatlik_plan_metin_gunlerle_kaydedilince_yalniz_o_gunlerde_calisir(): void
    {
        $hedef = YedekHedefi::factory()->create();
        $govde = $this->govde('Pazartesi', 'saatlik', '08:00', ['aralik' => 4, 'saat_baslangic' => '08:00', 'saat_bitis' => '20:00', 'gunler' => ['1']], [['hedef_id' => $hedef->id, 'sakla_son_adet' => 10]]);

        $kayit = $this->postJson(self::K, $govde)->assertCreated()->json('data');

        // Cumartesi: ilk çalışma pazartesi 08:00 İstanbul
        $this->assertSame('2026-11-23T05:00:00+00:00', $kayit['sonraki_calisma']);
        $this->assertSame([1], YedekPlani::query()->sole()->siklik['gunler']);
    }

    /**
     * YF-33 (G1, BY-014-R1): denetim günlüğü yazılamazsa plan değişikliği geri
     * alınır; kayıtsız silme / değişiklik olmaz.
     */
    public function test_denetim_gunlugu_yazilamazsa_plan_silinmez_500(): void
    {
        $plan = YedekPlani::factory()->create();
        touch($this->yedekKoku.'/dosya');
        config(['logging.channels.yedekleme.path' => $this->yedekKoku.'/dosya/yedekleme.log']);
        Log::forgetChannel('yedekleme');

        $this->deleteJson(self::K.'/'.$plan->id)->assertStatus(500);

        $this->assertModelExists($plan);
    }

    public function test_onizlemedeki_silinecekler_kayittan_sonra_saklamanin_hesabiyla_ayni(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'İkinci disk', 'baglanti' => ['klasor' => '/mnt/ikinci']]);
        $plan = YedekPlani::factory()->create(['ad' => 'Günlük']);
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => 10]);
        foreach ([5, 4, 3, 2] as $gun) {
            $yedek = Yedek::factory()->create(['kaynak' => YedekKaynagi::Plan, 'alinma_zamani' => CarbonImmutable::now()->subDays($gun)]);
            $kopya = YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam, 'uzak_kimlik' => $yedek->dosya_adi]);
            $kopya->planlar()->attach($plan->id, ['yedek_hedefi_id' => $hedef->id]);
        }
        $govde = ['id' => $plan->id, ...$this->govde('Günlük', 'gunluk', '02:00', [], [['hedef_id' => $hedef->id, 'sakla_son_adet' => 2]])];

        $onizleme = $this->postJson(self::K.'/onizleme', $govde)->assertOk()->json('data.silinecekler');
        $this->assertSame(10, $plan->hedefler()->first()->pivot->sakla_son_adet);

        $this->putJson(self::K.'/'.$plan->id, $govde)->assertOk();
        $gercek = array_values(array_filter(app(SaklamaPolitikasi::class)->hesapla()['silinecek'], fn (SilmeKarari $k): bool => $k->planId === $plan->id));

        $this->assertNotEmpty($onizleme);
        $this->assertEqualsCanonicalizing(array_map(fn (SilmeKarari $k): array => $k->diziye(), $gercek), array_map(fn (array $s): array => array_diff_key($s, ['dosya_adi' => 1, 'alinma_zamani' => 1]), array_filter($onizleme, fn (array $s): bool => $s['plan_id'] === $plan->id)));
    }

    public function test_cakisan_plan_ve_ayin_29_31i_uyari_ile_doner(): void
    {
        $hedef = YedekHedefi::factory()->create();
        $this->postJson(self::K, $this->govde('Gece', 'gunluk', '02:00', [], [['hedef_id' => $hedef->id, 'sakla_son_adet' => 3]]))->assertCreated();

        $cakisan = $this->postJson(self::K.'/onizleme', $this->govde('Gece 2', 'gunluk', '02:00', [], [['hedef_id' => $hedef->id, 'sakla_son_adet' => 3]]))->json('data');
        $ay = $this->postJson(self::K.'/onizleme', $this->govde('Ay sonu', 'aylik', '05:00', ['ayin_gunu' => 31], [['hedef_id' => $hedef->id, 'sakla_son_adet' => 3]]))->json('data');

        $this->assertSame(['Gece'], array_column($cakisan['cakisan_planlar'], 'ad'));
        $this->assertFalse($cakisan['ay_sonu_uyarisi']);
        $this->assertTrue($ay['ay_sonu_uyarisi']);
        $this->assertSame([], $ay['cakisan_planlar']);
    }

    public function test_kopyasi_olan_hedef_plandan_cikarilamaz_409_kopya_sayisiyla(): void
    {
        [$a, $b] = [YedekHedefi::factory()->create(['ad' => 'A']), YedekHedefi::factory()->create(['ad' => 'B'])];
        $plan = YedekPlani::factory()->create(['ad' => 'Plan']);
        $plan->hedefler()->attach([$a->id => ['sakla_son_adet' => 3], $b->id => ['sakla_son_adet' => 3]]);
        $yedek = Yedek::factory()->create();
        YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $b->id, 'durum' => KopyaDurumu::Tamam])
            ->planlar()->attach($plan->id, ['yedek_hedefi_id' => $b->id]);

        $this->putJson(self::K.'/'.$plan->id, $this->govde('Plan', 'gunluk', '02:00', [], [['hedef_id' => $a->id, 'sakla_son_adet' => 3]]))
            ->assertStatus(409)->assertJsonPath('kod', 'PLAN_HEDEFI_KOPYALI')
            ->assertJsonPath('hedefler', [['hedef_id' => $b->id, 'ad' => 'B', 'kopya_sayisi' => 1]]);

        $this->assertSame(2, $plan->hedefler()->count());
        // Kopyası olan plan da silinmez; pasife alınır
        $this->deleteJson(self::K.'/'.$plan->id)->assertStatus(409)->assertJsonPath('kod', 'PLAN_KOPYALI');
    }

    public function test_pasife_alinan_planin_sonraki_calismasi_gosterilmez_gecmisi_durur(): void
    {
        $hedef = YedekHedefi::factory()->create();
        $plan = YedekPlani::factory()->create(['ad' => 'Gece', 'son_calisma' => CarbonImmutable::parse('2026-11-20 23:00', 'UTC')]);
        $plan->hedefler()->attach($hedef, ['sakla_son_adet' => 3]);

        $yanit = $this->putJson(self::K.'/'.$plan->id, [...$this->govde('Gece', 'gunluk', '02:00', [], [['hedef_id' => $hedef->id, 'sakla_son_adet' => 3]]), 'aktif' => false])->assertOk();

        $yanit->assertJsonPath('data.aktif', false)->assertJsonPath('data.sonraki_calisma', null)->assertJsonPath('data.son_calisma', '2026-11-20T23:00:00+00:00');
    }

    /**
     * Son çalışmasının yedeği `$yedek` olan, o çalışmada `$hedefler`e
     * kopyalanmış plan (PlanliYedek'in yazdığı bağ). `$yedek` null: hiç çalışmadı.
     *
     * @param  list<YedekHedefi>  $hedefler
     */
    private function calismisPlan(string $ad, array $hedefler, ?Yedek $yedek): YedekPlani
    {
        $hedefIdleri = array_map(fn (YedekHedefi $h): int => $h->id, $hedefler);
        $plan = YedekPlani::factory()->create(['ad' => $ad]);
        $plan->forceFill(['son_calisma' => $yedek === null ? null : CarbonImmutable::now(), 'son_yedek_id' => $yedek?->id, 'son_hedef_idleri' => $yedek === null ? null : $hedefIdleri])->save();
        $plan->hedefler()->attach(array_fill_keys($hedefIdleri, ['sakla_son_adet' => 3]));

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $ozellik
     */
    private function planYedegi(array $ozellik = []): Yedek
    {
        return Yedek::factory()->plan()->create($ozellik);
    }

    public function test_son_calismanin_sonucu_basarili_yedek_hatali_ve_hedef_hatali_ayri_doner(): void
    {
        [$yerel, $ikinci] = [YedekHedefi::factory()->create(['ad' => 'Yerel']), YedekHedefi::factory()->create(['ad' => 'İkinci'])];

        $a = $this->planYedegi();
        YedekKopyasi::query()->create(['yedek_id' => $a->id, 'yedek_hedefi_id' => $yerel->id, 'durum' => KopyaDurumu::Tamam]);
        $this->calismisPlan('A Başarılı', [$yerel], $a);
        $this->calismisPlan('B Dump hatalı', [$yerel], $this->planYedegi(['durum' => 'basarisiz', 'hata_kodu' => 'PG_DUMP', 'alinma_zamani' => null]));
        $c = $this->planYedegi();
        YedekKopyasi::query()->create(['yedek_id' => $c->id, 'yedek_hedefi_id' => $yerel->id, 'durum' => KopyaDurumu::Tamam]);
        YedekKopyasi::query()->create(['yedek_id' => $c->id, 'yedek_hedefi_id' => $ikinci->id, 'durum' => KopyaDurumu::Basarisiz, 'hata_kodu' => 'HEDEF_ERISILEMEDI']);
        $this->calismisPlan('C Hedef hatalı', [$yerel, $ikinci], $c);
        $this->calismisPlan('D Hiç çalışmadı', [$yerel], null);

        $sonuclar = collect($this->getJson(self::K)->assertOk()->json('data'))->pluck('son_sonuc', 'ad')->all();

        $this->assertSame([
            'A Başarılı' => ['durum' => 'basarili', 'hata_kodu' => null, 'basarisiz_hedefler' => []],
            'B Dump hatalı' => ['durum' => 'yedek_basarisiz', 'hata_kodu' => 'PG_DUMP', 'basarisiz_hedefler' => []],
            'C Hedef hatalı' => ['durum' => 'hedef_basarisiz', 'hata_kodu' => null, 'basarisiz_hedefler' => [['hedef_id' => $ikinci->id, 'ad' => 'İkinci', 'hata_kodu' => 'HEDEF_ERISILEMEDI']]],
            'D Hiç çalışmadı' => null,
        ], $sonuclar);
    }

    /**
     * BY-017-R3: zaman dondurulmuş — iki ayrı çalışma aynı saniyede biter ve
     * açılır. Birinin yedeği ötekinin sonucu sayılmaz.
     */
    public function test_ayni_saniyede_ardisik_iki_calismada_her_plan_kendi_sonucunu_gosterir(): void
    {
        $this->sahtePgAraclari();
        Sleep::fake();
        $disk = YedekHedefi::factory()->create(['ad' => 'Disk', 'baglanti' => ['klasor' => $this->yedekKoku.'/disk-yok']]);
        mkdir($this->yedekKoku.'/disk-b', 0750, true);
        $diskB = YedekHedefi::factory()->create(['ad' => 'Disk B', 'baglanti' => ['klasor' => $this->yedekKoku.'/disk-b']]);
        [$a, $b] = [$this->calismisPlan('A', [$disk], null), $this->calismisPlan('B', [$diskB], null)];
        $kilit = $this->kilitAl();

        // A: disk yok → hedef hatası. B: aynı saniyede, kendi (sağlam) hedefiyle → başarılı.
        // B ayrı hedefte: aynı hedef düzelseydi A'nın kopyası yeniden denenip tamamlanırdı (YF-04)
        $yedekA = app(PlanliYedek::class)->calistir(YedekPlani::query()->whereKey($a->id)->get(), $kilit);
        app(PlanliYedek::class)->calistir(YedekPlani::query()->whereKey($b->id)->get(), $kilit);

        $sonuclar = collect($this->getJson(self::K)->assertOk()->json('data'))->pluck('son_sonuc', 'ad')->all();

        $hataKodu = $yedekA->kopyalar()->sole()->hata_kodu;
        $this->assertNotNull($hataKodu);
        $this->assertSame([
            'A' => ['durum' => 'hedef_basarisiz', 'hata_kodu' => null, 'basarisiz_hedefler' => [['hedef_id' => $disk->id, 'ad' => 'Disk', 'hata_kodu' => $hataKodu]]],
            'B' => ['durum' => 'basarili', 'hata_kodu' => null, 'basarisiz_hedefler' => []],
        ], $sonuclar);
    }

    /** Aynı ana düşen planlar tek yedeği paylaşır; her plan yalnız kendi hedeflerinin sonucunu görür. */
    public function test_tek_yedegi_paylasan_planlarda_digerinin_hedef_hatasi_sonuca_girmez(): void
    {
        $this->sahtePgAraclari();
        Sleep::fake();
        mkdir($this->yedekKoku.'/disk-2', 0750, true);
        $iyi = YedekHedefi::factory()->create(['ad' => 'Disk 2', 'baglanti' => ['klasor' => $this->yedekKoku.'/disk-2']]);
        $bozuk = YedekHedefi::factory()->create(['ad' => 'Bozuk', 'baglanti' => ['klasor' => '/proc/yedek-yazilamaz']]);
        $a = $this->calismisPlan('A', [$iyi], null);
        $b = $this->calismisPlan('B', [$bozuk], null);

        app(PlanliYedek::class)->calistir(YedekPlani::query()->whereKey([$a->id, $b->id])->get(), $this->kilitAl());

        $sonuclar = collect($this->getJson(self::K)->assertOk()->json('data'))->pluck('son_sonuc.durum', 'ad')->all();
        $this->assertSame(['A' => 'basarili', 'B' => 'hedef_basarisiz'], $sonuclar);
    }

    public function test_calismadan_sonra_basarisiz_hedef_plandan_cikarilinca_son_sonuc_degismez(): void
    {
        $this->sahtePgAraclari();
        Sleep::fake();
        mkdir($this->yedekKoku.'/disk-2', 0750, true);
        $iyi = YedekHedefi::factory()->create(['ad' => 'Disk 2', 'baglanti' => ['klasor' => $this->yedekKoku.'/disk-2']]);
        $bozuk = YedekHedefi::factory()->create(['ad' => 'Bozuk', 'baglanti' => ['klasor' => '/proc/yedek-yazilamaz']]);
        $plan = $this->calismisPlan('Gece', [$iyi, $bozuk], null);
        app(PlanliYedek::class)->calistir(YedekPlani::query()->whereKey($plan->id)->get(), $this->kilitAl());

        // Başarısız kopyanın plan bağı (G1-2) kuralı tutmaz: hedef plandan çıkarılabilir (F6 engeli yalnız yedeği tutan kopyada)
        $yanit = $this->putJson(self::K.'/'.$plan->id, $this->govde('Gece', 'gunluk', '02:00', [], [['hedef_id' => $iyi->id, 'sakla_son_adet' => 3]]))->assertOk();

        $this->assertSame([$iyi->id], array_column($yanit->json('data.hedefler'), 'hedef_id'));
        $this->assertSame('hedef_basarisiz', $yanit->json('data.son_sonuc.durum'));
        $this->assertSame([[$bozuk->id, 'Bozuk']], array_map(fn (array $h): array => [$h['hedef_id'], $h['ad']], $yanit->json('data.son_sonuc.basarisiz_hedefler')));
    }

    /**
     * G1-2: planlı yedeğin kopyası planına kayıt açılırken bağlanır; yalnız
     * başarısız kopyası olan plan yine silinebilir (bağ bir şey tutmuyor).
     */
    public function test_yalniz_basarisiz_kopyasi_olan_plan_silinir(): void
    {
        $this->sahtePgAraclari();
        Sleep::fake();
        $bozuk = YedekHedefi::factory()->create(['ad' => 'Bozuk', 'baglanti' => ['klasor' => '/proc/yedek-yazilamaz']]);
        $plan = $this->calismisPlan('Gece', [$bozuk], null);
        app(PlanliYedek::class)->calistir(YedekPlani::query()->whereKey($plan->id)->get(), $this->kilitAl());
        $kopya = YedekKopyasi::query()->sole();
        $this->assertSame(KopyaDurumu::Basarisiz, $kopya->durum);

        $this->deleteJson(self::K.'/'.$plan->id)->assertNoContent();

        $this->assertModelMissing($plan);
        $this->assertModelExists($kopya);
    }

    public function test_plan_listesi_sorgu_sayisi_plan_sayisindan_bagimsizdir_degerler_degismez(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Yerel']);
        $yedek = $this->planYedegi();
        $ilk = $this->calismisPlan('Plan 1', [$hedef], $yedek);
        YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam])
            ->planlar()->attach($ilk->id, ['yedek_hedefi_id' => $hedef->id]);
        $sorguSayisi = function (): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $veri = $this->getJson(self::K)->assertOk()->json('data');
            DB::disableQueryLog();

            return [count(DB::getQueryLog()), $veri];
        };
        [$tekPlanda] = $sorguSayisi();

        foreach (['Plan 2', 'Plan 3', 'Plan 4'] as $ad) {
            $yedek = $this->planYedegi();
            $plan = $this->calismisPlan($ad, [$hedef, YedekHedefi::factory()->create()], $yedek);
            $kopya = YedekKopyasi::query()->create(['yedek_id' => $yedek->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam]);
            $kopya->planlar()->attach($plan->id, ['yedek_hedefi_id' => $hedef->id]);
        }
        [$dortPlanda, $veri] = $sorguSayisi();

        $this->assertSame($tekPlanda, $dortPlanda);
        $this->assertSame([1, 1, 1, 1], array_map(fn (array $p): int => collect($p['hedefler'])->firstWhere('hedef_id', $hedef->id)['kopya_sayisi'], $veri));
        $this->assertSame([0, 0, 0], array_map(fn (array $p): int => collect($p['hedefler'])->firstWhere('hedef_id', '!=', $hedef->id)['kopya_sayisi'], array_slice($veri, 1)));
        $this->assertSame(array_fill(0, 4, 'basarili'), array_map(fn (array $p): string => $p['son_sonuc']['durum'], $veri));
    }

    public function test_simdi_calistir_istek_birakir(): void
    {
        $plan = YedekPlani::factory()->create();

        $this->postJson(self::K.'/'.$plan->id.'/calistir')->assertStatus(202);

        $istek = json_decode((string) file_get_contents(app(IstekKutusu::class)->bekleyenler()[0]), true);
        $this->assertSame(['plan_calistir', ['plan_id' => $plan->id]], [$istek['tur'], $istek['parametreler']]);
    }

    /** YF-11: yanıtı kaybolan "Şimdi Çalıştır"ın aynı anahtarla yeniden denemesi ikinci iş bırakmaz */
    public function test_ayni_istek_anahtariyla_iki_calistir_ayni_istegi_doner_tek_dosya_yazar(): void
    {
        $plan = YedekPlani::factory()->create();
        $anahtar = (string) Str::uuid();

        $ilk = $this->postJson(self::K.'/'.$plan->id.'/calistir', [], ['Idempotency-Key' => $anahtar])
            ->assertStatus(202)->json('data.istek_kimligi');
        $tekrar = $this->postJson(self::K.'/'.$plan->id.'/calistir', [], ['Idempotency-Key' => $anahtar])
            ->assertStatus(202)->json('data.istek_kimligi');

        $this->assertSame($ilk, $tekrar);
        $this->assertSame([$ilk.'.json'], array_map('basename', app(IstekKutusu::class)->bekleyenler()));
    }
}
