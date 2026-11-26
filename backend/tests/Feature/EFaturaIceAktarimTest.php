<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;
use App\Models\EFaturaYuklemesi;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\EntegratorBaglantiServisi;
use App\Services\ErpBelgeArsivi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Statik kaynakların Excel içe aktarımı (EK-06…EK-08). Test dosyaları kodda
 * uydurma veriyle üretilir; muhasebenin gerçek dosyaları testlere girmez.
 */
final class EFaturaIceAktarimTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SIRKET_VKN = '4550357065';

    private const LOGO_BASLIKLARI = [
        'Oluşturma Tarihi', 'Fatura No', 'Fatura Tarihi', 'Gönderici VKN', 'Gönderici Adı', 'Alıcı VKN', 'Alıcı Adı',
        'Senaryo', 'Tür', 'Vergi Toplamı', 'Toplam Tutar', 'Para Birimi', 'Durum', 'Zarf yanıt kodu',
        'Zarf yanıt açıklaması', 'ETTN', 'Uygulama Yanıtı', 'Uygulama yanıt açıklaması', 'TL Toplam Tutar',
    ];

    /** @var list<string> */
    private array $dosyalar = [];

    protected function tearDown(): void
    {
        foreach ($this->dosyalar as $yol) {
            @unlink($yol);
        }

        parent::tearDown();
    }

    /** Rolün izinleri TAM OLARAK bunlar (EFaturaEkranTest kalıbı) */
    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.md5(implode(',', $izinler))]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create(['ad' => 'Ayşe Muhasebe']);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    private function logocu(): User
    {
        return $this->izinli('logo_faturalari.goruntule', 'logo_faturalari.ice_aktar', 'logo_faturalari.isle');
    }

    /**
     * @param  list<string>  $basliklar
     * @param  list<list<mixed>>  $satirlar
     */
    private function excel(array $basliklar, array $satirlar, ?string $ustBaslik = null, string $ad = 'liste.xlsx'): UploadedFile
    {
        $kitap = new Spreadsheet;
        $sayfa = $kitap->getActiveSheet();
        $ilk = 1;
        if ($ustBaslik !== null) {
            $sayfa->setCellValue('A1', $ustBaslik);
            $ilk = 2;
        }
        $sayfa->fromArray($basliklar, null, 'A'.$ilk);
        foreach ($satirlar as $i => $satir) {
            foreach ($satir as $k => $deger) {
                if ($deger !== null) {
                    $sayfa->setCellValue([$k + 1, $ilk + 1 + $i], $deger);
                }
            }
        }
        $yol = tempnam(sys_get_temp_dir(), 'efatura-test-').'.xlsx';
        $yazici = new Xlsx($kitap);
        // Formüller (dolgu, `#REF!`) okunurken hesaplanır — gerçek dosyalardaki gibi
        $yazici->setPreCalculateFormulas(false);
        $yazici->save($yol);
        $kitap->disconnectWorksheets();
        $this->dosyalar[] = $yol;

        return new UploadedFile($yol, $ad, null, null, true);
    }

    private function tarih(string $tarih): float
    {
        return (float) ExcelTarihi::PHPToExcel($tarih);
    }

    /**
     * Üç fatura (biri RED, biri dolar) + sonda yalnız formülü olan dolgu satırı.
     *
     * @param  array<int, array<int, mixed>>  $degisiklik  satır sırası => [kolon sırası => değer]
     */
    private function logoDosyasi(array $degisiklik = []): UploadedFile
    {
        $satirlar = [
            ['04.01.2026 18:49:46', 'AYD2026000000111', $this->tarih('2026-01-04'), '1160417867', 'Aydoğan Dış Ticaret AŞ', self::SIRKET_VKN, 'Haliç A.Ş.', 'TEMELFATURA', 'SATIS', 35790.09, 214740.55, 'TRY', 'Alındı', '1200', 'BAŞARI İLE İŞLENDİ', 'AAAAAAAA-1111-4111-8111-000000000001', '', '', null],
            ['05.01.2026 09:00:00', 'MST2026000000001', $this->tarih('2026-01-05'), '6200031354', 'Metro Grosmarket A.Ş.', self::SIRKET_VKN, 'Haliç A.Ş.', 'TICARIFATURA', 'SATIS', 250.04, 1500.25, 'USD', 'Alındı', '1200', 'BAŞARI İLE İŞLENDİ', 'AAAAAAAA-1111-4111-8111-000000000002', 'RED', 'yeni portala kesilmeli', null],
            ['06.01.2026 10:30:15', 'ZEP2026000000005', $this->tarih('2026-01-06'), '9971965580', 'Zeplin Tur. Ltd.', self::SIRKET_VKN, 'Haliç A.Ş.', 'TICARIFATURA', 'ISTISNA', 0, 980.4, 'EUR', 'Alındı', '1200', 'BAŞARI İLE İŞLENDİ', 'AAAAAAAA-1111-4111-8111-000000000003', '', '', null],
            // Excel'in aşağı doğru çekilmiş formül dolgusu: fatura yok, yalnız TL kolonu
            [null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, '=IF(L6="TRY",K6*1,K6*2)'],
        ];
        foreach ($degisiklik as $satir => $kolonlar) {
            foreach ($kolonlar as $kolon => $deger) {
                $satirlar[$satir][$kolon] = $deger;
            }
        }

        return $this->excel(self::LOGO_BASLIKLARI, $satirlar, ad: 'HalicAB_Logo.xlsx');
    }

    private function sirketTanimi(): void
    {
        EntegratorBaglanti::factory()->canli()->aktif()->create(['vkn' => self::SIRKET_VKN]);
    }

    public function test_logo_listesi_kontrolde_yazilmaz_kaydette_tek_parti_olarak_eklenir(): void
    {
        $this->travelTo('2026-11-25 10:00:00');
        $this->sirketTanimi();
        $kullanici = $this->logocu();

        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar/kontrol', ['dosya' => $this->logoDosyasi()])
            ->assertOk()
            ->assertJsonPath('data.hata', null)
            ->assertJsonPath('data.hatalar', [])
            ->assertJsonPath('data.ozet', ['okunan' => 3, 'yeni' => 3, 'guncellenecek' => 0, 'degismeyen' => 0, 'red' => 1]);
        $this->assertDatabaseCount('efatura_faturalari', 0);

        $yanit = $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['dosya' => $this->logoDosyasi()])
            ->assertCreated()
            ->assertJsonPath('data.dosya_adi', 'HalicAB_Logo.xlsx')
            ->assertJsonPath('data.yeni', 3)
            ->assertJsonPath('data.kullanici_adi', 'Ayşe Muhasebe');

        $logo = EntegratorBaglanti::query()->where('saglayici', 'logo')->sole();
        $faturalar = EFatura::query()->where('entegrator_baglanti_id', $logo->id)->orderBy('belge_no')->get();
        $this->assertSame(['AYD2026000000111', 'MST2026000000001', 'ZEP2026000000005'], $faturalar->pluck('belge_no')->all());
        $ilk = $faturalar[0];
        $this->assertNull($ilk->kaynak_id);
        $this->assertSame('aaaaaaaa-1111-4111-8111-000000000001', $ilk->ettn);
        $this->assertSame($ilk->ettn, $ilk->belge_anahtari);
        $this->assertSame('214740.5500', $ilk->tutar);
        $this->assertSame('35790.0900', $ilk->getAttribute('vergi_tutari'));
        // 18:49:46 İstanbul = 15:49:46 UTC (G17)
        $this->assertSame('2026-01-04 15:49:46', $ilk->getAttribute('olusturma_zamani')->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1200, $ilk->getAttribute('gib_durum_kodu'));
        $this->assertSame((int) $yanit->json('data.id'), $ilk->yukleme_id);
        $this->assertSame(['RED', 'USD', '1500.2500', 'yeni portala kesilmeli'], [$faturalar[1]->durum, $faturalar[1]->para_birimi, $faturalar[1]->tutar, $faturalar[1]->getAttribute('yanit_aciklamasi')]);
        $this->assertTrue($faturalar[1]->reddedildi());
        $this->assertDatabaseHas('efatura_islem_gecmisi', ['islem' => 'ice_aktarildi', 'saglayici' => 'logo', 'yukleme_id' => $yanit->json('data.id'), 'kullanici_adi' => 'Ayşe Muhasebe']);

        // Liste: RED fatura hızlı filtrelerde yok; statik ekranın eMOR VKN kolonu hesaplanır
        $this->actingAs($kullanici)->getJson('/api/v1/logo/gelen/faturalar?baslangic=2026-01-01&bitis=2026-01-31&sinif=islenecek')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * Yeniden yükleme: anahtar eşleşen satır güncellenir, elle verilen bilgiler
     * (harici RED, İşlendi) korunur, değişmeyen sayılır; satır silinmez.
     */
    public function test_ayni_liste_yeniden_yuklenince_degisen_guncellenir_elle_isaretler_korunur(): void
    {
        $this->sirketTanimi();
        $kullanici = $this->logocu();
        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['dosya' => $this->logoDosyasi()])->assertCreated();
        $ilk = EFatura::query()->where('belge_no', 'AYD2026000000111')->sole();
        $ilk->update(['sap_islendi' => true]);
        EFatura::query()->where('belge_no', 'ZEP2026000000005')->update(['harici_red_zamani' => now(), 'harici_red_nedeni' => 'Noter']);

        // İlk faturanın tutarı düzeltilmiş, üçüncü satır yeni listede yok
        $dosya = $this->logoDosyasi([0 => [10 => 214750.55], 2 => array_fill(0, 18, null)]);
        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar/kontrol', ['dosya' => $dosya])
            ->assertJsonPath('data.ozet', ['okunan' => 2, 'yeni' => 0, 'guncellenecek' => 1, 'degismeyen' => 1, 'red' => 1]);
        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['dosya' => $dosya])
            ->assertCreated()
            ->assertJsonPath('data.yeni', 0)
            ->assertJsonPath('data.guncellenen', 1)
            ->assertJsonPath('data.degismeyen', 1);

        $this->assertSame('214750.5500', $ilk->fresh()->tutar);
        $this->assertTrue($ilk->fresh()->sap_islendi);
        $ucuncu = EFatura::query()->where('belge_no', 'ZEP2026000000005')->sole();
        $this->assertSame('Noter', $ucuncu->harici_red_nedeni);
        $this->assertDatabaseCount('efatura_faturalari', 3);
    }

    public function test_hatali_satirlar_nedenine_gore_gruplanir_hicbir_satir_kaydedilmez_422(): void
    {
        $this->sirketTanimi();
        $kullanici = $this->logocu();
        $dosya = $this->logoDosyasi([
            0 => [15 => 'GECERSIZ-ETTN', 0 => '31.02.2026 10:00:00'],
            1 => [5 => '1111111111', 11 => 'Dolar'],
            // Üçüncü satır ikincinin ETTN'iyle: dosyada tekrar
            2 => [15 => 'AAAAAAAA-1111-4111-8111-000000000002'],
        ]);

        $onizleme = $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar/kontrol', ['dosya' => $dosya])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(
            [
                ['ettn', 'GECERSIZ-ETTN', 'ettn_gecersiz', [2]],
                ['olusturma_zamani', '31.02.2026 10:00:00', 'zaman_gecersiz', [2]],
                ['alici_vkn', '1111111111', 'alici_sirket_degil', [3]],
                ['para_birimi', 'DOLAR', 'para_birimi_gecersiz', [3]],
                ['belge_no', 'ZEP2026000000005', 'tekrar_eden', [4]],
            ],
            array_map(fn (array $h): array => [$h['alan'], $h['deger'], $h['kod'], $h['satirlar']], $onizleme['hatalar']),
        );

        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['dosya' => $dosya])
            ->assertUnprocessable()
            ->assertJsonPath('kod', 'ICE_AKTARIM_HATALI');
        $this->assertDatabaseCount('efatura_faturalari', 0);
        $this->assertDatabaseCount('efatura_yuklemeleri', 0);
    }

    /**
     * e-Arşiv listesi Logo ekranına yüklenirse ortak başlıklar (Fatura No,
     * Toplam Tutar, Para Birimi) satırı başlık yapar; Logo'nun zorunlu
     * kolonlarından eksikler gösterilir. Hiç tanınmayan dosya "başlık yok" olur.
     */
    public function test_baska_kaynagin_dosyasi_eksik_kolonla_tanimsiz_dosya_basliksiz_reddedilir(): void
    {
        $earsivDosyasi = $this->excel(
            ['Ünvanı/Adı Soyadı', 'Vergi Kimlik/T.C. Kimlik Numarası', 'Fatura No', 'Düzenleme Tarihi', 'Toplam Tutar', 'Ödenecek Tutar', 'Para Birimi'],
            [['Ahmet Kara', '20561220884', 'GIB2026000000001', $this->tarih('2026-02-03'), 1000, 1200, 'TRY']],
        );
        $tanimsiz = $this->excel(['Ad', 'Soyad', 'Telefon'], [['Ali', 'Veli', '555']]);
        $kullanici = $this->logocu();

        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar/kontrol', ['dosya' => $earsivDosyasi])
            ->assertOk()
            ->assertJsonPath('data.hata', null)
            ->assertJsonPath('data.eksik_kolonlar', ['ettn', 'belge_tarihi', 'gonderici_vkn']);
        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar/kontrol', ['dosya' => $tanimsiz])
            ->assertOk()
            ->assertJsonPath('data.hata', 'baslik_bulunamadi');
    }

    /**
     * e-Arşiv ekranı iki dosya türünü başlıklarından tanır: fatura listesi (iptal
     * satırı kaynakta RED; aynı numara başka gönderende ayrı belge) ve kolon
     * adları 2. satırda olan makbuz listesi (formüllü stopaj hesaplanmış değeriyle).
     */
    public function test_earsiv_fatura_ve_makbuz_listeleri_basliklarindan_taninir(): void
    {
        $kullanici = $this->izinli('earsiv_faturalari.goruntule', 'earsiv_faturalari.ice_aktar');
        $fatura = $this->excel(
            ['Sıra', 'Ünvanı/Adı Soyadı', 'Vergi Kimlik/T.C. Kimlik Numarası', 'Fatura No', 'Düzenleme Tarihi', 'Toplam Tutar', 'Ödenecek Tutar', 'Vergiler Toplamı', 'Para Birimi', 'İptal İtiraz Durum', 'İptal İtiraz Tarihi', 'TEVKİFAT KDV Tutarı'],
            [
                [1, 'Bahtiyar Usta', '10184522250', 'GIB2026000000001', $this->tarih('2026-01-19'), 77658, 93189.6, 15531.6, 'TRY', 'İptal Edilmiştir', '21/01/2026', '=IF(#REF!="TRY",1,2)'],
                [null, 'DDS Tasarım Ltd.', '2690058947', 'GIB2026000000001', $this->tarih('2026-01-20'), 1000.1, 1200.12, 200.02, 'TRY', null, null, null],
            ],
        );
        $makbuz = $this->excel(
            ['Sıra', 'Düzenleyen T.C. / Vergi Kimlik Numarası', 'Düzenleyen Unvanı/Adı Soyadı', 'Makbuz No', 'Makbuz Tarihi', 'Brüt Ücret Tutarı', 'G.V. Stopaj Tutarı', 'KDV Tutarı', 'Tahsilat Tutarı ', 'Para Birimi', 'Gönderim Şekli'],
            [[null, '28948489664', 'Serbest Meslek Sahibi   ', 'MSK2026000000390', '20/10/2026', 45000.5, '=F3*0.2', 9000.1, 45000.5, 'TRY', 'KAGIT   ']],
            ustBaslik: 'E-Serbest Meslek Makbuzlarım',
        );

        $this->actingAs($kullanici)->post('/api/v1/earsiv/ice-aktar', ['dosya' => $fatura])
            ->assertCreated()->assertJsonPath('data.belge_turu', 'fatura')->assertJsonPath('data.yeni', 2);
        $this->actingAs($kullanici)->post('/api/v1/earsiv/ice-aktar', ['dosya' => $makbuz])
            ->assertCreated()->assertJsonPath('data.belge_turu', 'makbuz')->assertJsonPath('data.yeni', 1);

        $iptal = EFatura::query()->where('gonderici_vkn', '10184522250')->sole();
        $this->assertSame(['fatura|10184522250|GIB2026000000001', 'fatura', null, 'IPTAL', 'İptal Edilmiştir', '21.01.2026'], [
            $iptal->belge_anahtari, $iptal->belge_turu, $iptal->ettn, $iptal->durum, $iptal->getAttribute('durum_aciklamasi'), $iptal->getAttribute('yanit_aciklamasi'),
        ]);
        $this->assertSame(['93189.6000', '77658.0000'], [$iptal->tutar, $iptal->getAttribute('brut_tutar')]);
        $makbuzKaydi = EFatura::query()->where('belge_turu', 'makbuz')->sole();
        $this->assertSame(['makbuz|28948489664|MSK2026000000390', '2026-10-20', 'Serbest Meslek Sahibi', '45000.5000', '9000.1000', '9000.1000'], [
            $makbuzKaydi->belge_anahtari, $makbuzKaydi->belge_tarihi->toDateString(), $makbuzKaydi->getAttribute('gonderici_unvan'),
            $makbuzKaydi->getAttribute('brut_tutar'), $makbuzKaydi->getAttribute('stopaj_tutari'), $makbuzKaydi->getAttribute('vergi_tutari'),
        ]);
        $this->assertNull($makbuzKaydi->durum);
    }

    public function test_parti_geri_alininca_ekledigi_faturalar_silinir_elle_islenmis_varsa_409(): void
    {
        $this->sirketTanimi();
        $kullanici = $this->logocu();
        $parti = $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['dosya' => $this->logoDosyasi()])->json('data.id');
        $isaretli = EFatura::query()->where('belge_no', 'AYD2026000000111')->sole();
        $isaretli->update(['sap_islendi' => true]);

        $this->actingAs($kullanici)->deleteJson("/api/v1/logo/yuklemeler/{$parti}")
            ->assertConflict()
            ->assertJsonPath('kod', 'YUKLEME_ELLE_ISLENMIS');
        $this->assertDatabaseCount('efatura_faturalari', 3);

        $isaretli->update(['sap_islendi' => null]);
        $this->actingAs($kullanici)->deleteJson("/api/v1/logo/yuklemeler/{$parti}")->assertNoContent();

        $this->assertDatabaseCount('efatura_faturalari', 0);
        $this->assertDatabaseCount('efatura_yuklemeleri', 0);
        $this->assertDatabaseHas('efatura_islem_gecmisi', ['islem' => 'ice_aktarim_geri_alindi', 'saglayici' => 'logo', 'yukleme_id' => null]);
        $this->actingAs($kullanici)->getJson('/api/v1/logo/yuklemeler')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_ice_aktarim_izni_ve_suren_aktarim_kilidi(): void
    {
        $this->sirketTanimi();
        $this->actingAs($this->izinli('logo_faturalari.goruntule'))
            ->post('/api/v1/logo/ice-aktar', ['dosya' => $this->logoDosyasi()])
            ->assertForbidden();

        $logo = app(EntegratorBaglantiServisi::class)->statikTanim('logo');
        $kilit = Cache::lock('efatura-ice-aktarim:'.$logo->id, 60);
        $this->assertTrue($kilit->get());

        try {
            $this->actingAs($this->logocu())->post('/api/v1/logo/ice-aktar', ['dosya' => $this->logoDosyasi()])
                ->assertConflict()
                ->assertJsonPath('kod', 'ICE_AKTARIM_SURUYOR');
        } finally {
            $kilit->release();
        }
        $this->assertSame(0, EFaturaYuklemesi::query()->count());
    }

    /**
     * Excel'den kopyala-yapıştır (Kesinti'deki gibi): ekran tablosu başlıksız,
     * kolon ucunun verdiği standart sırayla gönderir; Türkçe biçimli sayı ve
     * tarih okunur, satır numarası tablonunkiyle aynıdır (aradaki boş satır
     * sayılır). Başlıkla yapıştırılan metin (Excel'in bütün kolonları) adlardan
     * tanınır. Kontrol ve kayıt dosyayla aynı kurallardan geçer.
     */
    /**
     * Rapor verileri (2026-11-26): Logo dosyasının oran bazında KDV / matrah,
     * tevkifat (ilk kodu), ek vergi, tutar ve kur kolonları UBL'li kaynaklarla
     * aynı tablolara yazılır; yeniden yükleme kalemleri çoğaltmaz, bu kolonları
     * taşımayan eski biçimli liste mevcut kalemleri silmez.
     */
    public function test_logo_kdv_tevkifat_ve_kur_kolonlari_vergi_kalemlerine_yazilir_yeniden_yuklemede_cogalmaz(): void
    {
        $this->sirketTanimi();
        $kullanici = $this->logocu();
        $basliklar = ['ETTN', 'Fatura No', 'Fatura Tarihi', 'Gönderici VKN', 'Alıcı VKN', 'Toplam Tutar', 'Para Birimi',
            'Vergi Hariç Tutar', 'İskonto Toplamı', 'Vergi Dahil Tutar', 'Kur', 'KDV %0', 'KDV %0 Matrah', 'KDV %10', 'KDV %10 Matrah',
            'KDV %20', 'KDV %20 Matrah', 'KDV Diğer', 'Tevkifat Toplamı', 'Ek Vergiler', 'İlk Tevkifat Kodu'];
        $satir = ['AAAAAAAA-1111-4111-8111-000000000021', 'LGO2026000000021', '05.01.2026', '1160417867', self::SIRKET_VKN, '1.180,00', 'USD',
            '1.100,00', '50,00', '1.250,00', '43,9846', '0,00', '100,00', '50,00', '500,00', '100,00', '500,00', '0,00', '70,00', '0,00', '624'];
        $metin = implode("\t", $basliklar)."\n".implode("\t", $satir);
        $kalemler = fn (): array => EFaturaVergiKalemi::query()->orderBy('sira')->get()
            ->map(fn (EFaturaVergiKalemi $k): array => [$k->tur, $k->vergi_kodu, $k->oran, $k->matrah, $k->tutar])->all();
        $beklenen = [
            ['vergi', '0015', '0.0000', '100.0000', '0.0000'],
            ['vergi', '0015', '10.0000', '500.0000', '50.0000'],
            ['vergi', '0015', '20.0000', '500.0000', '100.0000'],
            ['tevkifat', '624', null, null, '70.0000'],
        ];

        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['metin' => $metin])->assertCreated();
        $fatura = EFatura::query()->where('belge_no', 'LGO2026000000021')->sole();
        $this->assertSame(['1100.0000', '50.0000', '1250.0000', '43.984600'], [$fatura->vergi_haric_tutar, $fatura->indirim_tutari, $fatura->vergi_dahil_tutar, $fatura->kur]);
        $this->assertSame($beklenen, $kalemler());

        // Aynı dosya yeniden: kalemler baştan yazılır, çoğalmaz
        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['metin' => $metin])->assertCreated();
        $this->assertSame($beklenen, $kalemler());

        // KDV kolonları olmayan eski biçimli liste kalemleri silmez
        $eski = implode("\t", array_slice($basliklar, 0, 7))."\n".implode("\t", array_slice($satir, 0, 7));
        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['metin' => $eski])->assertCreated();
        $this->assertSame($beklenen, $kalemler());
    }

    public function test_logo_listesi_excelden_yapistirilinca_ayni_kontrol_ve_kayit_akisindan_gecer(): void
    {
        $this->sirketTanimi();
        $kullanici = $this->logocu();

        $kolonlar = $this->actingAs($kullanici)->getJson('/api/v1/logo/ice-aktar/kolonlar')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.belge_turu', null)
            ->assertJsonPath('data.0.kolonlar.0', ['alan' => 'ettn', 'basliklar' => ['ETTN']])
            ->json('data.0.kolonlar');
        $siralama = array_column($kolonlar, 'alan');
        $satir = fn (array $degerler): string => implode("\t", array_map(fn (string $alan): string => $degerler[$alan] ?? '', $siralama));
        $gecerli = [
            'ettn' => 'AAAAAAAA-1111-4111-8111-000000000001', 'belge_no' => 'AYD2026000000111', 'belge_tarihi' => '04.01.2026',
            'olusturma_zamani' => '04.01.2026 18:49:46', 'gonderici_vkn' => '1160417867', 'gonderici_unvan' => 'Aydoğan Dış Ticaret AŞ',
            'alici_vkn' => self::SIRKET_VKN, 'senaryo' => 'TEMELFATURA', 'fatura_tipi' => 'SATIS',
            'tutar' => '214.740,55', 'vergi_tutari' => '35.790,09', 'para_birimi' => 'TRY', 'durum' => 'Alındı',
        ];
        $hatali = [...$gecerli, 'ettn' => 'AAAAAAAA-1111-4111-8111-000000000002', 'belge_no' => 'MST2026000000001', 'gonderici_vkn' => '12AB'];
        $metin = $satir($gecerli)."\n\n".$satir($hatali);

        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar/kontrol', ['metin' => $metin])
            ->assertOk()
            ->assertJsonPath('data.hatalar', [['alan' => 'gonderici_vkn', 'deger' => '12AB', 'kod' => 'vkn_gecersiz', 'adet' => 1, 'satirlar' => [3]]]);

        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar', ['metin' => $satir($gecerli)])
            ->assertCreated()
            ->assertJsonPath('data.dosya_adi', 'Kopyala-yapıştır')
            ->assertJsonPath('data.yeni', 1);
        $kayit = EFatura::query()->where('belge_no', 'AYD2026000000111')->sole();
        $this->assertSame(
            ['aaaaaaaa-1111-4111-8111-000000000001', '214740.5500', '35790.0900', '2026-01-04', '2026-01-04 15:49:46'],
            [$kayit->belge_anahtari, $kayit->tutar, $kayit->getAttribute('vergi_tutari'), $kayit->belge_tarihi->toDateString(), $kayit->olusturma_zamani?->utc()->toDateTimeString()],
        );

        // Excel'in başlık satırıyla (fazla kolonlar dahil, başka sırada) kopyalanan metin
        $baslikli = implode("\t", self::LOGO_BASLIKLARI)."\n".implode("\t", [
            '05.01.2026 09:00:00', 'ZEP2026000000005', '05.01.2026', '9971965580', 'Zeplin Tur. Ltd.', self::SIRKET_VKN, 'Haliç A.Ş.',
            'TICARIFATURA', 'ISTISNA', '0', '980,40', 'EUR', 'Alındı', '1200', 'BAŞARI İLE İŞLENDİ', 'AAAAAAAA-1111-4111-8111-000000000003', '', '', '',
        ]);
        $this->actingAs($kullanici)->post('/api/v1/logo/ice-aktar/kontrol', ['metin' => $baslikli])
            ->assertOk()
            ->assertJsonPath('data.hatalar', [])
            ->assertJsonPath('data.ozet.okunan', 1)
            ->assertJsonPath('data.ozet.yeni', 1);
    }

    /**
     * e-Arşiv tanımında iki tür var: yapıştırma tablonun türüyle gelir (e-SMM
     * makbuz kolonları). Tür yoksa, dosyayla birlikte gelirse ya da Logo'ya
     * verilirse 422; dosya ile metin birlikte gelemez.
     */
    public function test_earsiv_yapistirmasi_secilen_belge_turunun_kolonlariyla_okunur_girdi_dogrulanir(): void
    {
        $kullanici = $this->izinli('earsiv_faturalari.goruntule', 'earsiv_faturalari.ice_aktar');
        $this->actingAs($kullanici)->getJson('/api/v1/earsiv/ice-aktar/kolonlar')
            ->assertOk()
            ->assertJsonPath('data.0.belge_turu', 'fatura')
            ->assertJsonPath('data.1.belge_turu', 'makbuz')
            ->assertJsonPath('data.1.kolonlar.2', ['alan' => 'belge_no', 'basliklar' => ['Makbuz No']]);
        $makbuz = implode("\t", ['28948489664', 'Serbest Meslek Sahibi', 'MSK2026000000390', '20.10.2026', '45.000,50', '9.000,10', '9.000,10', '45.000,50', 'TRY']);

        $this->actingAs($kullanici)->post('/api/v1/earsiv/ice-aktar/kontrol', ['metin' => $makbuz, 'belge_turu' => 'makbuz'])
            ->assertOk()
            ->assertJsonPath('data.belge_turu', 'makbuz')
            ->assertJsonPath('data.hatalar', [])
            ->assertJsonPath('data.ozet.okunan', 1);

        $this->actingAs($kullanici)->postJson('/api/v1/earsiv/ice-aktar/kontrol', ['metin' => $makbuz])
            ->assertUnprocessable()->assertJsonValidationErrors('belge_turu', 'hatalar');
        $this->actingAs($kullanici)->postJson('/api/v1/earsiv/ice-aktar/kontrol', ['metin' => $makbuz, 'belge_turu' => 'irsaliye'])
            ->assertUnprocessable()->assertJsonValidationErrors('belge_turu', 'hatalar');
        $this->actingAs($kullanici)->post('/api/v1/earsiv/ice-aktar/kontrol', ['metin' => $makbuz, 'dosya' => $this->logoDosyasi()], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('dosya', 'hatalar');
        $this->actingAs($this->logocu())->postJson('/api/v1/logo/ice-aktar/kontrol', ['metin' => $makbuz, 'belge_turu' => 'makbuz'])
            ->assertUnprocessable()->assertJsonValidationErrors('belge_turu', 'hatalar');
        $this->assertSame(0, EFatura::query()->count());
    }

    /**
     * Statik kaynağın belgesi elimizde yok (kullanıcı kararı 2026-11-26): Logo
     * ekranında PDF ucu yok; Tüm Faturalar'ın PDF ucu açık hata verir, ERP
     * arşivine ve dışarıya istek gitmez.
     */
    public function test_statik_faturanin_pdfi_yok_logo_ucu_404_tum_faturalar_acik_hata(): void
    {
        $this->sirketTanimi();
        $this->actingAs($this->logocu())->post('/api/v1/logo/ice-aktar', ['dosya' => $this->logoDosyasi()])->assertCreated();
        $fatura = EFatura::query()->where('belge_no', 'AYD2026000000111')->sole();
        $this->mock(ErpBelgeArsivi::class)->shouldNotReceive('pdf');
        Http::preventStrayRequests();
        $yonetici = User::factory()->create(['sistem_yoneticisi' => true]);

        $this->actingAs($yonetici)->get("/api/v1/logo/faturalar/{$fatura->id}/pdf")->assertNotFound();
        $this->actingAs($yonetici)
            ->get("/api/v1/tum-faturalar/faturalar/{$fatura->id}/pdf")
            ->assertNotFound()
            ->assertJsonPath('kod', 'FATURA_BELGESI_YOK');
    }
}
