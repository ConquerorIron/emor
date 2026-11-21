<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailAyari;
use App\Models\User;
use App\Models\YedekAyari;
use App\Models\YedekIslemKaydi;
use App\Services\Yedekleme\AyarKopyasi;
use App\Services\Yedekleme\IslemKaydi;
use App\Services\Yedekleme\IslemSonucu;
use App\Services\Yedekleme\IslemTuru;
use App\Services\Yedekleme\YedekDenetimi;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-018: Yedekleme Ayarları ve İşlem Günlüğü API'si (README §4.9, §4.3, §4.8).
 */
final class YedekAyariApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const K = '/api/v1/ayarlar';

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true, 'yedekleme.calistirici' => 'dogrudan']);
        $this->yonetici = User::factory()->yonetici()->create(['ad' => 'Ayşe Yönetici']);
        $this->actingAs($this->yonetici);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $degisen
     * @return array<string, mixed>
     */
    private function govde(array $degisen = []): array
    {
        return [
            'yayin_oncesi_son_adet' => 20, 'yayin_oncesi_gun' => 30, 'elle_gun' => 30, 'geri_yukleme_oncesi_gun' => 30,
            'enaz_adet' => 3, 'enaz_bos_alan_yuzde' => 15, 'bildirim_alicilari' => ['bt@ornek.test'],
            'sinyal_yerel_adresi' => 'https://izleme.ornek.test/ping/yerel', 'sinyal_uzak_adresi' => null, 'sinyal_sinama_adresi' => null,
            ...$degisen,
        ];
    }

    public function test_ayar_degisikligi_denetim_kaydina_farkla_ve_ayar_dosya_kopyasina_yazilir(): void
    {
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))->assertOk()->assertJsonPath('data.elle_gun', 45);

        $kayit = YedekIslemKaydi::query()->sole();
        $this->assertSame('ayar_guncelle', $kayit->islem);
        $this->assertSame($this->yonetici->id, $kayit->kullanici_id);
        $this->assertSame(['once' => 30, 'sonra' => 45], $kayit->ayrinti['elle_gun']);
        $this->assertArrayNotHasKey('yayin_oncesi_gun', $kayit->ayrinti);

        $kopya = app(AyarKopyasi::class)->oku();
        $this->assertNotNull($kopya);
        $this->assertSame(45, YedekAyari::guncel()->elle_gun);
        $this->assertStringContainsString('"elle_gun":45', (string) json_encode($kopya));
    }

    public function test_servis_kipinde_web_kopyayi_yazmaz_calistirici_turu_degisince_yazar(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        $kopya = app(AyarKopyasi::class);

        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['elle_gun' => 45]))->assertOk();
        $this->assertNull($kopya->oku());

        $this->artisan('yedek:calistir', ['--tek-tur' => true])->assertSuccessful();
        $this->assertSame(45, $kopya->oku()['yedek_ayarlari']['elle_gun']);

        // Değişiklik yoksa yeniden yazılmaz; mail parolası değişince yazılır
        $this->assertFalse($kopya->guncelDegilseYaz());
        MailAyari::query()->create(['anahtar' => MailAyari::ANAHTAR, 'sunucu' => 'smtp.ornek.test', 'port' => 587, 'sifreleme' => 'tls', 'kullanici_adi' => 'u', 'sifre' => 'eski', 'gonderen_adres' => 'a@ornek.test', 'gonderen_ad' => 'eMOR']);
        $this->assertTrue($kopya->guncelDegilseYaz());
        MailAyari::query()->firstOrFail()->update(['sifre' => 'yeni']);
        $this->assertTrue($kopya->guncelDegilseYaz());
        $this->assertSame('yeni', $kopya->mailAyari()?->sifre);
        $this->assertFalse($kopya->guncelDegilseYaz());
    }

    public function test_acik_ustune_geri_yuklemede_calistirici_ayar_kopyasini_ezmez(): void
    {
        config(['yedekleme.calistirici' => 'servis']);
        $kilit = $this->kilitAl();
        app(YedeklemeYapilandirmasi::class)->islemDurumu()->yaz(new IslemKaydi(
            kimlik: (string) Str::uuid(), tur: IslemTuru::GeriYuklemeUstune, adim: 'onay_bekliyor', acik: true,
            baslangic: CarbonImmutable::now('UTC'), sonGuncelleme: CarbonImmutable::now('UTC'), hata: null, erisimAnahtariOzeti: null,
        ), $kilit);
        $kilit->birak();
        $this->tutulanKilit = null;
        YedekAyari::query()->create(['elle_gun' => 99]);

        $this->artisan('yedek:calistir', ['--tek-tur' => true]);

        $this->assertNull(app(AyarKopyasi::class)->oku());
    }

    public function test_en_az_sayi_1in_altina_indirilemez_https_olmayan_sinyal_reddedilir(): void
    {
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['enaz_adet' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('enaz_adet', 'hatalar');
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['sinyal_uzak_adresi' => 'http://izleme.ornek.test/x']))
            ->assertStatus(422)->assertJsonValidationErrors('sinyal_uzak_adresi', 'hatalar');
        $this->putJson(self::K.'/yedek-ayarlari', $this->govde(['bildirim_alicilari' => ['gecersiz']]))
            ->assertStatus(422)->assertJsonValidationErrors('bildirim_alicilari.0', 'hatalar');

        $this->assertSame(0, YedekIslemKaydi::query()->count());
    }

    public function test_ozel_anahtar_iceren_metin_reddedilir_kaydedilmez(): void
    {
        $this->putJson(self::K.'/yedek-ayarlari/sifreleme-anahtari', ['acik_anahtar' => "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nlQOYBF...\n-----END PGP PRIVATE KEY BLOCK-----"])
            ->assertStatus(422)->assertJsonPath('kod', 'YEDEK_ANAHTAR_OZEL');

        $this->assertNull(YedekAyari::guncel()->sifreleme_acik_anahtari);
    }

    public function test_islem_gunlugu_salt_okunur_suzgecle_listelenir(): void
    {
        $denetim = app(YedekDenetimi::class);
        $denetim->kaydet('yedek', IslemSonucu::Basarili, null, $this->yonetici->id, 1200);
        $denetim->kaydet('kopya', IslemSonucu::Basarisiz, null, null, 50, ['hedef' => 'FTP'], 'ERISILEMEDI');

        $this->getJson(self::K.'/yedek-islem-kayitlari?sonuc=basarisiz')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.islem', 'kopya')->assertJsonPath('data.0.ayrinti.hedef', 'FTP');
        $this->getJson(self::K.'/yedek-islem-kayitlari?kullanici_id='.$this->yonetici->id)->assertOk()
            ->assertJsonPath('data.0.kullanici', 'Ayşe Yönetici')->assertJsonPath('meta.total', 1);

        // Değiştirme / silme ucu yok
        foreach (['postJson', 'putJson', 'deleteJson'] as $yontem) {
            $this->{$yontem}(self::K.'/yedek-islem-kayitlari')->assertStatus(405);
        }
        $this->assertSame(2, YedekIslemKaydi::query()->count());
    }
}
