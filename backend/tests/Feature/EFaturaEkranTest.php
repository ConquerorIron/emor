<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EFaturaManuelSenkron;
use App\Models\EFatura;
use App\Models\EFaturaSenkronCalismasi;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\Entegrator\EFaturaDurumServisi;
use App\Services\Entegrator\EFaturaExcelAktarici;
use App\Services\Entegrator\EFaturaSenkronServisi;
use App\Services\Entegrator\FaturaYonu;
use App\Services\ErpBelgeArsivi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** e-Fatura ekran uçları (EFAT-11): liste, özet, Excel, PDF/XML, durum, elle senkron. */
final class EFaturaEkranTest extends TestCase
{
    use RefreshDatabase;

    private const ARALIK = 'baslangic=2026-01-01&bitis=2026-01-31';

    /**
     * Rol, izinler TAM OLARAK bunlar olacak şekilde yazılır (RolServisi
     * görüntülemeyi tamamlardı): uçların kendi denetimi ayrıca sınanır.
     */
    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.implode(',', $izinler)]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    private function aktifTanim(): EntegratorBaglanti
    {
        return EntegratorBaglanti::factory()->aktif()->create();
    }

    /**
     * @param  array<string, mixed>  $alanlar
     */
    private function fatura(EntegratorBaglanti $tanim, array $alanlar = []): EFatura
    {
        return EFatura::factory()->create(['entegrator_baglanti_id' => $tanim->id, ...$alanlar]);
    }

    // --- Yetki -----------------------------------------------------------------

    public function test_oturumsuz_liste_istegi_401_doner(): void
    {
        $this->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK)
            ->assertUnauthorized()
            ->assertJsonPath('kod', 'YETKISIZ');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function izinsizUclar(): array
    {
        return [
            'liste' => ['get', '/api/v1/efatura/gelen/faturalar?'.self::ARALIK, 'efatura.pdf'],
            'durum' => ['get', '/api/v1/efatura/durum', 'efatura.senkron'],
            'excel' => ['get', '/api/v1/efatura/gelen/faturalar/excel?'.self::ARALIK, 'efatura.goruntule'],
            'pdf' => ['get', '/api/v1/efatura/faturalar/1/pdf', 'efatura.goruntule'],
            'xml' => ['get', '/api/v1/efatura/faturalar/1/xml', 'efatura.goruntule'],
            'gizle' => ['put', '/api/v1/efatura/faturalar/1/gizli', 'efatura.goruntule'],
            'senkron' => ['post', '/api/v1/efatura/senkron', 'efatura.goruntule'],
            // İşlem izni tek başına yetmez: görüntüleme izni de gerekir
            'excel, görüntüleme izni olmadan' => ['get', '/api/v1/efatura/gelen/faturalar/excel?'.self::ARALIK, 'efatura.disari_aktar'],
            'pdf, görüntüleme izni olmadan' => ['get', '/api/v1/efatura/faturalar/1/pdf', 'efatura.pdf'],
            'xml, görüntüleme izni olmadan' => ['get', '/api/v1/efatura/faturalar/1/xml', 'efatura.pdf'],
            'gizle, görüntüleme izni olmadan' => ['put', '/api/v1/efatura/faturalar/1/gizli', 'efatura.gizle'],
            'senkron, görüntüleme izni olmadan' => ['post', '/api/v1/efatura/senkron', 'efatura.senkron'],
        ];
    }

    #[DataProvider('izinsizUclar')]
    public function test_ucun_izni_olmayan_kullanici_403_alir(string $metot, string $adres, string $baskaIzin): void
    {
        $this->aktifTanim();

        $this->actingAs($this->izinli($baskaIzin))
            ->json($metot, $adres, ['baslangic' => '2026-01-01', 'bitis' => '2026-01-31'])
            ->assertForbidden()
            ->assertJsonPath('kod', 'ERISIM_ENGELLI');
    }

    // --- Liste -----------------------------------------------------------------

    public function test_liste_yalniz_aktif_hesabin_secilen_yon_ve_tarihteki_faturalarini_doner(): void
    {
        $tanim = $this->aktifTanim();
        $canli = EntegratorBaglanti::factory()->canli()->create();
        $beklenen = $this->fatura($tanim, ['belge_no' => 'GLN2026000000001']);
        $this->fatura($tanim, ['belge_no' => 'GDN2026000000001', 'yon' => 'giden']);
        $this->fatura($tanim, ['belge_no' => 'GLN2026000000002', 'belge_tarihi' => '2026-02-01']);
        $this->fatura($canli, ['belge_no' => 'CNL2026000000001']);

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $beklenen->id)
            ->assertJsonPath('data.0.tutar', '1250.5000')
            ->assertJsonPath('meta.total', 1)
            // sayfa_boyutu gönderilmezse varsayılan 50 (frontend ile aynı)
            ->assertJsonPath('meta.per_page', 50)
            ->assertJsonPath('kapsam.ortam', 'test');
    }

    public function test_ozet_para_birimi_bazinda_toplar_ve_filtreyle_ayni_kapsami_kullanir(): void
    {
        $tanim = $this->aktifTanim();
        $this->fatura($tanim, ['tutar' => '100.2500', 'vergi_tutari' => '18.0000']);
        $this->fatura($tanim, ['tutar' => '200.5000', 'vergi_tutari' => null]);
        $this->fatura($tanim, ['para_birimi' => 'USD', 'tutar' => '50.0000', 'vergi_tutari' => '0.0000']);
        $this->fatura($tanim, ['tutar' => '999.0000', 'erp_okundu' => false]);

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&erp_okundu=evet')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('ozet', [
                ['para_birimi' => 'TRY', 'adet' => 2, 'tutar' => '300.75', 'vergi_tutari' => '18.00'],
                ['para_birimi' => 'USD', 'adet' => 1, 'tutar' => '50.00', 'vergi_tutari' => '0.00'],
            ])
            ->assertJsonPath('secenekler.para_birimleri', ['TRY', 'USD']);
    }

    public function test_durum_secenekleri_kod_ve_turkce_aciklamayla_doner(): void
    {
        $tanim = $this->aktifTanim();
        $this->fatura($tanim, ['durum' => 'RECEIVED', 'durum_aciklamasi' => 'Alındı']);
        $this->fatura($tanim, ['durum' => 'ACCEPTED', 'durum_aciklamasi' => 'Kabul Edildi']);
        $this->fatura($tanim, ['durum' => 'ACCEPTED', 'durum_aciklamasi' => 'Kabul Edildi']);
        $this->fatura($tanim, ['durum' => 'NEW', 'durum_aciklamasi' => null]);

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK)
            ->assertJsonPath('secenekler.durumlar', [
                ['deger' => 'ACCEPTED', 'aciklama' => 'Kabul Edildi'],
                ['deger' => 'NEW', 'aciklama' => null],
                ['deger' => 'RECEIVED', 'aciklama' => 'Alındı'],
            ]);
    }

    public function test_arama_gelen_faturada_gondericiyi_giden_faturada_aliciyi_arar(): void
    {
        $tanim = $this->aktifTanim();
        $gelen = $this->fatura($tanim, ['gonderici_unvan' => 'Deniz Boya Ltd.', 'alici_unvan' => 'Bizim Tersane']);
        $this->fatura($tanim, ['gonderici_unvan' => 'Başka Firma', 'alici_unvan' => 'Deniz Boya Ltd.']);
        $giden = $this->fatura($tanim, ['yon' => 'giden', 'gonderici_unvan' => 'Bizim Tersane', 'alici_unvan' => 'Deniz Boya Ltd.']);
        $kullanici = $this->izinli('efatura.goruntule');

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&ara=deniz')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $gelen->id);

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/giden/faturalar?'.self::ARALIK.'&ara=deniz')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $giden->id);
    }

    public function test_arama_fatura_tipini_ve_karsi_tarafin_ad_soyadini_da_arar(): void
    {
        $tanim = $this->aktifTanim();
        $kisi = $this->fatura($tanim, ['gonderici_ad_soyad' => 'Ayşe Yılmaz', 'alici_ad_soyad' => 'Mehmet Kaya']);
        $iade = $this->fatura($tanim, ['fatura_tipi' => 'IADE']);
        $kullanici = $this->izinli('efatura.goruntule');

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&ara=Yılmaz')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $kisi->id);

        // Gelen faturada karşı taraf göndericidir; alıcının adıyla bulunmaz
        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&ara=Kaya')
            ->assertJsonCount(0, 'data');

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&ara=IADE')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $iade->id);
    }

    public function test_siralama_izinli_kolona_gore_yapilir(): void
    {
        $tanim = $this->aktifTanim();
        $kucuk = $this->fatura($tanim, ['tutar' => '10.0000']);
        $buyuk = $this->fatura($tanim, ['tutar' => '900.0000']);

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&sirala=tutar&yon=asc')
            ->assertJsonPath('data.0.id', $kucuk->id)
            ->assertJsonPath('data.1.id', $buyuk->id);
    }

    /**
     * [sıralama anahtarı, küçük değerli alanlar, büyük değerli alanlar]
     *
     * @return array<string, array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}>
     */
    public static function yeniSiralamalar(): array
    {
        return [
            'eMOR (aşama sırası, alfabetik değil)' => ['emor', ['emor_durumu' => 'yok'], ['emor_durumu' => 'havuzda']],
            'ERP okudu' => ['erp_okundu', ['erp_okundu' => false], ['erp_okundu' => true]],
            'VKN (gelende gönderici)' => ['karsi_vkn', ['gonderici_vkn' => '111'], ['gonderici_vkn' => '999']],
            'ad soyad' => ['karsi_ad_soyad', ['gonderici_ad_soyad' => 'Ali'], ['gonderici_ad_soyad' => 'Zeynep']],
            'tip' => ['fatura_tipi', ['fatura_tipi' => 'IADE'], ['fatura_tipi' => 'SATIS']],
            'para birimi' => ['para_birimi', ['para_birimi' => 'EUR'], ['para_birimi' => 'USD']],
            'irsaliye no' => ['irsaliye_no', ['irsaliye_no' => 'A1'], ['irsaliye_no' => 'B2']],
            'sipariş no' => ['siparis_no', ['siparis_no' => 'A1'], ['siparis_no' => 'B2']],
            'durum (açıklamasına göre)' => ['durum', ['durum_aciklamasi' => 'Alındı'], ['durum_aciklamasi' => 'Reddedildi']],
            'zarf durumu (GİB koduna göre)' => ['zarf_durumu', ['gib_durum_kodu' => 1200], ['gib_durum_kodu' => 1300]],
            'yanıt açıklaması' => ['yanit_aciklamasi', ['yanit_aciklamasi' => 'Kabul'], ['yanit_aciklamasi' => 'Red']],
        ];
    }

    /**
     * @param  array<string, mixed>  $kucukAlanlar
     * @param  array<string, mixed>  $buyukAlanlar
     */
    #[DataProvider('yeniSiralamalar')]
    public function test_tablo_kolonlarina_gore_siralanir_bos_degerler_en_sonda(string $anahtar, array $kucukAlanlar, array $buyukAlanlar): void
    {
        $tanim = $this->aktifTanim();
        $buyuk = $this->fatura($tanim, $buyukAlanlar);
        $kucuk = $this->fatura($tanim, $kucukAlanlar);
        // Para birimi zorunlu kolon: boş değerli kayıt yalnız boş olabilen kolonlarda
        $bos = $anahtar === 'para_birimi' ? null : $this->fatura($tanim, array_map(fn (): null => null, $kucukAlanlar));
        $kullanici = $this->izinli('efatura.goruntule');
        $sirasi = fn (string $yon): array => array_column(
            $this->actingAs($kullanici)->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK."&sirala={$anahtar}&yon={$yon}")->assertOk()->json('data'),
            'id',
        );
        $bosId = $bos === null ? [] : [$bos->id];

        $this->assertSame([$kucuk->id, $buyuk->id, ...$bosId], $sirasi('asc'));
        $this->assertSame([$buyuk->id, $kucuk->id, ...$bosId], $sirasi('desc'));
    }

    public function test_arama_siparis_irsaliye_ve_zarf_durumunda_da_arar(): void
    {
        $tanim = $this->aktifTanim();
        $siparis = $this->fatura($tanim, ['siparis_no' => 'SIP-4242']);
        $irsaliye = $this->fatura($tanim, ['irsaliye_no' => 'IRS-7777']);
        $zarf = $this->fatura($tanim, ['gib_durum_kodu' => 1215, 'gib_durum_aciklamasi' => 'ALICIDAN YANIT BEKLENIYOR']);
        $kullanici = $this->izinli('efatura.goruntule');
        $bulunan = fn (string $ara): array => array_column(
            $this->actingAs($kullanici)->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&ara='.urlencode($ara))->json('data'),
            'id',
        );

        $this->assertSame([$siparis->id], $bulunan('4242'));
        $this->assertSame([$irsaliye->id], $bulunan('irs-777'));
        $this->assertSame([$zarf->id], $bulunan('yanit bek'));
        $this->assertSame([$zarf->id], $bulunan('1215'));
    }

    public function test_emor_filtresi_asamaya_gore_suzer(): void
    {
        $tanim = $this->aktifTanim();
        $havuzda = $this->fatura($tanim, ['emor_durumu' => 'havuzda']);
        $this->fatura($tanim, ['emor_durumu' => 'islendi']);
        $bilinmiyor = $this->fatura($tanim);
        $kullanici = $this->izinli('efatura.goruntule');

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&emor=havuzda')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $havuzda->id)
            ->assertJsonPath('data.0.emor_durumu', 'havuzda');
        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&emor=bilinmiyor')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bilinmiyor->id);
        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&emor=muhasebe')
            ->assertUnprocessable();
    }

    public function test_hizli_filtreler_islenmeyenleri_ve_istisnalilari_suzer(): void
    {
        $tanim = $this->aktifTanim();
        $this->fatura($tanim, ['emor_durumu' => 'islendi']);
        $havuzda = $this->fatura($tanim, ['emor_durumu' => 'havuzda', 'vergi_istisna_kodu' => '351']);
        $yok = $this->fatura($tanim, ['emor_durumu' => 'yok']);
        $bilinmiyor = $this->fatura($tanim);
        $kullanici = $this->izinli('efatura.goruntule');
        $idler = fn (string $sorgu): array => array_column(
            $this->actingAs($kullanici)->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&sirala=belge_no&yon=asc&'.$sorgu)->assertOk()->json('data'),
            'id',
        );

        // "İşlendi" yazmayanların hepsi (kontrol edilmemiş dahil)
        $this->assertEqualsCanonicalizing([$havuzda->id, $yok->id, $bilinmiyor->id], $idler('emor=islenmemis'));
        $this->assertSame([$havuzda->id], $idler('istisnali=evet'));
        $this->assertSame([$havuzda->id], $idler('emor=islenmemis&istisnali=evet'));
    }

    public function test_tip_ve_istisna_kodu_filtreleri_ve_secenekleri(): void
    {
        $tanim = $this->aktifTanim();
        $istisna = $this->fatura($tanim, ['fatura_tipi' => 'ISTISNA', 'vergi_istisna_kodu' => '318']);
        $this->fatura($tanim, ['fatura_tipi' => 'ISTISNA', 'vergi_istisna_kodu' => '351']);
        $this->fatura($tanim, ['fatura_tipi' => 'SATIS']);
        // Seçili aralık dışındaki değer seçeneklere girmez
        $this->fatura($tanim, ['fatura_tipi' => 'IADE', 'vergi_istisna_kodu' => '999', 'belge_tarihi' => '2026-03-01']);
        $kullanici = $this->izinli('efatura.goruntule');

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK)
            ->assertJsonPath('secenekler.tipler', ['ISTISNA', 'SATIS'])
            ->assertJsonPath('secenekler.istisna_kodlari', ['318', '351']);

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&tip=ISTISNA&istisna_kodu=318')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $istisna->id);
        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&istisnali=hayir')
            ->assertUnprocessable();
    }

    public function test_izinsiz_siralama_kolonu_422_doner(): void
    {
        $this->aktifTanim();

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK.'&sirala=entegrator_baglanti_id')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('sirala', 'hatalar');
    }

    public function test_bir_yildan_genis_aralik_422_doner(): void
    {
        $this->aktifTanim();

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/gelen/faturalar?baslangic=2026-01-01&bitis=2027-01-02')
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.bitis.0', 'Tarih aralığı en fazla 366 gün olabilir.');
    }

    public function test_aktif_entegrator_yokken_liste_422_entegrator_aktif_yok_doner(): void
    {
        EntegratorBaglanti::factory()->create();

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK)
            ->assertUnprocessable()
            ->assertJsonPath('kod', 'ENTEGRATOR_AKTIF_YOK');
    }

    // --- Excel -----------------------------------------------------------------

    /** Ekrandaki görünür kolonlar ve başlıkları, sırasıyla (Excel isteğinin parçası) */
    private const EXCEL_KOLONLARI = 'kolonlar[]=karsi_unvan&kolonlar[]=belge_no&kolonlar[]=tutar&kolonlar[]=emor'
        .'&basliklar[]=Unvan&basliklar[]=Fatura%20No&basliklar[]=Tutar&basliklar[]=eMOR';

    public function test_excel_ekrandaki_sayfayi_kolonlari_ve_basliklari_yazar_formul_benzeri_metni_metin_tutar(): void
    {
        $tanim = $this->aktifTanim();
        foreach (range(1, 25) as $i) {
            $this->fatura($tanim, ['belge_no' => sprintf('ABC%013d', $i)]);
        }
        // Sıralamada 26. kayıt: 25'lik sayfada 2. sayfanın tek satırı
        $this->fatura($tanim, [
            'belge_no' => 'ABC9999999999999',
            'gonderici_unvan' => '=HYPERLINK("http://kotu.test","tikla")',
            'tutar' => '99.9900',
            'emor_durumu' => 'elle_islendi',
        ]);
        $this->fatura($tanim, ['belge_no' => 'ABC0000000000000', 'belge_tarihi' => '2026-03-01']);

        $yanit = $this->actingAs($this->izinli('efatura.goruntule', 'efatura.disari_aktar'))
            ->get('/api/v1/efatura/gelen/faturalar/excel?'.self::ARALIK.'&sirala=belge_no&yon=asc&page=2&sayfa_boyutu=25&'.self::EXCEL_KOLONLARI);

        $yanit->assertOk()->assertDownload('efatura-gelen-2026-01-01-2026-01-31.xlsx');
        $yol = $yanit->baseResponse->getFile()->getPathname();
        $kitap = IOFactory::load($yol);
        unlink($yol);
        $sayfa = $kitap->getSheet(0);

        // Yalnız ekrandaki sayfa ve yalnız görünen kolonlar, ekrandaki sırayla
        $this->assertSame(2, $sayfa->getHighestDataRow());
        $this->assertSame('D', $sayfa->getHighestDataColumn());
        $this->assertSame(['Unvan', 'Fatura No', 'Tutar', 'eMOR'], $sayfa->rangeToArray('A1:D1')[0]);
        $this->assertSame(DataType::TYPE_STRING, $sayfa->getCell('A2')->getDataType());
        $this->assertSame('=HYPERLINK("http://kotu.test","tikla")', $sayfa->getCell('A2')->getValue());
        $this->assertSame('ABC9999999999999', $sayfa->getCell('B2')->getValue());
        $this->assertSame(99.99, $sayfa->getCell('C2')->getValue());
        $this->assertSame('İşlendi (elle)', $sayfa->getCell('D2')->getValue());
        // Özet de yazılan satırlardan
        $this->assertSame(1, $kitap->getSheet(1)->getCell('B7')->getValue());
    }

    public function test_excel_sayfa_verilmezse_filtrenin_tamamini_yazar(): void
    {
        $tanim = $this->aktifTanim();
        $this->fatura($tanim, ['belge_no' => 'ABC2026000000001']);
        $this->fatura($tanim, ['belge_no' => 'ABC2026000000002']);

        $yanit = $this->actingAs($this->izinli('efatura.goruntule', 'efatura.disari_aktar'))
            ->get('/api/v1/efatura/gelen/faturalar/excel?'.self::ARALIK.'&'.self::EXCEL_KOLONLARI);

        $yol = $yanit->assertOk()->baseResponse->getFile()->getPathname();
        $sayfa = IOFactory::load($yol)->getSheet(0);
        unlink($yol);

        $this->assertSame(3, $sayfa->getHighestDataRow());
    }

    public function test_excel_kolonlar_zorunlu_ve_katalogda_olmali(): void
    {
        $this->aktifTanim();
        $kullanici = $this->izinli('efatura.goruntule', 'efatura.disari_aktar');

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar/excel?'.self::ARALIK)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('kolonlar', 'hatalar');

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar/excel?'.self::ARALIK.'&kolonlar[]=password&basliklar[]=X')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('kolonlar.0', 'hatalar');

        // Her kolonun bir başlığı olmalı
        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar/excel?'.self::ARALIK.'&kolonlar[]=belge_no&kolonlar[]=tutar&basliklar[]=X')
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('basliklar', 'hatalar');
    }

    public function test_excel_satir_siniri_asilirsa_422_ve_kod_doner(): void
    {
        config(['efatura.excel_azami_satir' => 1]);
        $tanim = $this->aktifTanim();
        $this->fatura($tanim);
        $this->fatura($tanim);

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.disari_aktar'))
            ->getJson('/api/v1/efatura/gelen/faturalar/excel?'.self::ARALIK.'&'.self::EXCEL_KOLONLARI)
            ->assertUnprocessable()
            ->assertJsonPath('kod', 'EFATURA_EXCEL_COK_BUYUK');
    }

    public function test_excel_buyuk_ozet_tutarini_float_yuvarlamasi_olmadan_yazar(): void
    {
        $tanim = $this->aktifTanim();
        $yol = app(EFaturaExcelAktarici::class)->olustur(
            EFatura::query()->whereRaw('1 = 0'),
            FaturaYonu::Gelen,
            $tanim,
            [['para_birimi' => 'TRY', 'adet' => 1, 'tutar' => '1234567890123456.78', 'vergi_tutari' => '12.50']],
            ['baslangic' => '2026-01-01', 'bitis' => '2026-01-31'],
            [['anahtar' => 'belge_no', 'baslik' => 'Fatura No']],
        );

        try {
            $sayfa = IOFactory::load($yol)->getSheet(1);
            $this->assertSame(DataType::TYPE_STRING, $sayfa->getCell('C7')->getDataType());
            $this->assertSame('1234567890123456.78', $sayfa->getCell('C7')->getValue());
            $this->assertSame(DataType::TYPE_NUMERIC, $sayfa->getCell('D7')->getDataType());
        } finally {
            unlink($yol);
        }
    }

    // --- PDF -------------------------------------------------------------------

    private function sahteIzibizPdf(string $govde, int $durum = 200): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $istek) use ($govde, $durum) {
            if (str_ends_with($istek->url(), '/v1/auth/token')) {
                return Http::response([
                    'data' => ['accessToken' => 'erisim-token', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'],
                    'error' => null,
                ]);
            }

            if (str_starts_with($istek->url(), 'https://apitest.izibiz.com.tr/v1/einvoices/')) {
                return Http::response($govde, $durum);
            }

            return null;
        });
    }

    public function test_pdf_izibizden_kaynak_kimligiyle_okunup_aynen_doner(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = $this->aktifTanim();
        $fatura = $this->fatura($tanim, ['yon' => 'giden', 'kaynak_id' => 4242, 'belge_no' => 'ABC/2026"1']);
        $this->sahteIzibizPdf('%PDF-1.4 deneme');

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.pdf'))
            ->get("/api/v1/efatura/faturalar/{$fatura->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'inline; filename="ABC_2026_1.pdf"')
            ->assertContent('%PDF-1.4 deneme');

        Http::assertSent(fn (Request $istek) => $istek->url() === 'https://apitest.izibiz.com.tr/v1/einvoices/outbox/4242/preview/pdf'
            && $istek->method() === 'GET');
    }

    public function test_pdf_olmayan_yanit_502_entegrator_yanit_gecersiz_doner(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $fatura = $this->fatura($this->aktifTanim());
        $this->erpArsivi();
        $this->sahteIzibizPdf('<html>hata</html>');

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.pdf'))
            ->getJson("/api/v1/efatura/faturalar/{$fatura->id}/pdf")
            ->assertStatus(502)
            ->assertJsonPath('kod', 'ENTEGRATOR_YANIT_GECERSIZ');
    }

    public function test_aktif_olmayan_hesabin_faturasi_404_doner_ve_izibize_gidilmez(): void
    {
        $this->aktifTanim();
        $fatura = $this->fatura(EntegratorBaglanti::factory()->canli()->create());
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.pdf'))
            ->getJson("/api/v1/efatura/faturalar/{$fatura->id}/pdf")
            ->assertNotFound()
            ->assertJsonPath('kod', 'BULUNAMADI');

        Http::assertNothingSent();
    }

    // --- Fatura aslı: önce ERP havuzu, yoksa entegratör ---------------------------

    /** @var list<string> ERP arşivine sorulan ETTN'ler */
    public array $erpSorulan = [];

    /**
     * ERP havuzu (TOHOM_E_FATURA) sahtesi: ETTN => içerik; $hata verilirse ERP'ye ulaşılamaz.
     *
     * @param  array<string, string>  $pdfler
     * @param  array<string, string>  $xmller
     */
    private function erpArsivi(array $pdfler = [], array $xmller = [], ?RuntimeException $hata = null): void
    {
        $this->app->instance(ErpBelgeArsivi::class, new class($this, $pdfler, $xmller, $hata) implements ErpBelgeArsivi
        {
            /**
             * @param  array<string, string>  $pdfler
             * @param  array<string, string>  $xmller
             */
            public function __construct(private readonly EFaturaEkranTest $test, private readonly array $pdfler, private readonly array $xmller, private readonly ?RuntimeException $hata) {}

            public function pdf(string $ettn): ?string
            {
                return $this->oku($this->pdfler, $ettn);
            }

            public function xml(string $ettn): ?string
            {
                return $this->oku($this->xmller, $ettn);
            }

            public function istisnaXmlleri(array $ettnler): array
            {
                return [];
            }

            /** @param array<string, string> $kaynak */
            private function oku(array $kaynak, string $ettn): ?string
            {
                $this->test->erpSorulan[] = $ettn;

                if ($this->hata !== null) {
                    throw $this->hata;
                }

                return $kaynak[$ettn] ?? null;
            }
        });
    }

    public function test_gelen_faturanin_pdfi_erp_havuzunda_varsa_oradan_doner_entegratore_gidilmez(): void
    {
        $fatura = $this->fatura($this->aktifTanim(), ['ettn' => 'aaaaaaaa-0000-0000-0000-000000000001', 'belge_no' => 'GLN2026000000001']);
        $this->erpArsivi(pdfler: ['aaaaaaaa-0000-0000-0000-000000000001' => '%PDF-1.7 erp']);
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.pdf'))
            ->get("/api/v1/efatura/faturalar/{$fatura->id}/pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Belge-Kaynagi', 'erp')
            ->assertContent('%PDF-1.7 erp');

        Http::assertNothingSent();
    }

    public function test_gelen_faturanin_pdfi_erp_havuzunda_yoksa_entegratorden_okunur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $fatura = $this->fatura($this->aktifTanim(), ['ettn' => 'aaaaaaaa-0000-0000-0000-000000000001', 'kaynak_id' => 77]);
        $this->erpArsivi();
        $this->sahteIzibizPdf('%PDF-1.4 izibiz');

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.pdf'))
            ->get("/api/v1/efatura/faturalar/{$fatura->id}/pdf")
            ->assertOk()
            ->assertHeader('X-Belge-Kaynagi', 'entegrator')
            ->assertContent('%PDF-1.4 izibiz');

        $this->assertSame(['aaaaaaaa-0000-0000-0000-000000000001'], $this->erpSorulan);
        Http::assertSent(fn (Request $istek) => $istek->url() === 'https://apitest.izibiz.com.tr/v1/einvoices/inbox/77/preview/pdf');
    }

    public function test_erpye_ulasilamazsa_pdf_entegratorden_okunur_ve_uyari_loglanir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $fatura = $this->fatura($this->aktifTanim());
        $this->erpArsivi(hata: new RuntimeException('SELECT permission was denied'));
        $this->sahteIzibizPdf('%PDF-1.4 izibiz');
        Log::spy();

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.pdf'))
            ->get("/api/v1/efatura/faturalar/{$fatura->id}/pdf")
            ->assertOk()
            ->assertHeader('X-Belge-Kaynagi', 'entegrator');

        Log::shouldHaveReceived('warning')->with('ERP fatura arşivi okunamadı; entegratörden okunuyor', ['fatura_id' => $fatura->id, 'hata' => 'SELECT permission was denied'])->once();
    }

    public function test_gelen_faturanin_xmli_erp_havuzundan_utf8_bildirimiyle_ek_olarak_doner(): void
    {
        $fatura = $this->fatura($this->aktifTanim(), ['ettn' => 'aaaaaaaa-0000-0000-0000-000000000001', 'belge_no' => 'GLN2026000000001']);
        // ERP XML'i bildirimsiz saklar
        $this->erpArsivi(xmller: ['aaaaaaaa-0000-0000-0000-000000000001' => '<Invoice><cbc:ID>İŞ</cbc:ID></Invoice>']);
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.pdf'))
            ->get("/api/v1/efatura/faturalar/{$fatura->id}/xml")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="GLN2026000000001.xml"')
            ->assertHeader('X-Belge-Kaynagi', 'erp')
            ->assertContent("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Invoice><cbc:ID>İŞ</cbc:ID></Invoice>");

        Http::assertNothingSent();
    }

    public function test_giden_faturanin_xmli_erpye_sorulmadan_entegratorden_toplu_indirmeyle_okunur(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $fatura = $this->fatura($this->aktifTanim(), ['yon' => 'giden', 'kaynak_id' => 4242]);
        $this->erpArsivi();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><Invoice/>';
        $yol = (string) tempnam(sys_get_temp_dir(), 'test-ubl-');
        $zip = new ZipArchive;
        $zip->open($yol, ZipArchive::OVERWRITE);
        $zip->addFromString('fatura.xml', $xml);
        $zip->close();
        $zipIcerigi = (string) file_get_contents($yol);
        unlink($yol);

        Http::preventStrayRequests();
        Http::fake([
            'apitest.izibiz.com.tr/v1/auth/token' => Http::response(['data' => ['accessToken' => 'erisim-token', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'], 'error' => null]),
            'apitest.izibiz.com.tr/v1/einvoices/outbox/download/ubl' => Http::response(['data' => ['filename' => 'x.zip', 'content' => base64_encode($zipIcerigi)], 'error' => null]),
        ]);

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.pdf'))
            ->get("/api/v1/efatura/faturalar/{$fatura->id}/xml")
            ->assertOk()
            ->assertHeader('X-Belge-Kaynagi', 'entegrator')
            ->assertContent($xml);

        // ERP havuzu yalnız gelen faturaları tutar
        $this->assertSame([], $this->erpSorulan);
        Http::assertSent(fn (Request $istek) => $istek->url() === 'https://apitest.izibiz.com.tr/v1/einvoices/outbox/download/ubl'
            && $istek->method() === 'POST'
            && $istek->data() === [['id' => 4242]]);
    }

    // --- Gizleme -----------------------------------------------------------------

    public function test_gizlenen_fatura_varsayilan_listede_yok_istenince_gelir_ve_listeye_geri_alinir(): void
    {
        $this->travelTo('2026-09-24 13:00:00');
        $tanim = $this->aktifTanim();
        $bizimDegil = $this->fatura($tanim, ['belge_no' => 'YNL2026000000001', 'tutar' => '100.0000']);
        $this->fatura($tanim, ['belge_no' => 'GLN2026000000001', 'tutar' => '50.0000']);
        $kullanici = $this->izinli('efatura.goruntule', 'efatura.gizle');

        $this->actingAs($kullanici)
            ->putJson("/api/v1/efatura/faturalar/{$bizimDegil->id}/gizli", ['gizli' => true])
            ->assertOk()
            ->assertJsonPath('data.gizli', true)
            ->assertJsonPath('data.gizlenme_zamani', '2026-09-24T13:00:00+00:00')
            ->assertJsonPath('data.gizleyen', $kullanici->ad);
        $this->assertSame($kullanici->id, $bizimDegil->fresh()->gizleyen_id);

        // Varsayılan: gizlenen listede, özette yok; sayısı anahtarın yanında
        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.belge_no', 'GLN2026000000001')
            ->assertJsonPath('ozet.0.adet', 1)
            ->assertJsonPath('secenekler.gizlenen_adet', 1);

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?gizlenenler=dahil&sirala=belge_no&yon=desc&'.self::ARALIK)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.gizli', true)
            ->assertJsonPath('data.0.gizleyen', $kullanici->ad)
            ->assertJsonPath('data.1.gizli', false);

        $this->actingAs($kullanici)
            ->putJson("/api/v1/efatura/faturalar/{$bizimDegil->id}/gizli", ['gizli' => false])
            ->assertOk()
            ->assertJsonPath('data.gizli', false)
            ->assertJsonPath('data.gizleyen', null);

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/gelen/faturalar?'.self::ARALIK)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('secenekler.gizlenen_adet', 0);
    }

    public function test_zaten_gizli_fatura_yeniden_gizlenince_ilk_gizleme_bilgisi_korunur(): void
    {
        $this->travelTo('2026-09-24 13:00:00');
        $tanim = $this->aktifTanim();
        $ilk = $this->izinli('efatura.goruntule', 'efatura.gizle');
        $fatura = $this->fatura($tanim, ['gizlenme_zamani' => now()->subHour(), 'gizleyen_id' => $ilk->id]);

        $this->actingAs(User::factory()->yonetici()->create())
            ->putJson("/api/v1/efatura/faturalar/{$fatura->id}/gizli", ['gizli' => true])
            ->assertOk()
            ->assertJsonPath('data.gizlenme_zamani', '2026-09-24T12:00:00+00:00')
            ->assertJsonPath('data.gizleyen', $ilk->ad);
    }

    public function test_gizleme_degeri_zorunludur_ve_baska_hesabin_faturasi_404_doner(): void
    {
        $tanim = $this->aktifTanim();
        $fatura = $this->fatura($tanim);
        $baskaHesabin = $this->fatura(EntegratorBaglanti::factory()->canli()->create());
        $kullanici = $this->izinli('efatura.goruntule', 'efatura.gizle');

        $this->actingAs($kullanici)
            ->putJson("/api/v1/efatura/faturalar/{$fatura->id}/gizli", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('gizli', 'hatalar');

        $this->actingAs($kullanici)
            ->putJson("/api/v1/efatura/faturalar/{$baskaHesabin->id}/gizli", ['gizli' => true])
            ->assertNotFound()
            ->assertJsonPath('kod', 'BULUNAMADI');
        $this->assertNull($baskaHesabin->fresh()->gizlenme_zamani);
    }

    // --- Durum -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $alanlar
     */
    private function calisma(EntegratorBaglanti $tanim, array $alanlar): EFaturaSenkronCalismasi
    {
        return EFaturaSenkronCalismasi::query()->create([
            'entegrator_baglanti_id' => $tanim->id,
            'yon' => 'gelen',
            'tarih_turu' => 'DELIVERY',
            'baslangic' => '2026-09-22',
            'bitis' => '2026-09-23',
            'tetikleyen' => 'zamanlanmis',
            'durum' => 'tam',
            'basladi' => now()->subMinute(),
            'bitti' => now(),
            ...$alanlar,
        ]);
    }

    public function test_durum_bugunu_kapsayan_yeni_tam_calismayi_guncel_sayar(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        $tanim = $this->aktifTanim();
        $this->calisma($tanim, ['bitti' => now()->subMinutes(20)]);

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/durum')
            ->assertOk()
            ->assertJsonPath('data.ortam', 'test')
            ->assertJsonPath('data.yonler.gelen.guncel', true)
            ->assertJsonPath('data.yonler.gelen.ardisik_hata', 0)
            ->assertJsonPath('data.yonler.giden.guncel', false)
            ->assertJsonPath('data.yonler.giden.veri_zamani', null);
    }

    public function test_durum_eski_aralik_veya_eksik_calismayi_guncel_saymaz_ve_ardisik_hatayi_sayar(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        $tanim = $this->aktifTanim();
        // Bugünü kapsamayan elle senkron — bugünkü verinin güncelliğini kanıtlamaz
        $this->calisma($tanim, ['baslangic' => '2026-01-01', 'bitis' => '2026-01-31', 'tetikleyen' => 'manuel', 'basladi' => now()->subHour()]);
        $this->calisma($tanim, ['durum' => 'basarisiz', 'hata_kodu' => 'ENTEGRATOR_ERISILEMEDI', 'basladi' => now()->subMinutes(30)]);
        $this->calisma($tanim, ['durum' => 'eksik', 'eksik_nedeni' => 'SAYFA_SINIRI', 'basladi' => now()->subMinutes(15)]);

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/durum')
            ->assertJsonPath('data.yonler.gelen.guncel', false)
            ->assertJsonPath('data.yonler.gelen.ardisik_hata', 2)
            ->assertJsonPath('data.yonler.gelen.son_calisma.durum', 'eksik')
            ->assertJsonMissingPath('data.yonler.gelen.son_calisma.hata_mesaji');
    }

    public function test_durum_gece_yarisindan_hemen_sonra_dunku_artimli_calismayi_guncel_sayar(): void
    {
        // 00:05 İstanbul; son çalışma 23:50'de başlayıp dünü kapsadı
        $this->travelTo('2026-09-22 21:05:00');
        $tanim = $this->aktifTanim();
        $this->calisma($tanim, [
            'baslangic' => '2026-09-21',
            'bitis' => '2026-09-22',
            'basladi' => '2026-09-22 20:50:00',
            'bitti' => '2026-09-22 20:50:30',
        ]);

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/durum')
            ->assertJsonPath('data.yonler.gelen.guncel', true);
    }

    public function test_durum_yarim_kalan_eski_calismayi_suruyor_saymaz(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        $tanim = $this->aktifTanim();
        $this->calisma($tanim, ['durum' => 'calisiyor', 'bitti' => null, 'basladi' => now()->subHours(3)]);
        $this->calisma($tanim, ['yon' => 'giden', 'durum' => 'calisiyor', 'bitti' => null, 'basladi' => now()->subMinutes(2)]);

        $this->actingAs($this->izinli('efatura.goruntule'))
            ->getJson('/api/v1/efatura/durum')
            ->assertJsonPath('data.yonler.gelen.calisiyor', false)
            ->assertJsonPath('data.yonler.giden.calisiyor', true);
    }

    // --- Elle senkron ----------------------------------------------------------

    public function test_elle_senkron_kuyruga_alinir_ve_ikinci_istek_409_doner(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        Queue::fake([EFaturaManuelSenkron::class]);
        $tanim = $this->aktifTanim();
        $kullanici = $this->izinli('efatura.senkron', 'efatura.goruntule');

        $this->actingAs($kullanici)
            ->postJson('/api/v1/efatura/senkron', ['baslangic' => '2026-09-01', 'bitis' => '2026-09-23'])
            ->assertAccepted();

        $this->actingAs($kullanici)
            ->postJson('/api/v1/efatura/senkron', ['baslangic' => '2026-09-01', 'bitis' => '2026-09-23'])
            ->assertStatus(409)
            ->assertJsonPath('kod', 'EFATURA_SENKRON_SURUYOR');

        Queue::assertPushed(EFaturaManuelSenkron::class, 1);
        Queue::assertPushed(EFaturaManuelSenkron::class, fn (EFaturaManuelSenkron $is) => $is->tanimId === $tanim->id
            && $is->baslangic === '2026-09-01'
            && $is->bitis === '2026-09-23'
            && $is->kullaniciId === $kullanici->id);

        $this->actingAs($kullanici)
            ->getJson('/api/v1/efatura/durum')
            ->assertJsonPath('data.manuel_istek.baslangic', '2026-09-01');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4?: string}>
     */
    public static function gecersizSenkronAraliklari(): array
    {
        return [
            'ilk tarama öncesi' => ['2025-12-31', '2026-01-15', 'baslangic', 'e-Faturalar 01.01.2026 tarihinden itibaren izlenir; başlangıç daha erken olamaz.'],
            'gelecek' => ['2026-09-20', '2026-09-24', 'bitis', 'Bitiş tarihi bugünden sonra olamaz.'],
            // İlk tarama 01.01.2026 olduğundan 366 günü aşan aralık ancak 2027'de seçilebilir
            '366 günden uzun' => ['2026-01-01', '2027-01-02', 'bitis', 'Tarih aralığı en fazla 366 gün olabilir.', '2027-03-01 10:00:00'],
        ];
    }

    #[DataProvider('gecersizSenkronAraliklari')]
    public function test_gecersiz_senkron_araligi_422_doner_ve_kuyruga_girmez(string $baslangic, string $bitis, string $alan, string $mesaj, string $simdi = '2026-09-23 10:00:00'): void
    {
        $this->travelTo($simdi);
        Queue::fake([EFaturaManuelSenkron::class]);
        $this->aktifTanim();

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.senkron'))
            ->postJson('/api/v1/efatura/senkron', ['baslangic' => $baslangic, 'bitis' => $bitis])
            ->assertUnprocessable()
            ->assertJsonPath("hatalar.{$alan}.0", $mesaj);

        Queue::assertNothingPushed();
    }

    public function test_kuyruga_verilemeyen_senkron_istegi_sonraki_istegi_engellemez(): void
    {
        $this->travelTo('2026-09-23 10:00:00');
        $tanim = $this->aktifTanim();
        config(['queue.default' => 'tanimsiz-baglanti']);

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.senkron'))
            ->postJson('/api/v1/efatura/senkron', ['baslangic' => '2026-09-01', 'bitis' => '2026-09-23'])
            ->assertServerError();

        $this->assertNull(Cache::get(EFaturaDurumServisi::manuelIstekAnahtari($tanim->id)));
    }

    public function test_senkron_kapaliyken_elle_senkron_409_doner(): void
    {
        config(['entegrator.izibiz.senkron_aktif' => false]);
        Queue::fake([EFaturaManuelSenkron::class]);
        $this->aktifTanim();

        $this->actingAs($this->izinli('efatura.goruntule', 'efatura.senkron'))
            ->postJson('/api/v1/efatura/senkron', ['baslangic' => '2026-01-01', 'bitis' => '2026-01-31'])
            ->assertStatus(409)
            ->assertJsonPath('kod', 'EFATURA_SENKRON_KAPALI');

        Queue::assertNothingPushed();
    }

    public function test_senkron_isi_iki_yonu_elle_tetiklenmis_olarak_calistirir_ve_bayragi_kaldirir(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        $tanim = $this->aktifTanim();
        $kullanici = User::factory()->create();
        Cache::put(EFaturaDurumServisi::manuelIstekAnahtari($tanim->id), ['istek_id' => 'istek-1'], 600);
        $this->sahteBosIzibiz();

        (new EFaturaManuelSenkron($tanim->id, '2026-09-01', '2026-09-23', $kullanici->id, 'istek-1'))->handle(app(EFaturaSenkronServisi::class));

        $calismalar = EFaturaSenkronCalismasi::query()->orderBy('yon')->get();
        $this->assertSame(['gelen', 'giden'], $calismalar->pluck('yon')->all());
        $this->assertSame(['manuel', 'manuel'], $calismalar->pluck('tetikleyen')->all());
        $this->assertSame([$kullanici->id, $kullanici->id], $calismalar->pluck('kullanici_id')->all());
        $this->assertNull(Cache::get(EFaturaDurumServisi::manuelIstekAnahtari($tanim->id)));
    }

    public function test_senkron_isi_tanim_artik_aktif_degilse_izibize_gitmez(): void
    {
        $tanim = EntegratorBaglanti::factory()->create();
        Cache::put(EFaturaDurumServisi::manuelIstekAnahtari($tanim->id), ['istek_id' => 'istek-1'], 600);
        Http::preventStrayRequests();
        Http::fake();

        (new EFaturaManuelSenkron($tanim->id, '2026-09-01', '2026-09-23', null, 'istek-1'))->handle(app(EFaturaSenkronServisi::class));

        Http::assertNothingSent();
        $this->assertSame(0, EFaturaSenkronCalismasi::query()->count());
        $this->assertNull(Cache::get(EFaturaDurumServisi::manuelIstekAnahtari($tanim->id)));
    }

    public function test_gec_biten_eski_is_yeni_istegin_bayragini_kaldirmaz(): void
    {
        $tanim = EntegratorBaglanti::factory()->create();
        Cache::put(EFaturaDurumServisi::manuelIstekAnahtari($tanim->id), ['istek_id' => 'yeni-istek'], 600);

        (new EFaturaManuelSenkron($tanim->id, '2026-09-01', '2026-09-23', null, 'eski-istek'))->handle(app(EFaturaSenkronServisi::class));

        $this->assertSame('yeni-istek', Cache::get(EFaturaDurumServisi::manuelIstekAnahtari($tanim->id))['istek_id']);
    }

    public function test_zamanlanmis_senkron_kilidi_birakmazsa_elle_senkron_sessizce_kaybolmaz_log_yazar(): void
    {
        $this->travelTo('2026-09-23 11:24:41');
        config(['efatura.manuel_kilit_bekleme' => 1]);
        $tanim = $this->aktifTanim();
        $this->sahteBosIzibiz();
        Cache::lock("efatura-senkron:{$tanim->id}:gelen", 600)->get();
        Log::spy();
        // Zaman dondurulmuş: kilit beklemesi sahte saati ilerletmeli, yoksa bitmez
        Sleep::fake(syncWithCarbon: true);

        (new EFaturaManuelSenkron($tanim->id, '2026-09-01', '2026-09-23', null, 'istek-1'))->handle(app(EFaturaSenkronServisi::class));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $mesaj, array $baglam): bool => str_contains($mesaj, 'başka bir senkron sürüyor') && $baglam['yon'] === 'gelen')
            ->once();
        // Kilidi serbest olan yön yine çalıştı
        $this->assertSame(['giden'], EFaturaSenkronCalismasi::query()->pluck('yon')->all());
    }

    private function sahteBosIzibiz(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'apitest.izibiz.com.tr/v1/auth/token' => Http::response([
                'data' => ['accessToken' => 'erisim-token', 'validity' => '2026-09-24 02:24:41', 'customerType' => 'C'],
                'error' => null,
            ]),
            'apitest.izibiz.com.tr/v1/einvoices/*' => Http::response(['data' => [
                'contents' => [],
                'pageable' => ['totalPages' => 0, 'totalElements' => 0],
            ], 'error' => null]),
        ]);
    }
}
