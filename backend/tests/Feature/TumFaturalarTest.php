<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\ErpBelgeArsivi;
use App\Services\Kesinti\ErpFirmaKaynagi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tüm Faturalar (kullanıcı isteği 2026-11-20): İzibiz ve Protel'e gelen
 * faturaların birleşik listesi. Kapsam sağlayıcı başına aktif tanım; kendi izinleri.
 */
final class TumFaturalarTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ARALIK = 'baslangic=2026-01-01&bitis=2026-01-31';

    /** Rolün izinleri TAM OLARAK bunlar (EFaturaEkranTest kalıbı) */
    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.md5(implode(',', $izinler))]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /** @return array{0: EntegratorBaglanti, 1: EntegratorBaglanti} [izibiz, protel] — ikisi de aktif */
    private function tanimlar(): array
    {
        return [
            EntegratorBaglanti::factory()->canli()->aktif()->create(),
            EntegratorBaglanti::factory()->protel()->canli()->aktif()->create(),
        ];
    }

    public function test_iki_saglayicinin_aktif_tanimindaki_gelen_faturalar_birlikte_listelenir_pasif_tanim_ve_giden_gelmez(): void
    {
        [$izibiz, $protel] = $this->tanimlar();
        $izibizTest = EntegratorBaglanti::factory()->create();
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'INS1', 'tutar' => '100.25']);
        EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'belge_no' => 'OTL1', 'tutar' => '200.50']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'yon' => 'giden', 'belge_no' => 'GID1']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibizTest->id, 'belge_no' => 'TST1']);

        $yanit = $this->actingAs($this->izinli('tum_faturalar.goruntule'))
            ->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK.'&sirala=belge_no&yon=asc')
            ->assertOk()
            ->assertJsonPath('kapsam.ortam', 'izibiz:canli,protel:canli')
            ->assertJsonPath('ozet.0.adet', 2)
            ->assertJsonPath('ozet.0.tutar', '300.75');

        $this->assertSame(
            [['INS1', 'izibiz'], ['OTL1', 'protel']],
            array_map(fn (array $f): array => [$f['belge_no'], $f['saglayici']], $yanit->json('data')),
        );
    }

    /**
     * Entegratör (tanımın sağlayıcısı) ve sistem kolonlarına göre sıralanır;
     * bilinmeyen (null) sistem durumu yönden bağımsız en sonda.
     */
    public function test_entegrator_ve_sistem_kolonlarina_gore_siralanir_bos_degerler_sonda(): void
    {
        [$izibiz, $protel] = $this->tanimlar();
        EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'belge_no' => 'OTL1', 'sap_islendi' => true]);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'INS1', 'sap_islendi' => null]);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'INS2', 'sap_islendi' => false]);
        $kullanici = $this->izinli('tum_faturalar.goruntule');
        $sirali = fn (string $kolon, string $yon): array => array_column(
            $this->actingAs($kullanici)->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK."&sirala={$kolon}&yon={$yon}")->assertOk()->json('data'),
            'belge_no',
        );

        $this->assertSame(['INS1', 'INS2', 'OTL1'], $sirali('entegrator', 'asc'));
        $this->assertSame(['OTL1', 'INS2', 'INS1'], $sirali('entegrator', 'desc'));
        $this->assertSame(['INS2', 'OTL1', 'INS1'], $sirali('sap', 'asc'));
        $this->assertSame(['OTL1', 'INS2', 'INS1'], $sirali('sap', 'desc'));
    }

    /** Entegratör filtresi ve aramada Entegratör kolonu (tanımın sağlayıcısı; harf / I-İ duyarsız). */
    public function test_entegrator_filtresi_ve_aramasi_saglayici_adiyla_eslesir(): void
    {
        [$izibiz, $protel] = $this->tanimlar();
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'INS1']);
        EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'belge_no' => 'OTL1']);
        $kullanici = $this->izinli('tum_faturalar.goruntule');
        $bulunan = fn (string $sorgu): array => array_column(
            $this->actingAs($kullanici)->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK.'&'.$sorgu)->assertOk()->json('data'),
            'belge_no',
        );

        $this->assertSame(['OTL1'], $bulunan('entegrator=protel'));
        $this->assertSame(['INS1'], $bulunan('entegrator=izibiz'));
        $this->assertSame(['OTL1'], $bulunan('ara=PROTEL'));
        $this->assertSame(['INS1'], $bulunan('ara='.urlencode('İzibiz')));
        $this->assertSame(['INS1'], $bulunan('ara=izib'));
        $this->assertSame([], $bulunan('ara=protel&entegrator=izibiz'));
        $this->actingAs($kullanici)->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK.'&entegrator=sap')->assertUnprocessable();
    }

    /**
     * Bu ekranda senkron paneli yok: bir posta kutusunun senkronu durursa
     * (ör. Protel şifresi değişti) ekran bunu söylemeli (PRT-F3).
     */
    public function test_saglayici_basina_verinin_guncelligi_doner(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'Europe/Istanbul'));
        [$izibiz] = $this->tanimlar();
        EFaturaSenkronCalismasi::query()->create([
            'entegrator_baglanti_id' => $izibiz->id,
            'yon' => 'gelen',
            'tarih_turu' => 'DELIVERY',
            'baslangic' => '2026-11-11',
            'bitis' => '2026-11-20',
            'tetikleyen' => EFaturaSenkronCalismasi::TETIKLEYEN_ZAMANLANMIS,
            'durum' => EFaturaSenkronCalismasi::DURUM_TAM,
            'basladi' => CarbonImmutable::now()->subMinutes(5),
            'bitti' => CarbonImmutable::now()->subMinutes(4),
        ]);

        $yanit = $this->actingAs($this->izinli('tum_faturalar.goruntule'))
            ->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK)
            ->assertOk();

        $this->assertSame([['izibiz', 'canli', true], ['protel', 'canli', false]], array_map(
            fn (array $t): array => [$t['saglayici'], $t['ortam'], $t['guncel']],
            $yanit->json('tazelik'),
        ));
        $this->assertNull($yanit->json('tazelik.1.veri_zamani'));
    }

    public function test_yalniz_bir_saglayici_aktifse_onun_faturalari_hicbiri_yoksa_hata_doner(): void
    {
        $kullanici = $this->izinli('tum_faturalar.goruntule');

        $this->actingAs($kullanici)->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK)
            ->assertStatus(422)
            ->assertJsonPath('kod', 'ENTEGRATOR_AKTIF_YOK');

        EFatura::factory()->protel()->create(['belge_no' => 'OTL1']);

        $this->actingAs($kullanici)->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('kapsam.ortam', 'protel:test');
    }

    /**
     * Kendi izinleri: sağlayıcı ekranlarının izinleri birleşik listeyi açmaz;
     * PDF ve Excel ek izin ister.
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function izinsizUclar(): array
    {
        return [
            'sağlayıcı izinleriyle liste' => ['/api/v1/tum-faturalar/faturalar?'.self::ARALIK, ['efatura.goruntule', 'protel_faturalari.goruntule']],
            'PDF izni olmadan PDF' => ['/api/v1/tum-faturalar/faturalar/1/pdf', ['tum_faturalar.goruntule', 'efatura.pdf', 'protel_faturalari.pdf']],
            'PDF izni olmadan XML' => ['/api/v1/tum-faturalar/faturalar/1/xml', ['tum_faturalar.goruntule', 'efatura.pdf']],
            'Excel izni olmadan Excel' => ['/api/v1/tum-faturalar/faturalar/excel?'.self::ARALIK, ['tum_faturalar.goruntule', 'efatura.disari_aktar']],
        ];
    }

    /**
     * @param  list<string>  $izinler
     */
    #[DataProvider('izinsizUclar')]
    public function test_ucun_izni_olmayan_kullanici_403_alir(string $adres, array $izinler): void
    {
        $this->tanimlar();

        $this->actingAs($this->izinli(...$izinler))->getJson($adres)->assertForbidden();
    }

    public function test_pdf_faturanin_kendi_saglayicisindan_alinir_xml_yalniz_izibizde_pasif_tanimin_faturasi_404(): void
    {
        config(['entegrator.protel.aktif' => true]);
        [, $protel] = $this->tanimlar();
        $this->mock(ErpBelgeArsivi::class)->shouldNotReceive('pdf');
        $pdf = "%PDF-1.4\nprotel-pdf";
        Http::preventStrayRequests();
        Http::fake([
            'https://authrestapi.protel.com.tr/v1/Auth/AccessToken' => ProtelIstemcisiTest::girisYaniti(),
            'https://einvoicerestapi.protel.com.tr/v1/Document/GetInvoicePdf' => Http::response(['isSuccess' => false, 'outputDatas' => [base64_encode($pdf)]]),
        ]);
        $fatura = EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id]);
        $pasif = EFatura::factory()->create(['entegrator_baglanti_id' => EntegratorBaglanti::factory()->create()->id]);
        $kullanici = $this->izinli('tum_faturalar.goruntule', 'tum_faturalar.pdf');

        $yanit = $this->actingAs($kullanici)->get("/api/v1/tum-faturalar/faturalar/{$fatura->id}/pdf");

        $yanit->assertOk()->assertHeader('X-Belge-Kaynagi', 'entegrator');
        $this->assertSame($pdf, $yanit->getContent());
        Http::assertSent(fn (Request $istek): bool => str_ends_with($istek->url(), '/GetInvoicePdf'));
        $this->actingAs($kullanici)->getJson("/api/v1/tum-faturalar/faturalar/{$fatura->id}/xml")->assertNotFound();
        $this->actingAs($kullanici)->getJson("/api/v1/tum-faturalar/faturalar/{$pasif->id}/pdf")->assertNotFound();
    }

    public function test_excel_iki_saglayicinin_satirlarini_entegrator_adiyla_ve_ekrandaki_kolonlarla_yazar_bilinmeyen_sistem_durumu_bos_kalir_reddedilen_protel_red_yazar(): void
    {
        [$izibiz, $protel] = $this->tanimlar();
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'INS1', 'erp_okundu' => true, 'sap_islendi' => true]);
        EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'belge_no' => 'OTL1', 'emor_durumu' => 'elle_islendi', 'sap_islendi' => false, 'gonderici_vkn' => '1112223333']);
        // Portalda reddedilmiş: daha önce İşlendi işaretlenmiş olsa da "Red"
        EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'belge_no' => 'OTL2', 'durum' => 'RejectAnswerCompleted', 'sap_islendi' => true]);
        // "eMOR VKN Var": yalnız OTL1'in göndericisi eMOR'da kayıtlı (kullanıcı isteği 2026-11-25)
        $this->app->instance(ErpFirmaKaynagi::class, new class implements ErpFirmaKaynagi
        {
            public function firmalar(): array
            {
                return [['kod' => 'K1', 'ad' => 'Kayıtlı Cari', 'guid' => 'g1', 'vergi_kimlik_no' => '1112223333', 'vergi_dairesi' => null, 'eposta' => null, 'gsm_no' => null]];
            }
        });
        $kolonlar = ['entegrator' => 'Entegratör', 'belge_no' => 'Fatura No', 'erp_okundu' => 'eMOR Okudu', 'emor' => 'eMOR', 'sap' => 'SAP', 'emor_vkn' => 'eMOR VKN Var'];
        $sorgu = http_build_query(['kolonlar' => array_keys($kolonlar), 'basliklar' => array_values($kolonlar), 'sirala' => 'belge_no', 'yon' => 'asc']);

        $yanit = $this->actingAs($this->izinli('tum_faturalar.goruntule', 'tum_faturalar.disari_aktar'))
            ->get('/api/v1/tum-faturalar/faturalar/excel?'.self::ARALIK.'&'.$sorgu.'&ortam=izibiz:canli,protel:canli');

        $yanit->assertOk()->assertDownload('tum-faturalar-2026-01-01-2026-01-31.xlsx');
        $yol = $yanit->baseResponse->getFile()->getPathname();
        $kitap = IOFactory::load($yol);
        unlink($yol);

        $sayfa = $kitap->getSheet(0);
        $this->assertSame(array_values($kolonlar), $sayfa->rangeToArray('A1:F1')[0]);
        $this->assertSame(['İzibiz', 'INS1', 'Evet', null, 'İşlendi', 'Hayır'], $sayfa->rangeToArray('A2:F2')[0]);
        // Protel faturasını eMOR okumaz (otelin ERP bayrağı ayrı kolonda); elle işlenmiş olabilir
        $this->assertSame(['Protel', 'OTL1', null, 'İşlendi (elle)', 'Hayır', 'Evet'], $sayfa->rangeToArray('A3:F3')[0]);
        $this->assertSame(['Protel', 'OTL2', null, null, 'Red', 'Hayır'], $sayfa->rangeToArray('A4:F4')[0]);
        $this->assertSame(['Liste', 'Tüm Faturalar (İzibiz + Protel)'], $kitap->getSheet(1)->rangeToArray('A1:B1')[0]);
        $this->assertSame(['Ortam', 'Canlı'], $kitap->getSheet(1)->rangeToArray('A2:B2')[0]);
    }

    /** Ekrandaki listenin kapsamı sunucuda değiştiyse görülenden farklı faturalar inmez (PRT-R3). */
    public function test_excel_ekrandaki_kapsam_aktif_tanimlarla_uyusmazsa_409_doner(): void
    {
        $this->tanimlar();

        $this->actingAs($this->izinli('tum_faturalar.goruntule', 'tum_faturalar.disari_aktar'))
            ->getJson('/api/v1/tum-faturalar/faturalar/excel?'.self::ARALIK.'&kolonlar[]=belge_no&basliklar[]=No&ortam=izibiz:canli')
            ->assertStatus(409)
            ->assertJsonPath('kod', 'ENTEGRATOR_ORTAM_DEGISTI');
    }
}
