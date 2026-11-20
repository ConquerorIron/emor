<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EntegratorBaglanti;
use App\Services\Entegrator\EntegratorHatasi;
use App\Services\Entegrator\JsonSayisi;
use App\Services\Entegrator\ProtelIstemcisi;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PRT-04: Protel'de hiçbir şey değiştirilmez — iki katmanlı kilit (metot +
 * gövde alanı) ve modül anahtarı, isteği GÖNDERMEDEN reddetmeli.
 */
final class ProtelIstemcisiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const GIRIS_URL = 'https://authrestapitest.protel.com.tr/v1/Auth/AccessToken';

    private const LISTE_URL = 'https://einvoicerestapitest.protel.com.tr/v1/Document/GetInvoiceList';

    private const PDF_URL = 'https://einvoicerestapitest.protel.com.tr/v1/Document/GetInvoicePdf';

    private const LISTE_GOVDESI = [
        'startDate' => '2026-11-01T00:00:00',
        'endDate' => '2026-11-19T23:59:59',
        'filterDateType' => 'DocumentDate',
        'documentDirection' => 'Incoming',
        'erpRecievedStatus' => 'All',
        'erpStatus' => 'All',
    ];

    private const PDF_GOVDESI = [
        'uuidType' => 'DocumentUUID',
        'uuid' => 'c14ffe5c-f9e9-42d9-ad5e-c8c84d592e84',
        'documentDirection' => 'Incoming',
        'outputType' => 'Pdf',
    ];

    private function tanim(): EntegratorBaglanti
    {
        return EntegratorBaglanti::factory()->protel()->aktif()->create();
    }

    /** Gerçek yanıt biçimi (PRT-01); token 24 saatlik JWT */
    public static function girisYaniti(string $vkn = '1234567890', ?int $exp = null): PromiseInterface
    {
        $yuk = rtrim(strtr(base64_encode((string) json_encode(['exp' => $exp ?? now()->addDay()->getTimestamp()])), '+/', '-_'), '=');

        return Http::response([
            'isSuccessed' => true,
            'accessToken' => "baslik.{$yuk}.imza",
            'result' => 'Success',
            'resultMessageCode' => 'MSG.Auth.LoginSuccessed',
            'customers' => [['identity' => $vkn, 'title' => 'Deneme Otel A.Ş.']],
            'userInfo' => ['loggedCustomerIdentity' => $vkn, 'loggedCustomerTitle' => 'Deneme Otel A.Ş.'],
        ]);
    }

    private function hataBekle(Closure $islem): EntegratorHatasi
    {
        try {
            $islem();
        } catch (EntegratorHatasi $hata) {
            return $hata;
        }

        $this->fail('EntegratorHatasi bekleniyordu.');
    }

    private function reddiBekle(Closure $islem): void
    {
        try {
            $islem();
            $yakalandi = false;
        } catch (InvalidArgumentException) {
            $yakalandi = true;
        }

        $this->assertTrue($yakalandi, 'Kilit isteği reddetmeliydi.');
        Http::assertNothingSent();
    }

    /**
     * Swagger'daki yazan ya da durum değiştiren bütün metotlar, okuma gibi görünüp
     * `set*` alanı taşıyanlar, /v1 önekisiz kopyalar ve bilinmeyen bir uç.
     *
     * @return array<string, array{string}>
     */
    public static function yasakYollar(): array
    {
        $yollar = [
            '/v1/Document/SendInvoice', '/v1/Document/SendAppResponse', '/v1/Document/SendAppResponseWithMapping',
            '/v1/Document/SendInvoiceResponse', '/v1/Document/SetStatus', '/v1/Document/SetErpReceived',
            '/v1/Document/GetUnReceivedIncomingInvoice', '/v1/Document/GetUnReceivedInvoiceTaxRepresentative',
            '/v1/Document/GetInvoice', '/v1/Document/GetInvoiceForTaxRepresentative', '/v1/Document/GetDocumentToken',
            '/v1/Document/CancelDraftInvoice', '/v1/Document/ApproveDraftInvoice', '/v1/Document/RePostApplicationResponse',
            // Saf okuma da olsa bugün kullanılmayan uç izin listesinde değildir
            '/v1/Document/GetInvoiceListStatus',
            '/Document/GetInvoiceList', '/Document/GetInvoicePdf',
            '/v1/Document/GetInvoiceList/', '/v1/Document/GetInvoiceListX', '/v1/Document/YeniUc',
        ];

        return array_combine($yollar, array_map(fn (string $yol): array => [$yol], $yollar));
    }

    #[DataProvider('yasakYollar')]
    public function test_izin_listesinde_olmayan_yol_istek_gonderilmeden_reddedilir(string $yol): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $tanim = $this->tanim();

        $this->reddiBekle(fn () => (new ProtelIstemcisi)->post($tanim, $yol, []));
    }

    /**
     * @return array<string, array{string, array<string, mixed>}>
     */
    public static function yasakGovdeler(): array
    {
        return [
            'PDF + setErpStatus' => [ProtelIstemcisi::YOL_FATURA_PDF, [...self::PDF_GOVDESI, 'setErpStatus' => 'New']],
            'PDF + setErpRecievedStatus' => [ProtelIstemcisi::YOL_FATURA_PDF, [...self::PDF_GOVDESI, 'setErpRecievedStatus' => 'Received']],
            'PDF + setIsRead' => [ProtelIstemcisi::YOL_FATURA_PDF, [...self::PDF_GOVDESI, 'setIsRead' => false]],
            'PDF + büyük harfli Set alanı' => [ProtelIstemcisi::YOL_FATURA_PDF, [...self::PDF_GOVDESI, 'SetErpStatus' => 'New']],
            'PDF + bilinmeyen alan' => [ProtelIstemcisi::YOL_FATURA_PDF, [...self::PDF_GOVDESI, 'mappingCode' => 'x']],
            'liste + setErpStatus' => [ProtelIstemcisi::YOL_FATURA_LISTESI, [...self::LISTE_GOVDESI, 'setErpStatus' => 'New']],
            'liste + bilinmeyen alan' => [ProtelIstemcisi::YOL_FATURA_LISTESI, [...self::LISTE_GOVDESI, 'branchCode' => '0000']],
            'giden yön' => [ProtelIstemcisi::YOL_FATURA_LISTESI, [...self::LISTE_GOVDESI, 'documentDirection' => 'Outgoing']],
            'ERP durumu süzgeci All değil' => [ProtelIstemcisi::YOL_FATURA_LISTESI, [...self::LISTE_GOVDESI, 'erpStatus' => 'New']],
            'ERP aldı süzgeci All değil' => [ProtelIstemcisi::YOL_FATURA_LISTESI, [...self::LISTE_GOVDESI, 'erpRecievedStatus' => 'Unreceived']],
            'PDF çıktı türü UBL' => [ProtelIstemcisi::YOL_FATURA_PDF, [...self::PDF_GOVDESI, 'outputType' => 'Ubl']],
        ];
    }

    /**
     * @param  array<string, mixed>  $govde
     */
    #[DataProvider('yasakGovdeler')]
    public function test_izinsiz_alan_ya_da_deger_istek_gonderilmeden_reddedilir(string $yol, array $govde): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $tanim = $this->tanim();

        $this->reddiBekle(fn () => (new ProtelIstemcisi)->post($tanim, $yol, $govde));
    }

    public function test_giris_ucu_post_ile_cagrilamaz(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $tanim = $this->tanim();

        $this->reddiBekle(fn () => (new ProtelIstemcisi)->post($tanim, ProtelIstemcisi::YOL_GIRIS, []));
    }

    public function test_modul_kapaliyken_hicbir_istek_gonderilmez(): void
    {
        config(['entegrator.protel.aktif' => false]);
        Http::preventStrayRequests();
        Http::fake();
        $tanim = $this->tanim();

        $giris = $this->hataBekle(fn () => (new ProtelIstemcisi)->tokenAl($tanim));
        $liste = $this->hataBekle(fn () => (new ProtelIstemcisi)->post($tanim, ProtelIstemcisi::YOL_FATURA_LISTESI, self::LISTE_GOVDESI));

        $this->assertSame(EntegratorHatasi::PROTEL_KAPALI, $giris->kod);
        $this->assertSame(EntegratorHatasi::PROTEL_KAPALI, $liste->kod);
        Http::assertNothingSent();
    }

    public function test_izibiz_tanimiyla_protele_istek_gonderilmez(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake();
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();

        $this->reddiBekle(fn () => (new ProtelIstemcisi)->post($izibiz, ProtelIstemcisi::YOL_FATURA_LISTESI, self::LISTE_GOVDESI));
    }

    public function test_izinli_alan_adi_disindaki_adrese_istek_gonderilmez(): void
    {
        config([
            'entegrator.protel.aktif' => true,
            'entegrator.protel.ortamlar.test.auth_url' => 'https://protel.com.tr.saldirgan.test',
        ]);
        Http::preventStrayRequests();
        Http::fake();
        $tanim = $this->tanim();

        $this->reddiBekle(fn () => (new ProtelIstemcisi)->tokenAl($tanim));
    }

    public function test_izinli_istek_birebir_izinli_govdeyle_ve_bearer_tokenla_gider(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => self::girisYaniti(),
            self::PDF_URL => Http::response(['isSuccess' => false, 'resultMessage' => 'Success', 'outputDatas' => ['JVBERi0=']]),
        ]);
        $tanim = $this->tanim();

        (new ProtelIstemcisi)->post($tanim, ProtelIstemcisi::YOL_FATURA_PDF, self::PDF_GOVDESI);

        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::GIRIS_URL
            && $istek->data() === [
                'clientType' => 'WebService',
                'eMail' => 'deneme@ornek.test',
                'password' => 'deneme-sifre',
                'customerIdentity' => '1234567890',
            ]);
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::PDF_URL
            && $istek->data() === self::PDF_GOVDESI
            && $istek->hasHeader('Authorization', 'Bearer '.$this->tokenOf($istek)));
        Http::assertSentCount(2);
    }

    /**
     * .NET kodlayıcısı base64'teki `+`'yı unicode kaçışıyla (ters bölü + u002B)
     * yazar: büyük PDF yanıtında yüz binlerce kaçış olur. Ondalığı koruyan
     * çözüm (PRT-R8) bunu sınıra takılmadan ve değiştirmeden okur.
     */
    /**
     * .NET base64 PDF'teki `+`'yı unicode kaçışıyla yazar (3,2 MB, 1,6 milyon
     * kaçış). Kaçışlar `chr(92)` ile kurulur: düz yazılan kaçış dizisi araçta
     * `+`'ya dönüşüp testi kaçışsız bırakabiliyor.
     */
    private static function kacisliBase64(string $base64): string
    {
        return str_replace(['+', '/'], [chr(92).'u002B', chr(92).'/'], $base64);
    }

    /** PDF'te para alanı yok: gövde bir kez, olağan çözümle ayrıştırılır (PRT-R14). */
    public function test_pdf_yaniti_olagan_cozumle_aynen_doner_json_olmayan_yanit_gecersizdir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $base64 = str_repeat('J+V+B+E/', 400000);
        $govde = '{"isSuccess":false,"outputDatas":["'.self::kacisliBase64($base64).'"],"tutar":1786.5}';
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => self::girisYaniti(),
            self::PDF_URL => Http::sequence()
                ->push($govde, 200, ['Content-Type' => 'application/json'])
                ->push('<html>hata</html>', 200),
        ]);
        $tanim = $this->tanim();
        $istemci = new ProtelIstemcisi;

        $yanit = $istemci->post($tanim, ProtelIstemcisi::YOL_FATURA_PDF, self::PDF_GOVDESI);

        $this->assertSame($base64, $yanit['outputDatas'][0]);
        $this->assertSame(1786.5, $yanit['tutar']);
        $hata = $this->hataBekle(fn () => $istemci->post($tanim, ProtelIstemcisi::YOL_FATURA_PDF, self::PDF_GOVDESI));
        $this->assertSame(EntegratorHatasi::YANIT_GECERSIZ, $hata->kod);
    }

    /**
     * Liste yolunda yalnız JSON SAYISI olan float `JsonSayisi`'ye döner
     * (PRT-R8); tırnaklı metin metin, tam sayı tam sayı kalır (PRT-R12).
     * Doğrusal tarama kaçışlı büyük dizede de aynı sonucu verir.
     */
    public function test_sayilari_koruyan_cozum_yalniz_json_sayilarini_metinle_tasir_turleri_korur(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $base64 = str_repeat('J+V+B+E/', 400000);
        $govde = '{"invoiceList":[{"id":7,"payableAmount":1234567890123456.23,"taxTotal":"0",'
            .'"documentId":123.45,"lineCount":-0,"buyuk":12345678901234567890,"uslu":-1.5E+3,'
            .'"isRead":true,"despatchId":null,"unvan":"Kat \"12.5\" '.chr(92).chr(92).' 3e2",'
            .'"ek":"'.self::kacisliBase64($base64).'","ic":[0.1,[2]]}]}';
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => self::girisYaniti(),
            self::LISTE_URL => Http::response($govde, 200, ['Content-Type' => 'application/json']),
        ]);

        $kayit = (new ProtelIstemcisi)->postSayilariKoruyarak($this->tanim(), ProtelIstemcisi::YOL_FATURA_LISTESI, self::LISTE_GOVDESI)['invoiceList'][0];

        $this->assertSame(7, $kayit['id']);
        $this->assertEquals(new JsonSayisi('1234567890123456.23'), $kayit['payableAmount']);
        $this->assertSame('0', $kayit['taxTotal']);
        $this->assertEquals(new JsonSayisi('123.45'), $kayit['documentId']);
        $this->assertSame(0, $kayit['lineCount']);
        $this->assertEquals(new JsonSayisi('12345678901234567890'), $kayit['buyuk']);
        $this->assertEquals(new JsonSayisi('-1.5E+3'), $kayit['uslu']);
        $this->assertTrue($kayit['isRead']);
        $this->assertNull($kayit['despatchId']);
        $this->assertSame('Kat "12.5" '.chr(92).' 3e2', $kayit['unvan']);
        $this->assertSame($base64, $kayit['ek']);
        $this->assertEquals([new JsonSayisi('0.1'), [2]], $kayit['ic']);
    }

    private function tokenOf(Request $istek): string
    {
        return substr($istek->header('Authorization')[0] ?? '', 7);
    }

    public function test_token_onbellekten_kullanilir_401de_bir_kez_yenilenir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => self::girisYaniti(),
            self::LISTE_URL => Http::sequence()
                ->push(['invoiceList' => []])
                ->push(null, 401)
                ->push(['invoiceList' => []]),
        ]);
        $tanim = $this->tanim();
        $istemci = new ProtelIstemcisi;

        $istemci->post($tanim, ProtelIstemcisi::YOL_FATURA_LISTESI, self::LISTE_GOVDESI);
        $istemci->post($tanim, ProtelIstemcisi::YOL_FATURA_LISTESI, self::LISTE_GOVDESI);

        // 1. giriş + liste; 2. liste (önbellekten) → 401 → yeni giriş + liste
        $girisler = Http::recorded(fn (Request $istek): bool => $istek->url() === self::GIRIS_URL);
        $this->assertCount(2, $girisler);
        Http::assertSentCount(5);
    }

    public function test_ikinci_401_kimlik_hatasidir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => self::girisYaniti(),
            self::LISTE_URL => Http::response(null, 401),
        ]);
        $tanim = $this->tanim();

        $hata = $this->hataBekle(fn () => (new ProtelIstemcisi)->post($tanim, ProtelIstemcisi::YOL_FATURA_LISTESI, self::LISTE_GOVDESI));

        $this->assertSame(EntegratorHatasi::KIMLIK_HATALI, $hata->kod);
    }

    public function test_hatali_sifre_kimlik_hatasi_doner(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([self::GIRIS_URL => Http::response([
            'isSuccessed' => false, 'result' => 'LoginFailed', 'resultMessageCode' => 'MSG.Auth.LoginFailed',
        ], 401)]);

        $hata = $this->hataBekle(fn () => (new ProtelIstemcisi)->tokenAl($this->tanim()));

        $this->assertSame(EntegratorHatasi::KIMLIK_HATALI, $hata->kod);
        $this->assertSame('LoginFailed', $hata->saglayiciKodu);
    }

    public function test_giristeki_firma_vkn_tanimla_uyusmazsa_token_kullanilmaz(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([self::GIRIS_URL => self::girisYaniti('9999999999')]);

        $hata = $this->hataBekle(fn () => (new ProtelIstemcisi)->tokenAl($this->tanim()));

        $this->assertSame('VKN_UYUSMUYOR', $hata->saglayiciKodu);
    }

    /**
     * Protel'in hata gövdesi isteğin başlıklarını (token dahil) yansıtıyor
     * (PRT-02'de görüldü): gövde hataya, mesaja ya da bağlama taşınmamalı.
     */
    public function test_hata_govdesindeki_yansitilan_token_hataya_tasinmaz(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Sleep::fake();
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => self::girisYaniti(),
            self::LISTE_URL => function (Request $istek): PromiseInterface {
                return Http::response([
                    'StatusCode' => 400,
                    'Message' => 'MSG.Core.Hata',
                    'Headers' => 'Authorization-'.$istek->header('Authorization')[0],
                    'status' => 'InputValidationFailed',
                ], 400);
            },
        ]);
        $tanim = $this->tanim();

        $hata = $this->hataBekle(fn () => (new ProtelIstemcisi)->post($tanim, ProtelIstemcisi::YOL_FATURA_LISTESI, self::LISTE_GOVDESI));

        $metin = $hata->getMessage().json_encode($hata->context());
        $this->assertSame('InputValidationFailed', $hata->saglayiciKodu);
        $this->assertStringNotContainsString('Bearer', $metin);
        $this->assertStringNotContainsString('baslik.', $metin);
    }

    public function test_yonlendirme_izlenmez(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => Http::response(null, 307, ['Location' => 'https://saldirgan.test/topla']),
        ]);

        $hata = $this->hataBekle(fn () => (new ProtelIstemcisi)->tokenAl($this->tanim()));

        $this->assertSame(EntegratorHatasi::HATA, $hata->kod);
        Http::assertSentCount(1);
    }
}
