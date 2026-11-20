<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeriYukleme;
use App\Models\Yedek;
use App\Models\YedekHedefi;
use App\Models\YedekKopyasi;
use App\Services\Yedekleme\DizinKurucu;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\OlayTuru;
use App\Services\Yedekleme\YedekAlici;
use App\Services\Yedekleme\YedekDurumu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekKopyalayici;
use App\Services\Yedekleme\YedekOlayi;
use App\Services\Yedekleme\YerelDosyaSilici;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-011: klasör tarama, dizini yeniden kurma (README §5.2, §7.3, §4.5).
 */
final class DizinKurucuTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        $this->sahtePgAraclari();
        Sleep::fake();
        BellekHedefi::sifirla();
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function gercekYedek(): Yedek
    {
        $yedek = app(YedekAlici::class)->al($this->kilitAl(), YedekKaynagi::Elle, 'Yıl sonu');
        $this->tutulanKilit?->birak();

        return $yedek;
    }

    private function dizinleriBosalt(): void
    {
        DB::table('yedek_kopya_planlari')->delete();
        YedekKopyasi::query()->delete();
        Yedek::query()->delete();
    }

    private function tara(): array
    {
        return app(DizinKurucu::class)->tara();
    }

    public function test_tablolar_bosaltilip_tarama_calisinca_liste_eski_haline_doner_sabitleme_durur(): void
    {
        $yedek = $this->gercekYedek();
        $yedek->update(['sabit' => true]);
        app(OlayGunlugu::class)->ekle(YedekOlayi::simdi($yedek->kimlik, OlayTuru::Sabitlendi));
        $this->dizinleriBosalt();

        $this->tara();

        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $this->assertSame($yedek->dosya_adi, $kurulan->dosya_adi);
        $this->assertTrue($kurulan->sabit);
        $this->assertTrue($kurulan->imzali);
        $this->assertSame('Yıl sonu', $kurulan->aciklama);
        $this->assertSame(YedekDurumu::Tamam, $kurulan->durum);
    }

    public function test_imzasiz_dosya_guvenilmeyen_olarak_listelenir_geri_yuklenemez(): void
    {
        mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(config('yedekleme.klasor').'/erp_predeploy_2026-08-05_101010.dump', 'PGDMP-eski');

        $sonuc = $this->tara();

        $yedek = Yedek::query()->sole();
        $this->assertSame(1, $sonuc['guvenilmeyen']);
        $this->assertFalse($yedek->imzali);
        $this->assertFalse($yedek->geriYuklenebilirMi());
        $this->assertSame(YedekKaynagi::YayinOncesi, $yedek->kaynak);
    }

    public function test_tarama_iki_kez_calisinca_kayit_cogalmaz(): void
    {
        $this->gercekYedek();
        file_put_contents(config('yedekleme.klasor').'/erp_predeploy_2026-08-05_101010.dump', 'PGDMP-eski');

        $this->tara();
        $this->tara();

        $this->assertSame(2, Yedek::query()->count());
    }

    public function test_tek_bozuk_manifest_taramanin_tamamini_durdurmaz(): void
    {
        $yedek = $this->gercekYedek();
        file_put_contents(config('yedekleme.klasor').'/erp_bozuk.json', '{"manifest": {');
        $this->dizinleriBosalt();

        $sonuc = $this->tara();

        $this->assertSame(1, $sonuc['bozuk']);
        $this->assertTrue(Yedek::query()->where('kimlik', $yedek->kimlik)->exists());
    }

    /** Kayıt silinip açılmaz: geri yükleme geçmişi (RESTRICT) olan yedekte tarama hatasız (Fable F19). */
    public function test_geri_yukleme_gecmisi_olan_yedekte_tarama_kaydi_kimligiyle_gunceller(): void
    {
        $yedek = $this->gercekYedek();
        GeriYukleme::query()->create([
            'kimlik' => (string) Str::uuid(), 'yedek_id' => $yedek->id, 'tur' => GeriYuklemeTuru::Kopya,
            'hedef_veritabani' => 'erp_geri_20261121_101500', 'durum' => GeriYuklemeDurumu::Tamam,
        ]);

        $this->tara();

        $this->assertSame($yedek->id, Yedek::query()->where('kimlik', $yedek->kimlik)->sole()->id);
    }

    public function test_diskte_olmayan_kayit_silinmez_dosya_yok_olur(): void
    {
        $yedek = $this->gercekYedek();
        unlink(config('yedekleme.klasor').'/'.$yedek->dosya_adi);
        unlink(config('yedekleme.klasor').'/'.str_replace('.dump', '.json', $yedek->dosya_adi));

        $sonuc = $this->tara();

        $yedek->refresh();
        $this->assertSame(1, $sonuc['dosyasi_yok']);
        $this->assertNotNull($yedek->yerel_silinme_zamani);
        $this->assertSame(YedekDurumu::Silindi, $yedek->durum);
    }

    /** F7: yerel dosyası silinmiş, uzakta doğrulanmış kopyası olan yedek dizin yeniden kurulunca listede ve `silindi` değil. */
    public function test_yerel_dosyasi_silinmis_uzak_kopyali_yedek_yeniden_kurulunca_bulunur(): void
    {
        $yedek = $this->gercekYedek();
        $hedef = YedekHedefi::factory()->create(['ad' => 'Uzak', 'tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);
        app(YedekKopyalayici::class)->kopyala($yedek, $hedef);
        app(YerelDosyaSilici::class)->sil($yedek->refresh(), uzakKopyaSart: true);
        $this->dizinleriBosalt();

        $this->tara();

        $kurulan = Yedek::query()->where('kimlik', $yedek->kimlik)->sole();
        $this->assertSame(YedekDurumu::Tamam, $kurulan->durum);
        $this->assertNotNull($kurulan->yerel_silinme_zamani);
        $kopya = YedekKopyasi::query()->where('yedek_id', $kurulan->id)->sole();
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame($hedef->id, $kopya->yedek_hedefi_id);
    }

    public function test_dosya_kimligi_ayni_dosya_icin_hep_ayni_uuid(): void
    {
        $kimlik = DizinKurucu::dosyaKimligi('erp_predeploy_2026-08-05_101010.dump');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $kimlik);
        $this->assertSame($kimlik, DizinKurucu::dosyaKimligi('erp_predeploy_2026-08-05_101010.dump'));
    }
}
