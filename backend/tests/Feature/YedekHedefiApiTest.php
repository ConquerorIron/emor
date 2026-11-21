<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\YedekHedefi;
use App\Models\YedekIslemKaydi;
use App\Models\YedekPlani;
use App\Services\Yedekleme\Hedefler\GoogleDriveBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-016 (+ BY-021 bağlantı akışı): Yedek Hedefleri API'si.
 */
final class YedekHedefiApiTest extends TestCase
{
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    private const K = '/api/v1/ayarlar/yedek-hedefleri';

    protected function setUp(): void
    {
        parent::setUp();
        $this->yedeklemeOrtaminiKur();
        config(['yedekleme.aktif' => true]);
        BellekHedefi::sifirla();
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
        $this->actingAs(User::factory()->yonetici()->create());
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $alanlar
     * @return array<string, mixed>
     */
    private function ftpGovdesi(array $alanlar = []): array
    {
        return ['ad' => 'Yedek FTP', 'tur' => 'ftps', 'alanlar' => [
            'sunucu' => 'ftp.ornek.test', 'port' => 21, 'kullanici_adi' => 'yedek', 'sifre' => 'Gizli-Parola-1', 'klasor' => 'emor', 'pasif' => true, ...$alanlar,
        ]];
    }

    public function test_parola_hicbir_yanitta_donmez_duzenlemede_bos_birakilirsa_degismez(): void
    {
        $olustur = $this->postJson(self::K, $this->ftpGovdesi())->assertCreated();
        $this->assertStringNotContainsString('Gizli-Parola-1', (string) $olustur->getContent());
        $olustur->assertJsonPath('data.gizli_kayitli.sifre', true)->assertJsonPath('data.baglanti.sunucu', 'ftp.ornek.test');
        $id = $olustur->json('data.id');

        $liste = $this->getJson(self::K)->assertOk();
        $this->assertStringNotContainsString('Gizli-Parola-1', (string) $liste->getContent());

        $this->putJson(self::K.'/'.$id, [...$this->ftpGovdesi(['sifre' => '', 'klasor' => 'emor2']), 'ad' => 'Yedek FTP'])->assertOk();

        $hedef = YedekHedefi::query()->findOrFail($id);
        $this->assertSame('Gizli-Parola-1', $hedef->gizli_bilgiler['sifre']);
        $this->assertSame('emor2', $hedef->baglanti['klasor']);
    }

    public function test_sunucu_degisirken_bos_parola_reddedilir_kayitli_parola_yeni_adrese_gitmez(): void
    {
        $id = $this->postJson(self::K, $this->ftpGovdesi())->json('data.id');

        $this->putJson(self::K.'/'.$id, $this->ftpGovdesi(['sunucu' => 'baska.ornek.test', 'sifre' => '']))
            ->assertStatus(422)->assertJsonValidationErrors('alanlar.sifre', 'hatalar');

        $this->assertSame('ftp.ornek.test', YedekHedefi::query()->findOrFail($id)->baglanti['sunucu']);
    }

    public function test_bilinmeyen_alan_ve_tur_degisikligi_reddedilir(): void
    {
        $this->postJson(self::K, $this->ftpGovdesi(['sunucu_anahtari' => 'ssh-ed25519 AAAA']))->assertStatus(422)->assertJsonPath('kod', 'DOGRULAMA');
        $id = $this->postJson(self::K, $this->ftpGovdesi())->json('data.id');
        $this->putJson(self::K.'/'.$id, [...$this->ftpGovdesi(), 'tur' => 'ftp'])->assertStatus(422)->assertJsonValidationErrors('tur', 'hatalar');
    }

    public function test_planin_kullandigi_hedef_silinemez_409(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Ana']);
        YedekPlani::factory()->create()->hedefler()->attach($hedef, ['sakla_son_adet' => 7]);

        $this->deleteJson(self::K.'/'.$hedef->id)
            ->assertStatus(409)->assertJsonPath('kod', 'HEDEF_KULLANILIYOR')->assertJsonPath('plan_sayisi', 1);
        $this->assertModelExists($hedef);
    }

    public function test_kullanilmayan_hedef_silinir_denetime_yazilir(): void
    {
        $hedef = YedekHedefi::factory()->create(['ad' => 'Eski']);

        $this->deleteJson(self::K.'/'.$hedef->id)->assertNoContent();

        $this->assertModelMissing($hedef);
        $this->assertSame('hedef_sil', YedekIslemKaydi::query()->sole()->islem);
    }

    public function test_sina_sonucu_adim_adim_doner_basarisiz_adimdan_sonrasi_atlanir(): void
    {
        $this->postJson(self::K.'/sina', ['tur' => 's3', 'alanlar' => ['kova' => 'k']])
            ->assertOk()->assertJsonPath('data.basarili', true)
            ->assertJsonPath('data.adimlar.*.adim', ['baglan', 'yaz', 'oku', 'dogrula', 'sil']);

        BellekHedefi::$hataAdimi = 'oku';
        $yanit = $this->postJson(self::K.'/sina', ['tur' => 's3', 'alanlar' => ['kova' => 'k']])->assertOk();

        $yanit->assertJsonPath('data.basarili', false);
        $adimlar = collect($yanit->json('data.adimlar'))->pluck('basarili', 'adim')->all();
        $this->assertSame(['baglan' => true, 'yaz' => true, 'oku' => false, 'dogrula' => null, 'sil' => true], $adimlar);
        $this->assertSame([], BellekHedefi::$dosyalar);
    }

    public function test_sina_kisa_sure_siniriyla_calisir(): void
    {
        config(['yedekleme.sure_siniri.kopya' => 900]);

        $this->postJson(self::K.'/sina', ['tur' => 's3', 'alanlar' => ['kova' => 'k']])->assertOk();

        $this->assertSame(30, config('yedekleme.sure_siniri.kopya'));
    }

    public function test_tur_listesi_bagdayicilardan_gelir(): void
    {
        $turler = collect($this->getJson(self::K.'/turler')->assertOk()->json('data'))->keyBy('tur');

        $this->assertSame(['kova'], array_column($turler['s3']['alanlar'], 'ad'));
        $this->assertSame('yedekleme.hedef.uyari.duz_ftp', $turler['ftp']['uyari']);
        $this->assertTrue($turler['google_drive']['oauth']);
    }

    public function test_google_baglanti_akisi_durum_degerini_dogrular_yenileme_anahtarini_saklar_dondurmez(): void
    {
        config(['yedekleme.google.istemci_kimligi' => 'istemci-1.apps.googleusercontent.com', 'yedekleme.google.istemci_sirri' => 'GOCSPX-sir', 'app.url' => 'https://erp.ornek.test']);
        Http::preventStrayRequests();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.erisim', 'refresh_token' => 'yenileme-GIZLI', 'expires_in' => 3599, 'scope' => GoogleDriveBaglayicisi::KAPSAM, 'token_type' => 'Bearer']),
            'https://www.googleapis.com/drive/v3/about?fields=*' => Http::response(['user' => ['emailAddress' => 'yedek@ornek.test']]),
        ]);
        $hedef = YedekHedefi::factory()->create(['ad' => 'Drive', 'tur' => HedefTuru::GoogleDrive, 'baglanti' => ['klasor_adi' => 'eMOR', 'durum' => 'yeniden_baglan']]);

        $adres = $this->postJson(self::K.'/'.$hedef->id.'/google/baglan')->assertOk()->json('data.adres');
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $sorgu);
        $this->assertSame('https://erp.ornek.test/ayarlar/yedek-hedefleri/google-donus', $sorgu['redirect_uri']);

        // Sahte durum değeri reddedilir ve oturumdaki değer tüketilir
        $this->postJson(self::K.'/google/donus', ['kod' => 'k', 'durum' => str_repeat('x', 48)])->assertStatus(422)->assertJsonPath('kod', 'GOOGLE_DURUM_GECERSIZ');
        Http::assertNothingSent();

        $adres = $this->postJson(self::K.'/'.$hedef->id.'/google/baglan')->json('data.adres');
        parse_str((string) parse_url($adres, PHP_URL_QUERY), $sorgu);
        $yanit = $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $sorgu['state']])->assertOk();

        $this->assertStringNotContainsString('yenileme-GIZLI', (string) $yanit->getContent());
        $yanit->assertJsonPath('data.baglanti.eposta', 'yedek@ornek.test')->assertJsonPath('data.gizli_kayitli', []);
        $hedef->refresh();
        $this->assertSame('yenileme-GIZLI', $hedef->gizli_bilgiler['yenileme_anahtari']);
        $this->assertArrayNotHasKey('durum', $hedef->baglanti);
        // Aynı durum değeri ikinci kez kullanılamaz
        $this->postJson(self::K.'/google/donus', ['kod' => 'yetki-kodu', 'durum' => $sorgu['state']])->assertStatus(422);
    }
}
