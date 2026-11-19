<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EFaturaManuelSenkron;
use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\ErpBelgeArsivi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Protel Gelen Faturalar uçları (PRT-09, PRT-10, PRT-12): kapsam yalnız aktif
 * Protel tanımı, ayrı izinler, PDF yalnız izinli alanlarla, XML yok.
 */
final class ProtelEkranTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ARALIK = 'baslangic=2026-01-01&bitis=2026-01-31';

    private const GIRIS_URL = 'https://authrestapitest.protel.com.tr/v1/Auth/AccessToken';

    private const PDF_URL = 'https://einvoicerestapitest.protel.com.tr/v1/Document/GetInvoicePdf';

    /** Rolün izinleri TAM OLARAK bunlar (EFaturaEkranTest kalıbı) */
    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.md5(implode(',', $izinler))]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    private function protelTanimi(): EntegratorBaglanti
    {
        return EntegratorBaglanti::factory()->protel()->aktif()->create();
    }

    public function test_protel_listesi_yalniz_protel_tanimini_izibiz_listesi_yalniz_izibizi_gosterir(): void
    {
        $protel = $this->protelTanimi();
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'belge_no' => 'OTEL0000000000001']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'INSA0000000000001']);
        $kullanici = $this->izinli('protel_faturalari.goruntule', 'efatura.goruntule');

        $this->actingAs($kullanici)->getJson('/api/v1/protel/gelen/faturalar?'.self::ARALIK)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.belge_no', 'OTEL0000000000001')
            ->assertJsonPath('data.0.erp_durumu', 'New')
            ->assertJsonPath('data.0.yazdirildi', true);
        $this->actingAs($kullanici)->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.belge_no', 'INSA0000000000001');
    }

    /**
     * Her uç kendi iznini ister; e-Fatura izni Protel'i, Protel izni e-Fatura'yı açmaz.
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function izinsizUclar(): array
    {
        return [
            'e-Fatura izniyle Protel listesi' => ['GET', '/api/v1/protel/gelen/faturalar?'.self::ARALIK, ['efatura.goruntule']],
            'e-Fatura izniyle Protel durumu' => ['GET', '/api/v1/protel/durum', ['efatura.goruntule']],
            'Protel izniyle e-Fatura listesi' => ['GET', '/api/v1/efatura/gelen/faturalar?'.self::ARALIK, ['protel_faturalari.goruntule']],
            'PDF izni olmadan PDF' => ['GET', '/api/v1/protel/faturalar/1/pdf', ['protel_faturalari.goruntule', 'efatura.pdf']],
            'Excel izni olmadan Excel' => ['GET', '/api/v1/protel/gelen/faturalar/excel?'.self::ARALIK, ['protel_faturalari.goruntule', 'efatura.disari_aktar']],
            'senkron izni olmadan senkron' => ['POST', '/api/v1/protel/senkron', ['protel_faturalari.goruntule', 'efatura.senkron']],
        ];
    }

    /**
     * @param  list<string>  $izinler
     */
    #[DataProvider('izinsizUclar')]
    public function test_ucun_izni_olmayan_kullanici_403_alir(string $metot, string $adres, array $izinler): void
    {
        Http::preventStrayRequests();
        $this->protelTanimi();

        $this->actingAs($this->izinli(...$izinler))
            ->json($metot, $adres, ['baslangic' => '2026-01-01', 'bitis' => '2026-01-31'])
            ->assertForbidden();
    }

    public function test_protelde_giden_liste_ve_xml_ucu_yoktur(): void
    {
        $fatura = EFatura::factory()->protel()->create();
        $kullanici = $this->izinli('protel_faturalari.goruntule', 'protel_faturalari.pdf', 'efatura.goruntule', 'efatura.pdf');

        $this->actingAs($kullanici)->getJson('/api/v1/protel/giden/faturalar?'.self::ARALIK)->assertNotFound();
        $this->actingAs($kullanici)->getJson("/api/v1/protel/faturalar/{$fatura->id}/xml")->assertNotFound();
        // İzibiz ucu Protel faturasını göremez (aktif İzibiz tanımı yok)
        $this->actingAs($kullanici)->getJson("/api/v1/efatura/faturalar/{$fatura->id}/xml")->assertStatus(422);
    }

    public function test_pdf_yalniz_izinli_alanlarla_alinir_erp_arsivine_bakilmaz(): void
    {
        config(['entegrator.protel.aktif' => true]);
        $this->mock(ErpBelgeArsivi::class)->shouldNotReceive('pdf');
        $pdf = "%PDF-1.4\nprotel-pdf";
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => ProtelIstemcisiTest::girisYaniti(),
            self::PDF_URL => Http::response(['isSuccess' => false, 'outputDatas' => [base64_encode($pdf)]]),
        ]);
        // Portalda yazdırılmamış fatura da açılır (kullanıcı kararı 2026-11-19)
        $fatura = EFatura::factory()->protel()->create(['ettn' => 'c14ffe5c-f9e9-42d9-ad5e-c8c84d592e84', 'yazdirildi' => false]);

        $yanit = $this->actingAs($this->izinli('protel_faturalari.goruntule', 'protel_faturalari.pdf'))
            ->get("/api/v1/protel/faturalar/{$fatura->id}/pdf");

        $yanit->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Belge-Kaynagi', 'entegrator');
        $this->assertSame($pdf, $yanit->getContent());
        Http::assertSent(fn (Request $istek): bool => $istek->url() === self::PDF_URL && $istek->data() === [
            'uuidType' => 'DocumentUUID',
            'uuid' => 'c14ffe5c-f9e9-42d9-ad5e-c8c84d592e84',
            'documentDirection' => 'Incoming',
            'outputType' => 'Pdf',
        ]);
    }

    public function test_pdf_olmayan_yanit_gecersiz_sayilir(): void
    {
        config(['entegrator.protel.aktif' => true]);
        Http::preventStrayRequests();
        Http::fake([
            self::GIRIS_URL => ProtelIstemcisiTest::girisYaniti(),
            self::PDF_URL => Http::response(['outputDatas' => [base64_encode('<html>hata</html>')]]),
        ]);
        $fatura = EFatura::factory()->protel()->create();

        $this->actingAs($this->izinli('protel_faturalari.goruntule', 'protel_faturalari.pdf'))
            ->getJson("/api/v1/protel/faturalar/{$fatura->id}/pdf")
            ->assertStatus(502)
            ->assertJsonPath('kod', 'ENTEGRATOR_YANIT_GECERSIZ');
    }

    public function test_elle_senkron_modul_kapaliyken_kuyruga_girmez_acikken_protel_tanimiyla_girer(): void
    {
        Queue::fake([EFaturaManuelSenkron::class]);
        $tanim = $this->protelTanimi();
        $kullanici = $this->izinli('protel_faturalari.goruntule', 'protel_faturalari.senkron');
        $aralik = ['baslangic' => '2026-01-01', 'bitis' => '2026-01-31'];

        config(['entegrator.protel.aktif' => false]);
        $this->actingAs($kullanici)->postJson('/api/v1/protel/senkron', $aralik)
            ->assertStatus(409)
            ->assertJsonPath('kod', 'PROTEL_KAPALI');
        Queue::assertNothingPushed();

        config(['entegrator.protel.aktif' => true]);
        $this->actingAs($kullanici)->postJson('/api/v1/protel/senkron', $aralik)->assertStatus(202);
        Queue::assertPushed(EFaturaManuelSenkron::class, fn (EFaturaManuelSenkron $is): bool => $is->tanimId === $tanim->id);
    }

    public function test_durum_yalniz_gelen_yonu_ve_protel_anahtarini_doner(): void
    {
        config(['entegrator.protel.aktif' => true, 'entegrator.izibiz.senkron_aktif' => false]);
        $this->protelTanimi();

        $this->actingAs($this->izinli('protel_faturalari.goruntule'))
            ->getJson('/api/v1/protel/durum')
            ->assertOk()
            ->assertJsonPath('data.ortam', 'test')
            ->assertJsonPath('data.senkron_aktif', true)
            ->assertJsonCount(1, 'data.yonler')
            ->assertJsonStructure(['data' => ['yonler' => ['gelen']]]);
    }

    public function test_excel_protel_durum_kolonlarini_yazar(): void
    {
        $fatura = EFatura::factory()->protel()->create(['erp_durumu' => 'Booked', 'yazdirildi' => false]);

        $yanit = $this->actingAs($this->izinli('protel_faturalari.goruntule', 'protel_faturalari.disari_aktar'))
            ->get('/api/v1/protel/gelen/faturalar/excel?'.self::ARALIK
                .'&kolonlar[]=belge_no&kolonlar[]=erp_durumu&kolonlar[]=yazdirildi'
                .'&basliklar[]=Fatura%20No&basliklar[]=ERP%20Durumu&basliklar[]=Yazd%C4%B1r%C4%B1ld%C4%B1');

        $yanit->assertOk()->assertDownload('protel-gelen-2026-01-01-2026-01-31.xlsx');
        $yol = $yanit->baseResponse->getFile()->getPathname();
        $sayfa = IOFactory::load($yol)->getSheet(0);
        unlink($yol);

        $this->assertSame(['Fatura No', 'ERP Durumu', 'Yazdırıldı'], $sayfa->rangeToArray('A1:C1')[0]);
        $this->assertSame([$fatura->belge_no, 'Booked', 'Hayır'], $sayfa->rangeToArray('A2:C2')[0]);
    }

    /**
     * Ekrandaki liste Test ortamınınken aktif ortam Canlı'ya geçtiyse Excel
     * görülenden farklı faturaları indirmemeli (PRT-R3).
     */
    public function test_excel_ekrandaki_listenin_ortami_aktif_ortamla_uyusmazsa_409_doner(): void
    {
        EntegratorBaglanti::factory()->protel()->create(['ortam' => 'test']);
        $canli = EntegratorBaglanti::factory()->protel()->canli()->aktif()->create();
        EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $canli->id]);
        $kullanici = $this->izinli('protel_faturalari.goruntule', 'protel_faturalari.disari_aktar');
        $adres = '/api/v1/protel/gelen/faturalar/excel?'.self::ARALIK.'&kolonlar[]=belge_no&basliklar[]=No&ortam=';

        $this->actingAs($kullanici)->getJson($adres.'test')
            ->assertStatus(409)
            ->assertJsonPath('kod', 'ENTEGRATOR_ORTAM_DEGISTI');
        $this->actingAs($kullanici)->get($adres.'canli')->assertOk()->assertDownload();
    }

    public function test_protel_listesi_istisna_kodu_seceneklerini_sorgulamaz(): void
    {
        EFatura::factory()->protel()->create(['izibiz_istisna_kodu' => '351', 'vergi_istisna_kodu' => '350']);

        $this->actingAs($this->izinli('protel_faturalari.goruntule'))
            ->getJson('/api/v1/protel/gelen/faturalar?'.self::ARALIK)
            ->assertOk()
            ->assertJsonPath('secenekler.istisna_kodlari', []);
    }
}
