<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EFaturaVergiKalemi;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Raporlar → Gelen Fatura Raporu (2026-11-26): bütün gelen faturalar, Logo
 * Excel'inin kolonlarıyla; satırlar fatura + vergi kalemlerinden (ERP okunmaz).
 */
final class GelenFaturaRaporuTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ADRES = '/api/v1/raporlar/gelen-fatura-raporu';

    private const PARAMETRELER = ['ilk_tarih' => '2026-11-01', 'son_tarih' => '2026-11-30'];

    /**
     * Denetleyici süre sınırını set_time_limit ile koyar; CLI'da bu bütün test
     * sürecine uygulanır ve sonraki testler 55 sn'de "Maximum execution time"
     * ile ölürdü. CLI varsayılanına (sınırsız) döner.
     */
    protected function tearDown(): void
    {
        set_time_limit(0);

        parent::tearDown();
    }

    private function raporcu(string $izin = 'gelen_fatura_raporu.goruntule'): User
    {
        $kullanici = User::factory()->create(['erp_kullanici_id' => null]);
        $rol = Rol::query()->create(['ad' => 'Raporcu '.$izin]);
        DB::table('rol_izinleri')->insert(['rol_id' => $rol->id, 'izin' => $izin]);
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2: string|null, 3: string|null, 4: string, 5?: string|null, 6?: string|null}>  $kalemler  [tur, kod, oran, matrah, tutar, istisna kodu, ad]
     */
    private function kalemler(EFatura $fatura, array $kalemler): void
    {
        EFaturaVergiKalemi::query()->insert(array_map(fn (array $k, int $i): array => [
            'efatura_id' => $fatura->id, 'sira' => $i + 1, 'tur' => $k[0], 'vergi_kodu' => $k[1], 'oran' => $k[2],
            'matrah' => $k[3], 'tutar' => $k[4], 'istisna_kodu' => $k[5] ?? null, 'istisna_adi' => null, 'vergi_adi' => $k[6] ?? null,
        ], $kalemler, array_keys($kalemler)));
    }

    /**
     * @return array<string, array<string, mixed>> fatura no => satır
     */
    private function satirlar(): array
    {
        $satirlar = $this->actingAs($this->raporcu())->getJson(self::ADRES.'?'.http_build_query(self::PARAMETRELER))->assertOk()->json('data');

        return array_column($satirlar, null, 'fatura_no');
    }

    public function test_fatura_ve_vergi_kalemlerinden_logo_kolonlari_hesaplanir(): void
    {
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        $protel = EntegratorBaglanti::factory()->protel()->aktif()->create();

        // Tevkifatlı, iki oranlı, indirimli TRY faturası (UBL'den)
        $tevkifatli = EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'IZB1', 'belge_tarihi' => '2026-11-20', 'senaryo' => 'TICARIFATURA',
            'gonderici_unvan' => 'Güvenlik A.Ş.', 'gonderici_vkn' => '1112223333', 'satici_vergi_dairesi' => 'Beyoğlu', 'para_birimi' => 'TRY',
            'tutar' => '1150.00', 'vergi_tutari' => '30.00', 'satir_toplami' => '1050.00', 'indirim_tutari' => '50.00', 'vergi_haric_tutar' => '1000.00',
        ]);
        $this->kalemler($tevkifatli, [
            ['vergi', '0015', '10.0000', '400.00', '40.00'],
            ['vergi', '0015', '20.0000', '600.00', '120.00'],
            // KDV dışı vergi (ÖİV): fatura toplamına girer, KDV'ye ve KDV matrahına girmez
            ['vergi', '4080', '1.0000', '1000.00', '10.00'],
            ['tevkifat', '607', '90.0000', '120.00', '108.00', null, 'ÖZEL GÜVENLİK'],
        ]);
        // Dövizli, kuru olan istisna faturası
        $dovizli = EFatura::factory()->protel()->create([
            'entegrator_baglanti_id' => $protel->id, 'belge_no' => 'PRT1', 'belge_tarihi' => '2026-11-10', 'para_birimi' => 'EUR',
            'tutar' => '200.00', 'vergi_haric_tutar' => '200.00', 'kur' => '50.123456',
        ]);
        $this->kalemler($dovizli, [['vergi', '0015', '0.0000', '200.00', '0.00', '318']]);
        // Dövizli ama kuru yok: TL boş
        EFatura::factory()->protel()->create(['entegrator_baglanti_id' => $protel->id, 'belge_no' => 'PRT2', 'belge_tarihi' => '2026-11-11', 'para_birimi' => 'USD', 'tutar' => '10.00', 'vergi_haric_tutar' => '10.00']);
        // UBL'i henüz okunmamış: listedeki tutar ve vergiden, oran kolonları boş
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'IZB2', 'belge_tarihi' => '2026-11-05', 'para_birimi' => 'TRY', 'tutar' => '118.00', 'vergi_tutari' => '18.00']);
        // Rapora girmez: gizlenmiş, aralık dışı, giden
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'GIZLI', 'belge_tarihi' => '2026-11-05', 'gizlenme_zamani' => now()]);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'EKIM', 'belge_tarihi' => '2026-10-31']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'GIDEN', 'belge_tarihi' => '2026-11-05', 'yon' => 'giden']);
        // Son gün dahil (T6), RED fatura girer ve işaretlidir
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'RED1', 'belge_tarihi' => '2026-11-30', 'harici_red_zamani' => now()]);

        $satirlar = $this->satirlar();

        // En yeni önce
        $this->assertSame(['RED1', 'IZB1', 'PRT2', 'PRT1', 'IZB2'], array_keys($satirlar));
        $this->assertSame('evet', $satirlar['RED1']['red']);

        $this->assertEquals([
            'fatura_tarihi' => '2026-11-20', 'fatura_profili' => 'TICARIFATURA', 'entegrator' => 'İzibiz', 'firma_unvani' => 'Güvenlik A.Ş.',
            'vkn' => '1112223333', 'vergi_dairesi' => 'Beyoğlu', 'para_kodu' => 'TRY',
            'orijinal_tutar' => 1050.0, 'orijinal_indirim' => 50.0, 'net_tutar' => 1000.0, 'orijinal_kdv' => 160.0,
            'fatura_toplami' => 1170.0, 'orijinal_tevkifat' => 108.0, 'net_kdv' => 52.0, 'kur' => '1',
            'tutar_tl' => 1050.0, 'kdv_matrahi_tl' => 1000.0, 'kdv_tl' => 160.0, 'fatura_toplami_tl' => 1170.0, 'tevkifat_tl' => 108.0, 'net_kdv_tl' => 52.0,
            'oran_0' => '', 'kdv_0' => 0.0, 'oran_1' => '', 'kdv_1' => 0.0, 'oran_10' => '%10', 'kdv_10' => 40.0, 'matrah_10' => 400.0, 'oran_20' => '%20', 'kdv_20' => 120.0, 'matrah_20' => 600.0,
            'matrah_toplami' => 1000.0, 'tevkifat_kodu' => '607', 'tevkifat_aciklama' => 'ÖZEL GÜVENLİK', 'red' => 'hayir',
        ], array_intersect_key($satirlar['IZB1'], array_flip([
            'fatura_tarihi', 'fatura_profili', 'entegrator', 'firma_unvani', 'vkn', 'vergi_dairesi', 'para_kodu', 'orijinal_tutar', 'orijinal_indirim',
            'net_tutar', 'orijinal_kdv', 'fatura_toplami', 'orijinal_tevkifat', 'net_kdv', 'kur', 'tutar_tl', 'kdv_matrahi_tl', 'kdv_tl',
            'fatura_toplami_tl', 'tevkifat_tl', 'net_kdv_tl', 'oran_0', 'kdv_0', 'oran_1', 'kdv_1', 'oran_10', 'kdv_10', 'matrah_10', 'oran_20', 'kdv_20', 'matrah_20',
            'matrah_toplami', 'tevkifat_kodu', 'tevkifat_aciklama', 'red',
        ])));

        // Dövizli: TL = orijinal × kur; sıfır oranlı istisna kalemi
        $this->assertEquals(['50,123456', 10024.69, '%0', 200.0, '318'], [
            $satirlar['PRT1']['kur'], $satirlar['PRT1']['tutar_tl'], $satirlar['PRT1']['oran_0'], $satirlar['PRT1']['matrah_0'], $satirlar['PRT1']['istisna_kodu'],
        ]);
        $this->assertSame(['', null, null], [$satirlar['PRT2']['kur'], $satirlar['PRT2']['tutar_tl'], $satirlar['PRT2']['fatura_toplami_tl']]);
        // Kalemsiz: net = tutar − vergi, toplam = tutar, oran kolonları boş
        $this->assertEquals([100.0, 18.0, 118.0, null, ''], [
            $satirlar['IZB2']['net_tutar'], $satirlar['IZB2']['orijinal_kdv'], $satirlar['IZB2']['fatura_toplami'], $satirlar['IZB2']['kdv_20'], $satirlar['IZB2']['oran_20'],
        ]);
    }

    /**
     * Fatura Toplamı "üçünden ikisi" (canlı veri 2026-11-27: her faturada üç
     * değerden en az ikisi uyuşuyor): B = net + KDV + stopaj dışı vergiler,
     * C = ödenecek + tevkifat + stopaj, D = satıcının vergi dahil alanı.
     */
    public function test_fatura_toplami_stopaji_eklemez_ve_uyusan_iki_degerden_secilir(): void
    {
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = fn (string $no, array $alanlar) => EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => $no, 'belge_tarihi' => '2026-11-20', 'para_birimi' => 'TRY',
            'vergi_haric_tutar' => '1000.00', ...$alanlar,
        ]);

        // GV (0003) ve KV (0011) stopajı ödenecekten düşülür, toplama eklenmez: B = C = D
        $stopajli = $fatura('STP', ['tutar' => '1000.00', 'vergi_dahil_tutar' => '1200.00']);
        $this->kalemler($stopajli, [
            ['vergi', '0015', '20.0000', '1000.00', '200.00'],
            ['vergi', '0003', '15.0000', '1000.00', '150.00'],
            ['vergi', '0011', '5.0000', '1000.00', '50.00'],
        ]);
        // Matraha dahil vergi (8005 elektrik tüketim): B ikinci kez ekler, C = D → D
        $matrahaDahil = $fatura('ETV', ['tutar' => '1200.00', 'vergi_dahil_tutar' => '1200.00']);
        $this->kalemler($matrahaDahil, [
            ['vergi', '0015', '20.0000', '1000.00', '200.00'],
            ['vergi', '8005', '5.0000', '952.38', '47.62'],
        ]);
        // Satıcının vergi dahil alanı yanlış, B = C (0,05 içinde) → B
        $saticiYanlis = $fatura('YNL', ['tutar' => '1199.97', 'vergi_dahil_tutar' => '1000.00']);
        $this->kalemler($saticiYanlis, [['vergi', '0015', '20.0000', '1000.00', '200.00']]);
        // Hiçbiri uyuşmuyor (satıcı alanı yok) → B
        $uyusmayan = $fatura('UYS', ['tutar' => '1100.00', 'vergi_dahil_tutar' => null]);
        $this->kalemler($uyusmayan, [['vergi', '0015', '20.0000', '1000.00', '200.00']]);

        $satirlar = $this->satirlar();

        $this->assertEquals(
            ['STP' => 1200.0, 'ETV' => 1200.0, 'YNL' => 1200.0, 'UYS' => 1200.0],
            array_map(fn (array $s): float => $s['fatura_toplami'], array_intersect_key($satirlar, array_flip(['STP', 'ETV', 'YNL', 'UYS']))),
        );
        $this->assertEquals([1200.0, 1000.0, 200.0], [$satirlar['STP']['fatura_toplami_tl'], $satirlar['STP']['net_tutar'], $satirlar['STP']['orijinal_kdv']]);
    }

    public function test_makbuzda_net_brut_tutardan_kalemli_vergi_haric_bos_faturada_odenecekten_geri_hesaplanir(): void
    {
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        $earsiv = app(EntegratorBaglantiServisi::class)->statikTanim('earsiv');
        // e-SMM: tahsilat = brüt + KDV − GV stopajı; kalem yok
        EFatura::factory()->create([
            'entegrator_baglanti_id' => $earsiv->id, 'kaynak_id' => null, 'ettn' => null, 'belge_anahtari' => 'makbuz-1', 'belge_turu' => 'makbuz',
            'belge_no' => 'MKB', 'belge_tarihi' => '2026-11-15', 'para_birimi' => 'TRY',
            'tutar' => '50000.00', 'vergi_tutari' => '10000.00', 'brut_tutar' => '50000.00', 'stopaj_tutari' => '10000.00',
        ]);
        // Kalemli (KDV + tevkifat) ama listede vergi hariç tutar yok: ödenecek tevkifat düşülmüş
        $kalemli = EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'KLM', 'belge_tarihi' => '2026-11-16', 'para_birimi' => 'TRY',
            'tutar' => '1100.00', 'vergi_tutari' => '200.00', 'vergi_haric_tutar' => null,
        ]);
        $this->kalemler($kalemli, [
            ['vergi', '0015', '20.0000', '1000.00', '200.00'],
            ['tevkifat', '601', '50.0000', '200.00', '100.00'],
        ]);
        // Avanslı: vergi dahil 1.200, 300 avans düşülmüş ödenecek 900. Ödenecekten
        // türeyen iki aday tek kanıttır; satıcının toplamı esas (Codex D-2-R1)
        $avansli = EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'AVN', 'belge_tarihi' => '2026-11-17', 'para_birimi' => 'TRY',
            'tutar' => '900.00', 'vergi_tutari' => '200.00', 'vergi_haric_tutar' => null, 'vergi_dahil_tutar' => '1200.00',
        ]);
        $this->kalemler($avansli, [['vergi', '0015', '20.0000', '1000.00', '200.00']]);
        // Codex D-2-R2: aynı avanslı fatura, KDV matrahının İÇİNDE 47,62 ÖTV ile
        // (toplam − KDV = KDV matrahı): ÖTV netten ayrıca düşülmez
        $matrahaDahil = EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'AVO', 'belge_tarihi' => '2026-11-17', 'para_birimi' => 'TRY',
            'tutar' => '900.00', 'vergi_tutari' => '247.62', 'vergi_haric_tutar' => null, 'vergi_dahil_tutar' => '1200.00',
        ]);
        $this->kalemler($matrahaDahil, [['vergi', '0015', '20.0000', '1000.00', '200.00'], ['vergi', '9077', '5.0000', '952.38', '47.62']]);
        // Matrahın DIŞINDA 50,00 konaklama vergisi: netten düşülür
        $matrahDisi = EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'AVK', 'belge_tarihi' => '2026-11-17', 'para_birimi' => 'TRY',
            'tutar' => '950.00', 'vergi_tutari' => '250.00', 'vergi_haric_tutar' => null, 'vergi_dahil_tutar' => '1250.00',
        ]);
        $this->kalemler($matrahDisi, [['vergi', '0015', '20.0000', '1000.00', '200.00'], ['vergi', '0059', '5.0000', '1000.00', '50.00']]);
        // Codex D-2-R3: ikisi aynı faturada — 47,62 ÖTV matrahın içinde, 50,00
        // konaklama vergisi dışında. Yalnız matrah dışı tutar düşülür
        $ikisi = EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'AVI', 'belge_tarihi' => '2026-11-17', 'para_birimi' => 'TRY',
            'tutar' => '950.00', 'vergi_tutari' => '297.62', 'vergi_haric_tutar' => null, 'vergi_dahil_tutar' => '1250.00',
        ]);
        $this->kalemler($ikisi, [
            ['vergi', '0015', '20.0000', '1000.00', '200.00'],
            ['vergi', '9077', '5.0000', '952.38', '47.62'],
            ['vergi', '0059', '5.0000', '1000.00', '50.00'],
        ]);
        // Ayrım doğrulanamıyor (toplam − KDV, KDV matrahının altında): açık kural, diğer vergilerin hepsi düşülür
        $tutarsiz = EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'AVT', 'belge_tarihi' => '2026-11-17', 'para_birimi' => 'TRY',
            'tutar' => '900.00', 'vergi_tutari' => '250.00', 'vergi_haric_tutar' => null, 'vergi_dahil_tutar' => '1150.00',
        ]);
        $this->kalemler($tutarsiz, [['vergi', '0015', '20.0000', '1000.00', '200.00'], ['vergi', '0059', '5.0000', '1000.00', '50.00']]);

        $satirlar = $this->satirlar();
        $alanlar = array_flip(['net_tutar', 'fatura_toplami', 'fatura_toplami_tl']);
        $this->assertEquals(['net_tutar' => 1000.0, 'fatura_toplami' => 1200.0, 'fatura_toplami_tl' => 1200.0], array_intersect_key($satirlar['AVN'], $alanlar));
        $this->assertEquals(['net_tutar' => 1000.0, 'fatura_toplami' => 1200.0, 'fatura_toplami_tl' => 1200.0], array_intersect_key($satirlar['AVO'], $alanlar));
        $this->assertEquals(['net_tutar' => 1000.0, 'fatura_toplami' => 1250.0, 'fatura_toplami_tl' => 1250.0], array_intersect_key($satirlar['AVK'], $alanlar));
        $this->assertEquals(['net_tutar' => 1000.0, 'fatura_toplami' => 1250.0, 'fatura_toplami_tl' => 1250.0], array_intersect_key($satirlar['AVI'], $alanlar));
        $this->assertEquals(['net_tutar' => 900.0, 'fatura_toplami' => 1150.0, 'fatura_toplami_tl' => 1150.0], array_intersect_key($satirlar['AVT'], $alanlar));
        // satirlar() raporcu olarak oturum açtı
        $excel = $this->post(self::ADRES.'/excel', [...self::PARAMETRELER, 'anahtarlar' => [(string) $avansli->id]])
            ->assertOk();
        $sayfa = IOFactory::load($excel->baseResponse->getFile()->getPathname())->getSheet(0);
        $baslik = $sayfa->rangeToArray('A1:AQ1')[0];
        $this->assertSame('AVN', $sayfa->getCell('C2')->getValue());
        $this->assertEquals(1200, $sayfa->getCell([array_search('Fatura Toplamı ORJ', $baslik, true) + 1, 2])->getValue());

        $this->assertEquals(
            ['net_tutar' => 50000.0, 'orijinal_kdv' => 10000.0, 'fatura_toplami' => 60000.0, 'kdv_matrahi_tl' => 50000.0, 'matrah_toplami' => 50000.0],
            array_intersect_key($satirlar['MKB'], array_flip(['net_tutar', 'orijinal_kdv', 'fatura_toplami', 'matrah_toplami', 'kdv_matrahi_tl'])),
        );
        $this->assertEquals(
            ['net_tutar' => 1000.0, 'fatura_toplami' => 1200.0, 'matrah_toplami' => 1000.0],
            array_intersect_key($satirlar['KLM'], array_flip(['net_tutar', 'fatura_toplami', 'matrah_toplami'])),
        );
    }

    public function test_cok_kalemli_kurusu_toplanir_logo_18_orani_oran_kolonlarina_girmez(): void
    {
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        $logo = app(EntegratorBaglantiServisi::class)->statikTanim('logo');
        $cokKalemli = EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'CKL', 'belge_tarihi' => '2026-11-20', 'para_birimi' => 'TRY',
            'tutar' => '101.94', 'satir_toplami' => '100.00', 'indirim_tutari' => '0.05', 'vergi_haric_tutar' => '99.95',
        ]);
        $this->kalemler($cokKalemli, [
            ['vergi', '0015', '20.0000', '33.33', '6.67'],
            ['vergi', '0015', '20.0000', '66.62', '13.32'],
            ['tevkifat', '601', '50.0000', '13.32', '12.34', null, 'YAPIM'],
            ['tevkifat', '624', '30.0000', '6.67', '5.66', null, 'TAŞIMA'],
        ]);
        $logoFaturasi = EFatura::factory()->create([
            'entegrator_baglanti_id' => $logo->id, 'kaynak_id' => null, 'ettn' => null, 'belge_anahtari' => 'logo-1',
            'belge_no' => 'LGO', 'belge_tarihi' => '2026-11-19', 'para_birimi' => 'TRY', 'tutar' => '118.00', 'vergi_haric_tutar' => '100.00',
        ]);
        $this->kalemler($logoFaturasi, [['vergi', '0015', '18.0000', '100.00', '18.00']]);

        $satirlar = $this->satirlar();

        $this->assertEquals([
            'orijinal_tutar' => 100.0, 'orijinal_indirim' => 0.05, 'net_tutar' => 99.95, 'orijinal_kdv' => 19.99, 'fatura_toplami' => 119.94,
            'orijinal_tevkifat' => 18.0, 'net_kdv' => 1.99, 'matrah_toplami' => 99.95,
            'tevkifat_kodu' => '601', 'tevkifat_aciklama' => 'YAPIM', 'kdv_20' => 19.99, 'matrah_20' => 99.95, 'oran_20' => '%20',
        ], array_intersect_key($satirlar['CKL'], array_flip([
            'orijinal_tutar', 'orijinal_indirim', 'net_tutar', 'orijinal_kdv', 'fatura_toplami', 'orijinal_tevkifat', 'net_kdv',
            'oran_20', 'kdv_20', 'matrah_20', 'matrah_toplami', 'tevkifat_kodu', 'tevkifat_aciklama',
        ])));
        // Rapor kolonu olmayan oran: KDV'ye ve Matrah Toplamı'na girer, oran kolonlarına girmez
        $this->assertEquals(
            ['entegrator' => 'Logo', 'orijinal_kdv' => 18.0, 'matrah_toplami' => 100.0, 'kdv_20' => 0.0, 'oran_20' => ''],
            array_intersect_key($satirlar['LGO'], array_flip(['orijinal_kdv', 'kdv_20', 'oran_20', 'matrah_toplami', 'entegrator'])),
        );
    }

    public function test_366_gunden_genis_aralik_liste_ve_excelde_422_doner(): void
    {
        $raporcu = $this->raporcu();
        $genis = ['ilk_tarih' => '2025-01-01', 'son_tarih' => '2026-01-02'];

        $this->actingAs($raporcu)->getJson(self::ADRES.'?'.http_build_query($genis))
            ->assertUnprocessable()
            ->assertJsonPath('kod', 'DOGRULAMA')
            ->assertJsonPath('hatalar.son_tarih.0', 'Tarih aralığı en fazla 366 gün olabilir.');
        $this->actingAs($raporcu)->postJson(self::ADRES.'/excel', [...$genis, 'anahtarlar' => []])
            ->assertUnprocessable()
            ->assertJsonPath('hatalar.son_tarih.0', 'Tarih aralığı en fazla 366 gün olabilir.');
        // Sınır: 366 gün
        $this->actingAs($raporcu)->getJson(self::ADRES.'?'.http_build_query(['ilk_tarih' => '2025-01-01', 'son_tarih' => '2026-01-01']))
            ->assertOk();
    }

    /**
     * Kuru olmayan dövizli fatura TL toplamına giremez: genel TL satırının
     * etiketi kaç faturanın dışarıda kaldığını söyler, özet sayfasında o
     * grubun TL hücreleri boş kalır (0 değil).
     */
    public function test_excel_toplam_satirlari_ve_ozet_sayfasi_kursuz_dovizi_ayri_gosterir(): void
    {
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        $fatura = fn (string $no, string $firma, string $para, string $tutar, string $vergi, ?string $kur, string $tarih) => EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_no' => $no, 'gonderici_unvan' => $firma, 'para_birimi' => $para,
            'tutar' => $tutar, 'vergi_tutari' => $vergi, 'kur' => $kur, 'belge_tarihi' => $tarih,
        ]);
        $try = $fatura('TRY1', 'Ankara A.Ş.', 'TRY', '120.12', '20.02', null, '2026-11-22');
        $eur = $fatura('EUR1', 'Berlin GmbH', 'EUR', '12.06', '2.01', '50.000000', '2026-11-21');
        $usd = $fatura('USD1', 'Chicago Inc', 'USD', '9.32', '1.55', null, '2026-11-20');

        $yanit = $this->actingAs($this->raporcu())
            ->post(self::ADRES.'/excel', [...self::PARAMETRELER, 'anahtarlar' => [(string) $try->id, (string) $eur->id, (string) $usd->id]])
            ->assertOk();

        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $data = $kitap->getSheetByName('Data');
        $kolon = array_flip(array_filter($data->rangeToArray('A1:AQ1')[0]));
        $hucre = fn ($sayfa, string $baslik, int $satir) => $sayfa->getCell([$kolon[$baslik] + 1, $satir])->getValue();
        // Satır 2–4 faturalar; 5 genel TL toplamı; 6–8 para birimine göre orijinal toplamlar
        $this->assertSame('GENEL TOPLAM (TL) — kuru olmayan 1 fatura hariç', $data->getCell('A5')->getValue());
        $this->assertEquals([602.6, 120.52, 723.12], [$hucre($data, 'Tutar TL', 5), $hucre($data, 'KDV TL', 5), $hucre($data, 'Fatura Toplamı TL', 5)]);
        $this->assertSame(['TOPLAM', 'TOPLAM', 'TOPLAM'], [$data->getCell('A6')->getValue(), $data->getCell('A7')->getValue(), $data->getCell('A8')->getValue()]);
        $this->assertEquals(
            [['EUR', 10.05, 12.06], ['TRY', 100.1, 120.12], ['USD', 7.77, 9.32]],
            array_map(fn (int $s): array => [$hucre($data, 'Döviz Cinsi', $s), $hucre($data, 'Net Tutar ORJ', $s), $hucre($data, 'Fatura Toplamı ORJ', $s)], [6, 7, 8]),
        );

        $firma = $kitap->getSheetByName('Firma');
        $ozetKolon = array_flip(array_filter($firma->rangeToArray('A1:AQ1')[0]));
        $ozet = fn (string $baslik, int $satir) => $firma->getCell([$ozetKolon[$baslik] + 1, $satir])->getValue();
        $this->assertSame(['Ankara A.Ş.', 'Berlin GmbH', 'Chicago Inc'], [$ozet('Firma Ünvanı', 2), $ozet('Firma Ünvanı', 3), $ozet('Firma Ünvanı', 4)]);
        $this->assertEquals([100.1, 502.5, null], [$ozet('Tutar TL', 2), $ozet('Tutar TL', 3), $ozet('Tutar TL', 4)]);
        $this->assertEquals(7.77, $ozet('Net Tutar ORJ', 4));
    }

    public function test_izinsiz_403_excel_ekranda_suzulen_satirlari_logo_kolonlariyla_yazar(): void
    {
        $izibiz = EntegratorBaglanti::factory()->aktif()->create();
        $bir = EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'IZB1', 'belge_tarihi' => '2026-11-20', 'para_birimi' => 'TRY', 'tutar' => '120.00', 'vergi_haric_tutar' => '100.00']);
        $this->kalemler($bir, [['vergi', '0015', '20.0000', '100.00', '20.00']]);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_no' => 'IZB2', 'belge_tarihi' => '2026-11-21']);

        $this->actingAs($this->raporcu('gider_yansitma_faturalari.goruntule'))
            ->getJson(self::ADRES.'?'.http_build_query(self::PARAMETRELER))->assertForbidden();

        $yanit = $this->actingAs($this->raporcu())
            ->post(self::ADRES.'/excel', [...self::PARAMETRELER, 'anahtarlar' => [(string) $bir->id]])
            ->assertOk();
        $this->assertStringContainsString('gelen-fatura-raporu.xlsx', (string) $yanit->headers->get('Content-Disposition'));

        $kitap = IOFactory::load($yanit->baseResponse->getFile()->getPathname());
        $this->assertSame(['Data', 'Firma', 'Entegratör', 'Tarih'], $kitap->getSheetNames());
        $sayfa = $kitap->getSheet(0);
        $baslik = $sayfa->rangeToArray('A1:AQ1')[0];
        $this->assertSame(['Sıra No', 'Fatura Tarihi', 'Fatura No', 'Fatura Profili', 'Entegratör', 'Firma Ünvanı'], array_slice($baslik, 0, 6));
        $this->assertContains('Matrah 20', $baslik);
        $this->assertNotContains('Hareket No', $baslik);
        // Yalnız seçilen satır (IZB2 yok)
        $this->assertSame('IZB1', $sayfa->getCell('C2')->getValue());
        $this->assertNotSame('IZB2', $sayfa->getCell('C3')->getValue());
        $matrah20 = array_search('Matrah 20', $baslik, true) + 1;
        $this->assertEquals(100, $sayfa->getCell([$matrah20, 2])->getValue());
    }
}
