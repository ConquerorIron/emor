<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\IzibizIstemcisi;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class IzibizIstemcisiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://apitest.izibiz.com.tr/v1/auth/token';

    private const VERI_URL = 'https://apitest.izibiz.com.tr/v1/deneme*';

    private function istemci(): IzibizIstemcisi
    {
        return new IzibizIstemcisi;
    }

    /**
     * Test hesabında gözlenen yanıt biçimi; `validity` İstanbul saati.
     * 2026-09-23 11:24:41 UTC'de alınan token 2026-09-24 02:24:41 (TR) biter.
     */
    private function tokenYaniti(string $token = 'erisim-token-1', string $gecerlilik = '2026-09-24 02:24:41'): PromiseInterface
    {
        return Http::response([
            'data' => ['accessToken' => $token, 'validity' => $gecerlilik, 'customerType' => 'C', 'privileges' => []],
            'error' => null,
        ]);
    }

    private function kimlikHatasiYaniti(): PromiseInterface
    {
        return Http::response([
            'data' => null,
            'error' => [
                'code' => '10004',
                'message' => 'Kullanıcı adı veya şifre hatalı (hata kodu: 10004)',
                'detail' => 'Bad credentials',
                'group' => 'AUTHENTICATION',
            ],
            'warning' => null,
        ], 401);
    }

    private function hataBekle(string $kod, Closure $islem): EntegratorHatasi
    {
        try {
            $islem();
        } catch (EntegratorHatasi $hata) {
            $this->assertSame($kod, $hata->kod);

            return $hata;
        }

        $this->fail("{$kod} hatası bekleniyordu.");
    }

    public function test_token_alinir_ve_istanbul_saatindeki_validity_utcye_cevrilir(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([self::TOKEN_URL => $this->tokenYaniti()]);

        $token = $this->istemci()->tokenAl(EntegratorBaglanti::factory()->make());

        $this->assertSame('erisim-token-1', $token->erisimToken);
        $this->assertSame('2026-09-23T23:24:41+00:00', $token->bitis->toIso8601String());
        $this->assertSame('C', $token->musteriTipi);
    }

    public function test_token_istegi_ortamin_sabit_adresine_tanimin_kimligiyle_gider(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake(['https://api.izibiz.com.tr/v1/auth/token' => $this->tokenYaniti()]);

        $this->istemci()->tokenAl(EntegratorBaglanti::factory()->canli()->make());

        Http::assertSent(fn (Request $istek): bool => $istek->url() === 'https://api.izibiz.com.tr/v1/auth/token'
            && $istek->method() === 'POST'
            && $istek->data() === ['username' => 'deneme-kullanici', 'password' => 'deneme-sifre']);
    }

    public function test_hatali_kimlikte_401_yaniti_kimlik_hatasi_olur_ve_yeniden_denenmez(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([self::TOKEN_URL => $this->kimlikHatasiYaniti()]);

        $hata = $this->hataBekle(EntegratorHatasi::KIMLIK_HATALI, fn () => $this->istemci()->tokenAl(EntegratorBaglanti::factory()->make()));

        $this->assertSame('10004', $hata->saglayiciKodu);
        $this->assertSame(422, $hata->httpDurumu);
        Http::assertSentCount(1);
    }

    public function test_200_icinde_dolu_error_basari_sayilmaz(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::TOKEN_URL => Http::response(['data' => null, 'error' => ['code' => '20001', 'group' => 'GENERAL']])]);

        $hata = $this->hataBekle(EntegratorHatasi::HATA, fn () => $this->istemci()->tokenAl(EntegratorBaglanti::factory()->make()));

        $this->assertSame('20001', $hata->saglayiciKodu);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function gecersizTokenGovdeleri(): array
    {
        $veri = ['accessToken' => 'erisim-token-1', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'];

        return [
            'validity biçimi bozuk' => [['data' => [...$veri, 'validity' => '24.09.2026 02:24'], 'error' => null]],
            'validity takvim tarihi geçersiz' => [['data' => [...$veri, 'validity' => '2026-09-31 02:24:41'], 'error' => null]],
            'validity geçmişte' => [['data' => [...$veri, 'validity' => '2026-09-23 10:00:00'], 'error' => null]],
            'validity güvenlik payı içinde' => [['data' => [...$veri, 'validity' => '2026-09-23 14:29:00'], 'error' => null]],
            'token boş' => [['data' => [...$veri, 'accessToken' => ''], 'error' => null]],
            'data yok' => [['data' => null, 'error' => null]],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('gecersizTokenGovdeleri')]
    public function test_gecersiz_token_yaniti_basari_sayilmaz(array $govde): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([self::TOKEN_URL => Http::response($govde)]);

        $this->hataBekle(EntegratorHatasi::YANIT_GECERSIZ, fn () => $this->istemci()->tokenAl(EntegratorBaglanti::factory()->make()));
    }

    public function test_baglanti_kurulamazsa_iki_kez_bekleyip_erisilemedi_hatasi_olur(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        Http::fake([self::TOKEN_URL => Http::failedConnection()]);

        $this->hataBekle(EntegratorHatasi::ERISILEMEDI, fn () => $this->istemci()->tokenAl(EntegratorBaglanti::factory()->make()));

        Sleep::assertSleptTimes(2);
    }

    public function test_gecici_503_sonrasi_basarili_yanit_kullanilir(): void
    {
        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([self::TOKEN_URL => Http::sequence()->push('', 503)->pushResponse($this->tokenYaniti())]);

        $token = $this->istemci()->tokenAl(EntegratorBaglanti::factory()->make());

        $this->assertSame('erisim-token-1', $token->erisimToken);
        Http::assertSentCount(2);
    }

    public function test_token_onbellekte_sifreli_tutulur_ve_ikinci_cagri_istek_atmaz(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([self::TOKEN_URL => $this->tokenYaniti()]);
        $tanim = EntegratorBaglanti::factory()->create();

        $ilk = $this->istemci()->token($tanim);
        $ikinci = $this->istemci()->token($tanim);

        $this->assertSame('erisim-token-1', $ilk);
        $this->assertSame('erisim-token-1', $ikinci);
        Http::assertSentCount(1);
        $this->assertNotSame('erisim-token-1', Cache::get($tanim->tokenOnbellekAnahtari()));
    }

    public function test_kimlik_surumu_degisince_yeni_token_alinir(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->pushResponse($this->tokenYaniti('erisim-token-1'))
            ->pushResponse($this->tokenYaniti('erisim-token-2'))]);
        $tanim = EntegratorBaglanti::factory()->create();
        $this->istemci()->token($tanim);

        $tanim->kimlik_surumu++;
        $tanim->save();

        $this->assertSame('erisim-token-2', $this->istemci()->token($tanim));
    }

    public function test_eski_model_ornegiyle_token_istenirse_guncel_kimlik_kullanilir(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([self::TOKEN_URL => $this->tokenYaniti()]);
        $eskiTanim = EntegratorBaglanti::factory()->create();

        $guncelTanim = EntegratorBaglanti::query()->whereKey($eskiTanim->id)->firstOrFail();
        $guncelTanim->sifre = 'guncel-sifre';
        $guncelTanim->kimlik_surumu = 2;
        $guncelTanim->save();

        $this->assertSame('erisim-token-1', $this->istemci()->token($eskiTanim));
        Http::assertSent(fn (Request $istek): bool => $istek->data()['password'] === 'guncel-sifre');
        $this->assertSame(2, $eskiTanim->kimlik_surumu);
    }

    public function test_onbellek_token_bitisinden_guvenlik_payi_kadar_once_doldugunda_yeniden_alinir(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([self::TOKEN_URL => Http::sequence()
            ->pushResponse($this->tokenYaniti('erisim-token-1'))
            ->pushResponse($this->tokenYaniti('erisim-token-2', '2026-09-24 14:24:41'))]);
        $tanim = EntegratorBaglanti::factory()->create();
        $this->istemci()->token($tanim);

        // Bitiş 23:24:41 UTC; güvenlik payı 300 sn → 23:19:41'den sonra yenilenir
        $this->travelTo('2026-09-23 23:19:40');
        $payIcinde = $this->istemci()->token($tanim);
        $this->travelTo('2026-09-23 23:19:42');
        $payDisinda = $this->istemci()->token($tanim);

        $this->assertSame('erisim-token-1', $payIcinde);
        $this->assertSame('erisim-token-2', $payDisinda);
    }

    public function test_cozulemeyen_onbellek_kaydi_atilir_ve_token_yeniden_alinir(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([self::TOKEN_URL => $this->tokenYaniti()]);
        $tanim = EntegratorBaglanti::factory()->create();
        Cache::put($tanim->tokenOnbellekAnahtari(), 'baska-anahtarla-sifrelenmis', 600);

        $this->assertSame('erisim-token-1', $this->istemci()->token($tanim));
    }

    public function test_veri_istegi_403_alinca_token_bir_kez_yenilenip_tekrarlanir(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([
            self::TOKEN_URL => Http::sequence()
                ->pushResponse($this->tokenYaniti('erisim-token-1'))
                ->pushResponse($this->tokenYaniti('erisim-token-2')),
            self::VERI_URL => Http::sequence()
                ->push('', 403)
                ->push(['data' => ['sayi' => 3], 'error' => null]),
        ]);
        $tanim = EntegratorBaglanti::factory()->create();

        $sonuc = $this->istemci()->getJson($tanim, '/v1/deneme', ['sayfa' => 0]);

        $this->assertSame(['data' => ['sayi' => 3], 'error' => null], $sonuc);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $istek): bool => str_starts_with($istek->url(), 'https://apitest.izibiz.com.tr/v1/deneme')
            && $istek->hasHeader('Authorization', 'Bearer erisim-token-2'));
    }

    public function test_veri_istegi_yenilemeden_sonra_yine_401_alirsa_donguye_girmeden_kimlik_hatasi_olur(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([
            self::TOKEN_URL => $this->tokenYaniti(),
            self::VERI_URL => Http::response('', 401),
        ]);
        $tanim = EntegratorBaglanti::factory()->create();

        $this->hataBekle(EntegratorHatasi::KIMLIK_HATALI, fn () => $this->istemci()->getJson($tanim, '/v1/deneme'));

        Http::assertSentCount(4);
    }

    public function test_veri_isteginde_200_icinde_dolu_error_bos_liste_sayilmaz(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([
            self::TOKEN_URL => $this->tokenYaniti(),
            self::VERI_URL => Http::response(['data' => [], 'error' => ['code' => '30001']]),
        ]);
        $tanim = EntegratorBaglanti::factory()->create();

        $hata = $this->hataBekle(EntegratorHatasi::HATA, fn () => $this->istemci()->getJson($tanim, '/v1/deneme'));

        $this->assertSame('30001', $hata->saglayiciKodu);
    }

    public function test_veri_isteginde_data_olmayan_200_yaniti_basari_sayilmaz(): void
    {
        Http::preventStrayRequests();
        $this->travelTo('2026-09-23 11:24:41');
        Http::fake([
            self::TOKEN_URL => $this->tokenYaniti(),
            self::VERI_URL => Http::response(['error' => null]),
        ]);

        $this->hataBekle(EntegratorHatasi::YANIT_GECERSIZ, fn () => $this->istemci()->getJson(
            EntegratorBaglanti::factory()->create(), '/v1/deneme',
        ));
    }

    public function test_tokenli_veri_istegi_izibiz_disinda_bir_adrese_gonderilemez(): void
    {
        Http::preventStrayRequests();

        $this->expectException(InvalidArgumentException::class);
        $this->istemci()->getJson(EntegratorBaglanti::factory()->create(), 'https://ornek.test/v1/deneme');
    }
}
