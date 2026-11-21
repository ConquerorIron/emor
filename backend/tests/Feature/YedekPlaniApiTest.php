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
use App\Services\Yedekleme\SaklamaPolitikasi;
use App\Services\Yedekleme\SilmeKarari;
use App\Services\Yedekleme\YedekKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        mkdir(config('yedekleme.klasor'), 0750, true);
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
     * Son çalışması `$sonCalisma`'da biten plan (UTC, bugün).
     *
     * @param  list<YedekHedefi>  $hedefler
     */
    private function calismisPlan(string $ad, array $hedefler, string $sonCalisma): YedekPlani
    {
        $plan = YedekPlani::factory()->create(['ad' => $ad, 'son_calisma' => CarbonImmutable::parse('2026-11-21 '.$sonCalisma, 'UTC')]);
        $plan->hedefler()->attach(array_fill_keys(array_map(fn (YedekHedefi $h): int => $h->id, $hedefler), ['sakla_son_adet' => 3]));

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $ozellik
     */
    private function planYedegi(string $acilis, array $ozellik = []): Yedek
    {
        return Yedek::factory()->plan()->create(['created_at' => CarbonImmutable::parse('2026-11-21 '.$acilis, 'UTC'), ...$ozellik]);
    }

    public function test_son_calismanin_sonucu_basarili_yedek_hatali_ve_hedef_hatali_ayri_doner(): void
    {
        [$yerel, $ikinci] = [YedekHedefi::factory()->create(['ad' => 'Yerel']), YedekHedefi::factory()->create(['ad' => 'İkinci'])];
        $this->calismisPlan('A Başarılı', [$yerel], '06:00');
        $this->calismisPlan('B Dump hatalı', [$yerel], '07:00');
        $this->calismisPlan('C Hedef hatalı', [$yerel, $ikinci], '08:00');
        $this->calismisPlan('D Hiç çalışmadı', [$yerel], '05:00')->forceFill(['son_calisma' => null])->save();

        $a = $this->planYedegi('05:59');
        YedekKopyasi::query()->create(['yedek_id' => $a->id, 'yedek_hedefi_id' => $yerel->id, 'durum' => KopyaDurumu::Tamam]);
        $this->planYedegi('06:59', ['durum' => 'basarisiz', 'hata_kodu' => 'PG_DUMP', 'alinma_zamani' => null]);
        $c = $this->planYedegi('07:59');
        YedekKopyasi::query()->create(['yedek_id' => $c->id, 'yedek_hedefi_id' => $yerel->id, 'durum' => KopyaDurumu::Tamam]);
        YedekKopyasi::query()->create(['yedek_id' => $c->id, 'yedek_hedefi_id' => $ikinci->id, 'durum' => KopyaDurumu::Basarisiz, 'hata_kodu' => 'HEDEF_ERISILEMEDI']);
        // Plan dışı ve son çalışmadan sonra açılan yedekler sonucu etkilemez
        Yedek::factory()->create(['created_at' => CarbonImmutable::parse('2026-11-21 07:30', 'UTC'), 'durum' => 'basarisiz']);
        $this->planYedegi('08:30', ['durum' => 'basarisiz', 'hata_kodu' => 'DISK_DOLU']);

        $sonuclar = collect($this->getJson(self::K)->assertOk()->json('data'))->pluck('son_sonuc', 'ad')->all();

        $this->assertSame([
            'A Başarılı' => ['durum' => 'basarili', 'hata_kodu' => null, 'basarisiz_hedefler' => []],
            'B Dump hatalı' => ['durum' => 'yedek_basarisiz', 'hata_kodu' => 'PG_DUMP', 'basarisiz_hedefler' => []],
            'C Hedef hatalı' => ['durum' => 'hedef_basarisiz', 'hata_kodu' => null, 'basarisiz_hedefler' => [['hedef_id' => $ikinci->id, 'ad' => 'İkinci', 'hata_kodu' => 'HEDEF_ERISILEMEDI']]],
            'D Hiç çalışmadı' => null,
        ], $sonuclar);
    }

    public function test_plan_listesi_sorgu_sayisi_plan_sayisindan_bagimsizdir_degerler_degismez(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Yerel']);
        $ilk = $this->calismisPlan('Plan 1', [$hedef], '06:00');
        $yedek = $this->planYedegi('05:59');
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

        foreach (['Plan 2' => ['06:50', '06:55'], 'Plan 3' => ['07:00', '07:05'], 'Plan 4' => ['07:10', '07:15']] as $ad => [$acilis, $bitis]) {
            $plan = $this->calismisPlan($ad, [$hedef, YedekHedefi::factory()->create()], $bitis);
            $kopya = YedekKopyasi::query()->create(['yedek_id' => $this->planYedegi($acilis)->id, 'yedek_hedefi_id' => $hedef->id, 'durum' => KopyaDurumu::Tamam]);
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
}
