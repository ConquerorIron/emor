<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EntegratorBaglanti;
use App\Models\SqlBaglanti;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class EntegratorBaglantiTest extends TestCase
{
    use RefreshDatabase;

    private const HATA_KULLANICI_DEGISTI = 'Kullanıcı adı değiştiğinde entegratör şifresi yeniden girilmelidir.';

    private const HATA_ADRES_DEGISTI = 'API adresi değiştiğinde entegratör şifresi yeniden girilmelidir.';

    private const HATA_ADRES_BICIMI = 'API adresi "https://alan-adı" biçiminde olmalı (yol, sorgu veya kullanıcı bilgisi içeremez).';

    private const HATA_ADRES_IZINSIZ = 'API adresinin alan adı izinli değil. İzinli alan adları: izibiz.com.tr';

    private const TOKEN_URL = 'https://apitest.izibiz.com.tr/v1/auth/token';

    private function yonetici(): User
    {
        $user = User::factory()->yonetici()->create();
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function govde(array $degisen = []): array
    {
        return [
            'kullanici_adi' => 'deneme-kullanici',
            'sifre' => 'yeni-sifre',
            'vkn' => '1234567890',
            'posta_kutusu' => 'urn:mail:deneme-pk@ornek.test',
            'gonderici_birim' => 'urn:mail:deneme-gb@ornek.test',
            ...$degisen,
        ];
    }

    public function test_oturumsuz_istek_401_doner(): void
    {
        $this->getJson('/api/v1/ayarlar/entegrator-baglantilari')->assertUnauthorized();
    }

    /**
     * @return array<string, array{string, string, array<string, mixed>}>
     */
    public static function yoneticiUclari(): array
    {
        return [
            'listeleme' => ['GET', '/api/v1/ayarlar/entegrator-baglantilari', []],
            'güncelleme' => ['PUT', '/api/v1/ayarlar/entegrator-baglantilari/test', [
                'kullanici_adi' => 'baska', 'sifre' => 's', 'vkn' => '1111111111',
            ]],
            'aktif yapma' => ['POST', '/api/v1/ayarlar/entegrator-baglantilari/aktif', ['ortam' => 'test']],
            'sınama' => ['POST', '/api/v1/ayarlar/entegrator-baglantilari/test/sina', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('yoneticiUclari')]
    public function test_standart_kullanici_403_alir_ve_tanim_degismez(string $yontem, string $url, array $govde): void
    {
        Http::preventStrayRequests();
        $tanim = EntegratorBaglanti::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->json($yontem, $url, $govde)
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');

        $tanim->refresh();
        $this->assertSame('deneme-kullanici', $tanim->kullanici_adi);
        $this->assertSame('deneme-sifre', $tanim->sifre);
        $this->assertFalse($tanim->aktif);
    }

    public function test_bos_durumda_tanimsiz_ortamlar_ve_uyumsuzluk_yok_doner(): void
    {
        $this->yonetici();

        $this->getJson('/api/v1/ayarlar/entegrator-baglantilari')
            ->assertOk()
            ->assertExactJson(['data' => [
                'test' => null,
                'canli' => null,
                'aktif_ortam' => null,
                'sql_aktif_ortam' => null,
                'ortam_uyumsuz' => false,
                'sinirlar' => [
                    'senkron_araligi_dakika' => ['en_az' => 15, 'en_cok' => 1440],
                    'sayfa_boyutu' => ['en_az' => 10, 'en_cok' => 100],
                ],
                'varsayilan_api_url' => [
                    'test' => 'https://apitest.izibiz.com.tr',
                    'canli' => 'https://api.izibiz.com.tr',
                ],
            ]]);
    }

    /**
     * @return array<string, array{0: array<string, int>, 1: string}>
     */
    public static function izibizSinirlari(): array
    {
        return [
            '15 dakikadan sık senkron' => [['senkron_araligi_dakika' => 14], 'senkron_araligi_dakika'],
            'tek istekte 100 faturadan fazla' => [['sayfa_boyutu' => 101], 'sayfa_boyutu'],
            'çok küçük sayfa' => [['sayfa_boyutu' => 5], 'sayfa_boyutu'],
        ];
    }

    /**
     * @param  array<string, int>  $ayar
     */
    #[DataProvider('izibizSinirlari')]
    public function test_senkron_ayarlari_izibiz_sinirlarinin_disina_cikamaz(array $ayar, string $alan): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde($ayar))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor($alan, 'hatalar');
    }

    public function test_senkron_ayarlari_kaydedilir_gonderilmezse_degismez(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde(['senkron_araligi_dakika' => 30, 'sayfa_boyutu' => 50]))
            ->assertOk()
            ->assertJsonPath('data.senkron_araligi_dakika', 30)
            ->assertJsonPath('data.sayfa_boyutu', 50);

        // Tek seferlik içe aktarma gibi ayarsız güncelleme mevcut ayarı korur
        $govde = $this->govde();
        unset($govde['sifre']);
        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $govde)
            ->assertOk()
            ->assertJsonPath('data.senkron_araligi_dakika', 30)
            ->assertJsonPath('data.sayfa_boyutu', 50);
    }

    public function test_ilk_kayitta_sifre_yoksa_422_doner_ve_kayit_olusmaz(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde(['sifre' => '']))
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', 'İlk kayıtta entegratör şifresi zorunludur.');

        $this->assertDatabaseCount('entegrator_baglantilari', 0);
    }

    public function test_tanim_olusturulur_adres_verilmezse_ortamin_varsayilani_kullanilir_ve_sifre_gizli_kalir(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde())
            ->assertOk()
            ->assertJsonPath('data.ortam', 'test')
            ->assertJsonPath('data.saglayici', 'izibiz')
            ->assertJsonPath('data.api_url', 'https://apitest.izibiz.com.tr')
            ->assertJsonPath('data.api_url_ozel', false)
            ->assertJsonPath('data.portal_url', 'https://portaltest.izibiz.com.tr')
            ->assertJsonPath('data.vkn', '1234567890')
            ->assertJsonPath('data.sifre_dolu', true)
            ->assertJsonMissingPath('data.sifre');

        $tanim = EntegratorBaglanti::query()->where('ortam', 'test')->firstOrFail();
        $this->assertSame('yeni-sifre', $tanim->sifre);
        $this->assertNotSame('yeni-sifre', $tanim->getRawOriginal('sifre'));
        $this->assertSame(1, $tanim->kimlik_surumu);
    }

    public function test_canli_tanim_canli_adresi_kullanir(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/canli', $this->govde())
            ->assertOk()
            ->assertJsonPath('data.api_url', 'https://api.izibiz.com.tr');
    }

    public function test_ayni_kullaniciyla_bos_sifre_kayitli_sifreyi_ve_kimlik_surumunu_korur(): void
    {
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde([
            'sifre' => '',
            'vkn' => '98765432101',
        ]))->assertOk()->assertJsonPath('data.vkn', '98765432101');

        $tanim = EntegratorBaglanti::query()->where('ortam', 'test')->firstOrFail();
        $this->assertSame('deneme-sifre', $tanim->sifre);
        $this->assertSame(1, $tanim->kimlik_surumu);
    }

    public function test_kullanici_degisince_bos_sifreyle_guncelleme_422_ile_reddedilir(): void
    {
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde([
            'kullanici_adi' => 'baska-kullanici',
            'sifre' => '',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', self::HATA_KULLANICI_DEGISTI);

        $this->assertSame(
            'deneme-kullanici',
            EntegratorBaglanti::query()->where('ortam', 'test')->value('kullanici_adi'),
        );
    }

    public function test_sifre_degisince_kimlik_surumu_artar_ve_eski_token_onbellekten_silinir(): void
    {
        $this->yonetici();
        $tanim = EntegratorBaglanti::factory()->create();
        $eskiAnahtar = $tanim->tokenOnbellekAnahtari();
        Cache::put($eskiAnahtar, 'eski-token', 600);

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde(['sifre' => 'degisen-sifre']))
            ->assertOk();

        $tanim->refresh();
        $this->assertSame(2, $tanim->kimlik_surumu);
        $this->assertSame('degisen-sifre', $tanim->sifre);
        $this->assertNotSame($eskiAnahtar, $tanim->tokenOnbellekAnahtari());
        $this->assertFalse(Cache::has($eskiAnahtar));
    }

    /**
     * @return array<string, array{array<string, mixed>, string, string}>
     */
    public static function gecersizGovdeler(): array
    {
        return [
            'VKN harf içeriyor' => [['vkn' => '12345ABCDE'], 'vkn', 'VKN 10, TCKN 11 haneli olmalı ve yalnız rakam içermelidir.'],
            'VKN kısa' => [['vkn' => '123456789'], 'vkn', 'VKN 10, TCKN 11 haneli olmalı ve yalnız rakam içermelidir.'],
            'posta kutusu urn değil' => [['posta_kutusu' => 'pk@ornek.test'], 'posta_kutusu', 'Etiket "urn:mail:" ile başlamalıdır.'],
            'gönderici birim urn değil' => [['gonderici_birim' => 'gb@ornek.test'], 'gonderici_birim', 'Etiket "urn:mail:" ile başlamalıdır.'],
            'adres https değil' => [['api_url' => 'http://apitest.izibiz.com.tr'], 'api_url', self::HATA_ADRES_BICIMI],
            'adreste yol var' => [['api_url' => 'https://apitest.izibiz.com.tr/v1/auth'], 'api_url', self::HATA_ADRES_BICIMI],
            'adreste kullanıcı bilgisi var' => [['api_url' => 'https://a:b@apitest.izibiz.com.tr'], 'api_url', self::HATA_ADRES_BICIMI],
            'adres izinsiz alan adı' => [['api_url' => 'https://saldirgan.example'], 'api_url', self::HATA_ADRES_IZINSIZ],
            // Sonek hilesi: alan adı izinli adla BİTMİYOR
            'adres izinli adı içeren başka alan' => [['api_url' => 'https://izibiz.com.tr.saldirgan.example'], 'api_url', self::HATA_ADRES_IZINSIZ],
            'adres benzer ama farklı alan' => [['api_url' => 'https://kotuizibiz.com.tr'], 'api_url', self::HATA_ADRES_IZINSIZ],
        ];
    }

    public function test_api_adresi_ekrandan_tanimlanir_normallestirilir_ve_ozel_isaretlenir(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde([
            'api_url' => ' https://APITEST2.izibiz.com.tr/ ',
        ]))
            ->assertOk()
            ->assertJsonPath('data.api_url', 'https://apitest2.izibiz.com.tr')
            ->assertJsonPath('data.api_url_ozel', true);

        $this->assertSame('https://apitest2.izibiz.com.tr', EntegratorBaglanti::query()->value('api_url'));
    }

    public function test_adres_degisince_bos_sifreyle_422_doner_kayitli_sifre_yeni_adrese_baglanmaz(): void
    {
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde([
            'api_url' => 'https://apitest2.izibiz.com.tr',
            'sifre' => '',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', self::HATA_ADRES_DEGISTI);

        $this->assertNull(EntegratorBaglanti::query()->value('api_url'));
    }

    public function test_adres_sifreyle_degisince_kimlik_surumu_artar_ve_eski_token_silinir(): void
    {
        $this->yonetici();
        $tanim = EntegratorBaglanti::factory()->create();
        $eskiAnahtar = $tanim->tokenOnbellekAnahtari();
        Cache::put($eskiAnahtar, 'eski-token', 600);

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde([
            'api_url' => 'https://apitest2.izibiz.com.tr',
            'sifre' => 'yeni-adresin-sifresi',
        ]))->assertOk();

        $this->assertSame(2, $tanim->refresh()->kimlik_surumu);
        $this->assertFalse(Cache::has($eskiAnahtar));
    }

    public function test_adres_gonderilmezse_kayitli_ozel_adres_korunur_bosaltilirsa_varsayilana_doner(): void
    {
        $this->yonetici();
        EntegratorBaglanti::factory()->create(['api_url' => 'https://apitest2.izibiz.com.tr']);

        // Adres alanı olmayan eski istemci: adres ve şifre korunur
        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde(['sifre' => '']))
            ->assertOk()
            ->assertJsonPath('data.api_url', 'https://apitest2.izibiz.com.tr');

        // Boşaltmak da adres değişikliğidir: şifre ister
        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde(['api_url' => '', 'sifre' => '']))
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', self::HATA_ADRES_DEGISTI);

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde(['api_url' => '']))
            ->assertOk()
            ->assertJsonPath('data.api_url', 'https://apitest.izibiz.com.tr')
            ->assertJsonPath('data.api_url_ozel', false);
    }

    public function test_liste_bos_adres_icin_ortamlarin_varsayilan_adresini_bildirir(): void
    {
        $this->yonetici();

        $this->getJson('/api/v1/ayarlar/entegrator-baglantilari')
            ->assertOk()
            ->assertJsonPath('data.varsayilan_api_url', [
                'test' => 'https://apitest.izibiz.com.tr',
                'canli' => 'https://api.izibiz.com.tr',
            ]);
    }

    /**
     * @param  array<string, mixed>  $degisen
     */
    #[DataProvider('gecersizGovdeler')]
    public function test_gecersiz_alan_422_ile_reddedilir(array $degisen, string $alan, string $mesaj): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde($degisen))
            ->assertUnprocessable()
            ->assertJsonPath("hatalar.{$alan}.0", $mesaj);

        $this->assertDatabaseCount('entegrator_baglantilari', 0);
    }

    public function test_gecersiz_ortam_404_doner(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/staging', $this->govde())->assertNotFound();
    }

    public function test_aktif_ortam_degisir_tek_aktif_kalir_ve_sql_ortamina_dokunmaz(): void
    {
        $this->yonetici();
        EntegratorBaglanti::factory()->aktif()->create();
        EntegratorBaglanti::factory()->canli()->create();
        $sql = SqlBaglanti::query()->create([
            'ortam' => 'test', 'sunucu' => 'sql.local', 'veritabani' => 'ERP',
            'kullanici_adi' => 'sa', 'sifre' => 's', 'aktif' => true,
        ]);

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/aktif', ['ortam' => 'canli'])
            ->assertOk()
            ->assertJsonPath('data.ortam', 'canli')
            ->assertJsonPath('data.aktif', true);

        $this->assertSame(['canli'], EntegratorBaglanti::query()->where('aktif', true)->pluck('ortam')->all());
        $this->assertTrue($sql->refresh()->aktif);
    }

    public function test_sql_ve_entegrator_farkli_ortamdaysa_uyumsuzluk_bildirilir(): void
    {
        $this->yonetici();
        EntegratorBaglanti::factory()->canli()->aktif()->create();
        SqlBaglanti::query()->create([
            'ortam' => 'test', 'sunucu' => 'sql.local', 'veritabani' => 'ERP',
            'kullanici_adi' => 'sa', 'sifre' => 's', 'aktif' => true,
        ]);

        $this->getJson('/api/v1/ayarlar/entegrator-baglantilari')
            ->assertOk()
            ->assertJsonPath('data.aktif_ortam', 'canli')
            ->assertJsonPath('data.sql_aktif_ortam', 'test')
            ->assertJsonPath('data.ortam_uyumsuz', true);
    }

    public function test_tanimsiz_ortam_aktif_yapilamaz_422(): void
    {
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/aktif', ['ortam' => 'test'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.ortam.0', 'Bu ortam için entegratör bağlantısı tanımlanmamış.');
    }

    private function tokenYanitiSahte(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'data' => ['accessToken' => 'erisim-token-1', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'],
            'error' => null,
        ])]);
    }

    public function test_sinama_kayitli_kimlikle_token_alir_onbellege_ve_tanima_dokunmaz(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        $this->tokenYanitiSahte();
        $this->yonetici();
        $tanim = EntegratorBaglanti::factory()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina')
            ->assertOk()
            ->assertExactJson(['data' => [
                'musteri_tipi' => 'C',
                'gecerlilik_bitis' => '2026-09-23T23:24:41+00:00',
            ]]);

        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::TOKEN_URL
            && $istek->data() === ['username' => 'deneme-kullanici', 'password' => 'deneme-sifre']);
        $this->assertFalse(Cache::has($tanim->tokenOnbellekAnahtari()));
        $this->assertSame(1, $tanim->refresh()->kimlik_surumu);
    }

    public function test_sinama_kaydedilmemis_form_kimligiyle_yapilir_ve_kayitli_tanim_degismez(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        $this->tokenYanitiSahte();
        $this->yonetici();
        $tanim = EntegratorBaglanti::factory()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina', [
            'kullanici_adi' => 'formdaki-kullanici',
            'sifre' => 'formdaki-sifre',
        ])->assertOk();

        Http::assertSent(fn (Request $istek): bool => $istek->data() === ['username' => 'formdaki-kullanici', 'password' => 'formdaki-sifre']);
        $tanim->refresh();
        $this->assertSame('deneme-kullanici', $tanim->kullanici_adi);
        $this->assertSame('deneme-sifre', $tanim->sifre);
    }

    public function test_sinama_kullanici_degisip_sifre_bossa_istek_atmadan_422_doner(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina', ['kullanici_adi' => 'baska-kullanici'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', self::HATA_KULLANICI_DEGISTI);

        Http::assertNothingSent();
    }

    public function test_sinama_adres_degisip_sifre_bossa_kayitli_sifre_yeni_adrese_gonderilmez(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina', ['api_url' => 'https://apitest2.izibiz.com.tr'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', self::HATA_ADRES_DEGISTI);

        Http::assertNothingSent();
    }

    public function test_sinama_formdaki_adres_ve_sifreyle_o_adrese_yapilir(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake(['https://apitest2.izibiz.com.tr/v1/auth/token' => Http::response([
            'data' => ['accessToken' => 'erisim-token-2', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'],
            'error' => null,
        ])]);
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina', [
            'api_url' => 'https://apitest2.izibiz.com.tr',
            'sifre' => 'formdaki-sifre',
        ])->assertOk();

        Http::assertSent(fn (Request $istek): bool => $istek->url() === 'https://apitest2.izibiz.com.tr/v1/auth/token');
        $this->assertNull(EntegratorBaglanti::query()->value('api_url'));
    }

    public function test_sinama_izinsiz_adrese_istek_atmadan_422_doner(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina', [
            'api_url' => 'https://saldirgan.example',
            'sifre' => 'formdaki-sifre',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.api_url.0', self::HATA_ADRES_IZINSIZ);

        Http::assertNothingSent();
    }

    public function test_sinama_tanim_ve_sifre_yoksa_istek_atmadan_422_doner(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina', ['kullanici_adi' => 'yeni'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', 'İlk kayıtta entegratör şifresi zorunludur.');

        Http::assertNothingSent();
    }

    public function test_sinama_hatali_kimlikte_422_ve_entegrator_kimlik_hatali_kodu_doner(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response([
            'data' => null,
            'error' => ['code' => '10004', 'message' => 'Kullanıcı adı veya şifre hatalı', 'group' => 'AUTHENTICATION'],
        ], 401)]);
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina')
            ->assertUnprocessable()
            ->assertExactJson([
                'kod' => 'ENTEGRATOR_KIMLIK_HATALI',
                'mesaj' => 'Entegratör kullanıcı adı veya şifresi hatalı.',
            ]);
    }

    public function test_sinama_entegratore_ulasilamazsa_502_ve_entegrator_erisilemedi_kodu_doner(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([self::TOKEN_URL => Http::failedConnection()]);
        $this->yonetici();
        EntegratorBaglanti::factory()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/test/sina')
            ->assertStatus(502)
            ->assertJsonPath('kod', 'ENTEGRATOR_ERISILEMEDI');
    }

    public function test_guncelleme_kim_tarafindan_yapildigi_loglanir_sifre_loga_girmez(): void
    {
        Log::spy();
        $yonetici = $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/test', $this->govde())->assertOk();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $mesaj, array $baglam): bool => $baglam === [
                'saglayici' => 'izibiz',
                'ortam' => 'test',
                'kullanici_id' => $yonetici->id,
                'sifre_degisti' => true,
                // Adres sır değil; hangi adrese bağlanıldığı iz için yazılır
                'api_url' => 'https://apitest.izibiz.com.tr',
            ])
            ->once();
    }
}
