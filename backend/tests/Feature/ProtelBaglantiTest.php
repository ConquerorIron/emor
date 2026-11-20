<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Models\User;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\ProtelIstemcisi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ayarlar → Entegratör Bağlantıları → Protel (PRT-03, PRT-05).
 */
final class ProtelBaglantiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const GIRIS_URL = 'https://authrestapitest.protel.com.tr/v1/Auth/AccessToken';

    private const LISTE_URL = 'https://einvoicerestapitest.protel.com.tr/v1/Document/GetInvoiceList';

    private function yonetici(): User
    {
        $kullanici = User::factory()->yonetici()->create();
        $this->actingAs($kullanici);

        return $kullanici;
    }

    /** Adresler ve senkron aralığı İzibiz'deki gibi ekrandan girilir (kullanıcı kararı 2026-11-20). */
    public function test_tanim_adresleri_ve_senkron_araligi_kaydedilir_izibiz_alanlari_saklanmaz_sifre_donmez(): void
    {
        $this->yonetici();
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'auth_url' => 'https://Giris2.Protel.com.tr/',
            'api_url' => 'https://fatura2.protel.com.tr',
            'kullanici_adi' => 'ws@ornek.test',
            'sifre' => 'gizli-sifre',
            'vkn' => '1234567890',
            'senkron_araligi_dakika' => 30,
            // Protel formunda yok; gönderilse de saklanmaz
            'posta_kutusu' => 'urn:mail:pk@ornek.test',
        ])
            ->assertOk()
            ->assertJsonPath('data.saglayici', 'protel')
            ->assertJsonPath('data.auth_url', 'https://giris2.protel.com.tr')
            ->assertJsonPath('data.auth_url_ozel', true)
            ->assertJsonPath('data.api_url', 'https://fatura2.protel.com.tr')
            ->assertJsonPath('data.api_url_ozel', true)
            ->assertJsonPath('data.senkron_araligi_dakika', 30)
            ->assertJsonPath('data.sifre_dolu', true)
            ->assertJsonMissingPath('data.sifre');

        $tanim = EntegratorBaglanti::query()->where('saglayici', 'protel')->sole();
        $this->assertNull($tanim->posta_kutusu);
        $this->assertSame('gizli-sifre', $tanim->sifre);
        $this->assertTrue($izibiz->refresh()->aktif);

        // Boş adres = ortamın varsayılanı; varsayılanlar formun ipucu olarak döner
        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'auth_url' => '', 'api_url' => null, 'kullanici_adi' => 'ws@ornek.test', 'sifre' => 'gizli-sifre', 'vkn' => '1234567890',
        ])
            ->assertOk()
            ->assertJsonPath('data.auth_url', 'https://authrestapitest.protel.com.tr')
            ->assertJsonPath('data.auth_url_ozel', false)
            ->assertJsonPath('data.api_url_ozel', false);
        $this->getJson('/api/v1/ayarlar/entegrator-baglantilari')
            ->assertJsonPath('data.protel.varsayilan_auth_url.canli', 'https://authrestapi.protel.com.tr')
            ->assertJsonPath('data.protel.varsayilan_api_url.canli', 'https://einvoicerestapi.protel.com.tr')
            ->assertJsonMissingPath('data.canli.auth_url');
    }

    public function test_izinli_alan_adi_disindaki_ya_da_https_olmayan_adres_reddedilir(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'auth_url' => 'https://protel.com.tr.kotu.example',
            'api_url' => 'http://einvoicerestapi.protel.com.tr',
            'kullanici_adi' => 'ws@ornek.test', 'sifre' => 's', 'vkn' => '1234567890',
        ])->assertUnprocessable()->assertJsonValidationErrors(['auth_url', 'api_url'], 'hatalar');

        $this->assertSame(0, EntegratorBaglanti::query()->count());
    }

    /** Kayıtlı şifre henüz doğrulanmamış yeni bir adrese gönderilmez (İzibiz kuralı). */
    public function test_adres_degisince_sifre_yeniden_istenir_sinamada_kayitli_sifre_gonderilmez(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $this->yonetici();
        $tanim = EntegratorBaglanti::factory()->protel()->create();
        $mesaj = 'API adresi değiştiğinde entegratör şifresi yeniden girilmelidir.';

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'auth_url' => 'https://giris2.protel.com.tr', 'kullanici_adi' => $tanim->kullanici_adi, 'vkn' => $tanim->vkn,
        ])->assertUnprocessable()->assertJsonPath('hatalar.sifre.0', $mesaj);
        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'api_url' => 'https://fatura2.protel.com.tr', 'kullanici_adi' => $tanim->kullanici_adi, 'vkn' => $tanim->vkn,
        ])->assertUnprocessable()->assertJsonPath('hatalar.sifre.0', $mesaj);
        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test/sina', ['auth_url' => 'https://giris2.protel.com.tr'])
            ->assertUnprocessable()->assertJsonPath('hatalar.sifre.0', $mesaj);

        Http::assertNothingSent();
        $this->assertNull($tanim->refresh()->auth_url);
        $this->assertNull($tanim->api_url);
    }

    public function test_kayitli_adresler_giris_sinama_ve_listede_kullanilir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([
            'https://giris2.protel.com.tr/v1/Auth/AccessToken' => ProtelIstemcisiTest::girisYaniti(),
            'https://fatura2.protel.com.tr/v1/Document/GetInvoiceList' => Http::response(['invoiceList' => []]),
        ]);
        $this->yonetici();
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create([
            'auth_url' => 'https://giris2.protel.com.tr',
            'api_url' => 'https://fatura2.protel.com.tr',
        ]);

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test/sina', [])->assertOk();
        app(ProtelIstemcisi::class)->post($tanim, ProtelIstemcisi::YOL_FATURA_LISTESI, [
            'startDate' => '2026-11-01T00:00:00', 'endDate' => '2026-11-19T23:59:59', 'filterDateType' => 'DocumentDate',
            'documentDirection' => 'Incoming', 'erpRecievedStatus' => 'All', 'erpStatus' => 'All',
        ]);

        Http::assertSentCount(3);
    }

    public function test_kullanici_adi_e_posta_olmali(): void
    {
        $this->yonetici();

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'kullanici_adi' => 'ws', 'sifre' => 's', 'vkn' => '1234567890',
        ])->assertUnprocessable()->assertJsonValidationErrorFor('kullanici_adi', 'hatalar');
    }

    public function test_standart_kullanici_protel_tanimini_degistiremez(): void
    {
        $this->actingAs(User::factory()->create());

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'kullanici_adi' => 'ws@ornek.test', 'sifre' => 's', 'vkn' => '1234567890',
        ])->assertForbidden();

        $this->assertSame(0, EntegratorBaglanti::query()->count());
    }

    public function test_aktif_yapma_izibizin_aktif_ortamina_dokunmaz(): void
    {
        $this->yonetici();
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        $protel = EntegratorBaglanti::factory()->protel()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/protel/aktif', ['ortam' => 'test'])
            ->assertOk()
            ->assertJsonPath('data.saglayici', 'protel');

        $this->assertTrue($protel->refresh()->aktif);
        $this->assertTrue($izibiz->refresh()->aktif);
        $this->getJson('/api/v1/ayarlar/entegrator-baglantilari')
            ->assertJsonPath('data.aktif_ortam', 'test')
            ->assertJsonPath('data.protel.aktif_ortam', 'test');
    }

    public function test_sinama_modul_kapaliyken_protele_istek_gondermez(): void
    {
        config(['entegrator.protel.aktif' => false]);
        Http::preventStrayRequests();
        Http::fake();
        $this->yonetici();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test/sina', [
            'kullanici_adi' => 'ws@ornek.test', 'sifre' => 's', 'vkn' => '1234567890',
        ])->assertStatus(409)->assertJsonPath('kod', 'PROTEL_KAPALI');

        Http::assertNothingSent();
    }

    public function test_sinama_bos_sifrede_kayitli_sifreyi_kullanir_ve_firmayi_doner(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([self::GIRIS_URL => ProtelIstemcisiTest::girisYaniti()]);
        $this->yonetici();
        EntegratorBaglanti::factory()->protel()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test/sina', [])
            ->assertOk()
            ->assertJsonPath('data.firma_unvani', 'Deneme Otel A.Ş.');

        Http::assertSent(fn (Request $istek): bool => $istek['password'] === 'deneme-sifre'
            && $istek['eMail'] === 'deneme@ornek.test');
    }

    public function test_sinama_kullanici_degisince_kayitli_sifreyi_gondermez(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $this->yonetici();
        EntegratorBaglanti::factory()->protel()->create();

        $this->postJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test/sina', ['kullanici_adi' => 'baska@ornek.test'])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.sifre.0', 'Kullanıcı adı değiştiğinde entegratör şifresi yeniden girilmelidir.');

        Http::assertNothingSent();
    }

    public function test_gecmisi_olan_tanimin_vknsi_degistirilemez(): void
    {
        $this->yonetici();
        $fatura = EFatura::factory()->protel()->create();
        $tanim = $fatura->entegratorBaglanti;

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'kullanici_adi' => $tanim->kullanici_adi, 'vkn' => '9999999999',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.vkn.0', 'Bu Protel tanımının faturaları ve senkron geçmişi var; VKN değiştirilemez. Başka bir firmanın kutusu için ayrı tanım gerekir.');

        $this->assertSame('1234567890', $tanim->refresh()->vkn);
    }

    /**
     * Önbellekteki token eski firmanındır: VKN değişince kullanılmaz, yeni
     * girişte firma VKN'si yeniden doğrulanır (PRT-R1).
     */
    public function test_vkn_degisince_eski_token_kullanilmaz_yeni_giriste_firma_dogrulanir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => Http::sequence()
                ->push(ProtelIstemcisiTest::girisYaniti('1234567890')->wait()->getBody()->getContents())
                ->push(ProtelIstemcisiTest::girisYaniti('1234567890')->wait()->getBody()->getContents()),
            self::LISTE_URL => Http::response(['invoiceList' => []]),
        ]);
        $this->yonetici();
        $tanim = EntegratorBaglanti::factory()->protel()->aktif()->create();
        $istemci = app(ProtelIstemcisi::class);
        $govde = ['startDate' => '2026-11-01T00:00:00', 'endDate' => '2026-11-19T23:59:59', 'filterDateType' => 'DocumentDate', 'documentDirection' => 'Incoming', 'erpRecievedStatus' => 'All', 'erpStatus' => 'All'];
        $istemci->post($tanim, ProtelIstemcisi::YOL_FATURA_LISTESI, $govde);

        $this->putJson('/api/v1/ayarlar/entegrator-baglantilari/protel/test', [
            'kullanici_adi' => $tanim->kullanici_adi, 'vkn' => '9999999999',
        ])->assertOk();

        try {
            $istemci->post($tanim->refresh(), ProtelIstemcisi::YOL_FATURA_LISTESI, $govde);
            $this->fail('Eski firmanın token\'ı yeni VKN ile kullanıldı.');
        } catch (EntegratorHatasi $hata) {
            $this->assertSame('VKN_UYUSMUYOR', $hata->saglayiciKodu);
        }

        // İkinci giriş yapıldı (önbellek kullanılmadı); liste yalnız ilk seferde istendi
        $this->assertCount(2, Http::recorded(fn (Request $istek): bool => $istek->url() === self::GIRIS_URL));
        $this->assertCount(1, Http::recorded(fn (Request $istek): bool => $istek->url() === self::LISTE_URL));
    }
}
