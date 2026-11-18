<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeriYukleme;
use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekKopyasi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\GeriYuklemeDurumu;
use App\Services\Yedekleme\GeriYuklemeTuru;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\YedekKaynagi;
use App\Support\KayitSilme;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * Yedekleme veri modelinin sözleşmeleri (BY-004). Hedef / plan uçları henüz
 * yok (BY-016, BY-017): silme, uçların kullanacağı KayitSilme üzerinden sınanır.
 */
final class YedeklemeVeriModeliTest extends TestCase
{
    use RefreshDatabase;

    public function test_hedef_sirri_sifreli_saklanir_ve_serilestirmede_donmez(): void
    {
        $hedef = YedekHedefi::factory()->ftp()->create();

        $ham = (string) DB::table('yedek_hedefleri')->where('id', $hedef->id)->value('gizli_bilgiler');
        $this->assertStringNotContainsString('deneme-ftp-sifresi', $ham);
        $this->assertSame(['sifre' => 'deneme-ftp-sifresi'], $hedef->fresh()?->gizli_bilgiler);

        $this->assertArrayNotHasKey('gizli_bilgiler', $hedef->toArray());
        $this->assertStringNotContainsString('deneme-ftp-sifresi', $hedef->toJson());
    }

    public function test_plana_bagli_hedef_silinemez_ve_409_doner(): void
    {
        $hedef = YedekHedefi::factory()->create();
        YedekPlani::factory()->create()->hedefler()->attach($hedef, ['sakla_son_adet' => 14]);

        $this->silinemez($hedef);
    }

    public function test_kopyasi_olan_hedef_silinemez_ve_409_doner(): void
    {
        $kopya = YedekKopyasi::factory()->plansiz()->create();

        $this->silinemez($kopya->hedef);
    }

    public function test_kopyasi_olan_plan_silinemez_ve_409_doner(): void
    {
        $kopya = YedekKopyasi::factory()->create();

        $this->silinemez($kopya->planlar()->first());
    }

    public function test_kopyasi_olmayan_plan_silinince_hedef_baglari_gider_hedef_kalir(): void
    {
        $hedef = YedekHedefi::factory()->create();
        $plan = YedekPlani::factory()->create();
        $plan->hedefler()->attach($hedef, ['sakla_gun' => 30]);

        KayitSilme::sil($plan);

        $this->assertModelMissing($plan);
        $this->assertDatabaseMissing('yedek_plan_hedefleri', ['yedek_plani_id' => $plan->id]);
        $this->assertModelExists($hedef);
    }

    public function test_kullanilmayan_hedef_silinir(): void
    {
        $hedef = YedekHedefi::factory()->create();

        KayitSilme::sil($hedef);

        $this->assertModelMissing($hedef);
    }

    public function test_yedek_kaydi_silinince_kopyalari_ve_plan_baglari_gider_hedef_ve_plan_kalir(): void
    {
        $kopya = YedekKopyasi::factory()->create();
        $plan = $kopya->planlar()->sole();

        $kopya->yedek?->delete();

        $this->assertModelMissing($kopya);
        $this->assertDatabaseMissing('yedek_kopya_planlari', ['yedek_kopyasi_id' => $kopya->id]);
        $this->assertModelExists($kopya->hedef);
        $this->assertModelExists($plan);
    }

    public function test_geri_yukleme_gecmisi_olan_yedek_kaydi_silinemez_ve_409_doner(): void
    {
        $yedek = Yedek::factory()->create();
        GeriYukleme::query()->create([
            'kimlik' => (string) Str::uuid(),
            'yedek_id' => $yedek->id,
            'tur' => GeriYuklemeTuru::Kopya,
            'hedef_veritabani' => 'erp_geri_20261118_100000',
            'durum' => GeriYuklemeDurumu::Tamam,
        ]);

        $this->silinemez($yedek);
    }

    /**
     * Bir yedeğin bir hedefte TEK fiziksel kopyası vardır; planı olsun olmasın
     * ikinci satır açılamaz (Fable incelemesi F6: iki satır tek dosyayı
     * temsil ederse bir planın temizliği diğerinin "tamam" kopyasını siler).
     */
    public function test_ayni_yedegin_ayni_hedefteki_ikinci_kopyasi_reddedilir(): void
    {
        $kopya = YedekKopyasi::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        YedekKopyasi::factory()->create([
            'yedek_id' => $kopya->yedek_id,
            'yedek_hedefi_id' => $kopya->yedek_hedefi_id,
        ]);
    }

    public function test_ayni_hedefi_paylasan_planlar_tek_kopyaya_baglanir_yedek_baska_hedefe_de_kopyalanir(): void
    {
        $kopya = YedekKopyasi::factory()->create();
        $ikinciPlan = YedekPlani::factory()->create();
        $ikinciPlan->hedefler()->attach($kopya->yedek_hedefi_id, ['sakla_son_adet' => 24]);

        $kopya->planlar()->attach($ikinciPlan);
        $baskaHedef = YedekKopyasi::factory()->create(['yedek_id' => $kopya->yedek_id]);

        $this->assertSame(2, $kopya->planlar()->count());
        $this->assertSame(2, YedekKopyasi::query()->where('yedek_id', $kopya->yedek_id)->count());
        $this->assertNotSame($kopya->yedek_hedefi_id, $baskaHedef->yedek_hedefi_id);
    }

    public function test_ayni_plan_ayni_kopyaya_ikinci_kez_baglanamaz(): void
    {
        $kopya = YedekKopyasi::factory()->create();
        $plan = $kopya->planlar()->sole();

        $this->expectException(UniqueConstraintViolationException::class);

        $kopya->planlar()->attach($plan);
    }

    public function test_planli_kopya_factorysi_plan_yedegini_saklama_kurali_olan_plan_hedef_bagina_baglar(): void
    {
        $kopya = YedekKopyasi::factory()->create();
        $plansiz = YedekKopyasi::factory()->plansiz()->create();

        $this->assertSame(YedekKaynagi::Plan, $kopya->yedek?->kaynak);
        $this->assertStringStartsWith('erp_plan_', (string) $kopya->yedek->dosya_adi);
        $bag = $kopya->planlar()->sole()->hedefler()->whereKey($kopya->yedek_hedefi_id)->first();
        $this->assertNotNull($bag);
        $this->assertSame(14, $bag->pivot->sakla_son_adet);

        $this->assertSame(YedekKaynagi::Elle, $plansiz->yedek?->kaynak);
        $this->assertFalse($plansiz->planlar()->exists());
    }

    /**
     * Denetim kaydı salt eklemelidir (README §4.9); kural modelde zorlanır
     * (Dikkat_Edilecekler K3, Fable incelemesi F10).
     */
    public function test_islem_kaydi_guncellenemez_ve_silinemez_kayit_degismeden_kalir(): void
    {
        $kayit = YedekIslemKaydi::query()->create(['islem' => 'yedek_al', 'sonuc' => IslemSonucu::Basarili]);

        try {
            $kayit->update(['sonuc' => IslemSonucu::Basarisiz]);
            $this->fail('Denetim kaydı güncellendi.');
        } catch (LogicException) {
        }

        try {
            $kayit->delete();
            $this->fail('Denetim kaydı silindi.');
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('yedek_islem_kayitlari', ['id' => $kayit->id, 'sonuc' => 'basarili']);
        $this->assertSame(1, YedekIslemKaydi::query()->count());
    }

    /**
     * Varsayılanlar veritabanında; model de aynılarını kaydetmeden önce taşır
     * (Dikkat_Edilecekler K4, Fable incelemesi F11): yeni nesnede null olsaydı
     * "en az N yedek tut" hesabı 0 ile çalışırdı.
     */
    public function test_yeni_yedek_ayari_veritabani_varsayilanlarini_kaydetmeden_once_de_tasir(): void
    {
        $alanlar = ['anahtar', 'yayin_oncesi_son_adet', 'yayin_oncesi_gun', 'elle_gun', 'geri_yukleme_oncesi_gun', 'enaz_adet', 'enaz_bos_alan_yuzde', 'bildirim_alicilari'];

        $ayar = YedekAyari::query()->create();

        $this->assertSame(3, $ayar->enaz_adet);
        $this->assertSame([], $ayar->bildirim_alicilari);
        $this->assertSame($ayar->fresh()?->only($alanlar), $ayar->only($alanlar));
    }

    /** Silme 409 + KAYIT_KULLANILIYOR ile reddedilmeli ve kayıt yerinde kalmalı. */
    private function silinemez(?Model $kayit): void
    {
        $this->assertNotNull($kayit);

        try {
            KayitSilme::sil($kayit);
            $this->fail('Kullanılan kayıt silindi.');
        } catch (HttpResponseException $hata) {
            TestResponse::fromBaseResponse($hata->getResponse())
                ->assertConflict()
                ->assertJsonPath('kod', 'KAYIT_KULLANILIYOR');
        }

        $this->assertModelExists($kayit);
    }
}
