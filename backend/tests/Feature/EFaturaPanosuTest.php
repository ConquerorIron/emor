<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\EFatura;
use App\Models\EntegratorBaglanti;
use App\Models\Rol;
use App\Models\User;
use App\Services\EntegratorBaglantiServisi;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * e-Fatura panosu (EK-09): portal bazında gelen / işlenen / RED / işlenecek,
 * aylara göre işlenecek ve firma dökümü. Her pano sayısı, aynı süzgeçle
 * açılan Tüm Faturalar listesinin toplamına eşittir.
 */
final class EFaturaPanosuTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ARALIK = 'baslangic=2026-01-01&bitis=2026-12-31';

    /** Rolün izinleri TAM OLARAK bunlar (EFaturaEkranTest kalıbı) */
    private function izinli(string ...$izinler): User
    {
        $rol = Rol::query()->create(['ad' => 'Rol '.md5(implode(',', $izinler))]);
        DB::table('rol_izinleri')->insert(array_map(fn (string $izin): array => ['rol_id' => $rol->id, 'izin' => $izin], $izinler));
        $kullanici = User::factory()->create();
        $kullanici->roller()->attach($rol);

        return $kullanici;
    }

    /**
     * İzibiz: 2 işlenen, 1 RED (eMOR'da işlenmiş — uyarı), 2 işlenecek (Ocak, Mart), 1 gizlenen.
     * Protel: 1 SAP işlendi, 1 harici RED, 1 işlenecek (Şubat). Logo: 1 işlenecek (Ocak).
     * Kapsam dışı: pasif İzibiz tanımı, giden fatura, aralık dışı tarih.
     */
    private function veri(): void
    {
        $izibiz = EntegratorBaglanti::factory()->canli()->aktif()->create();
        $protel = EntegratorBaglanti::factory()->protel()->canli()->aktif()->create();
        $logo = app(EntegratorBaglantiServisi::class)->statikTanim('logo');
        $f = fn (EntegratorBaglanti $t, array $alanlar) => EFatura::factory()->create(['entegrator_baglanti_id' => $t->id, 'belge_tarihi' => '2026-01-10', ...$alanlar]);

        $f($izibiz, ['emor_durumu' => 'islendi', 'gonderici_vkn' => '1111111111', 'tutar' => '100.10']);
        $f($izibiz, ['emor_durumu' => 'elle_islendi']);
        $f($izibiz, ['emor_durumu' => 'islendi', 'durum' => 'REJECTED']);
        $f($izibiz, ['emor_durumu' => 'havuzda', 'gonderici_vkn' => '1111111111', 'tutar' => '200.25']);
        $f($izibiz, ['emor_durumu' => 'yok', 'belge_tarihi' => '2026-03-05', 'gonderici_vkn' => '1111111111', 'para_birimi' => 'USD', 'tutar' => '50.50']);
        $f($izibiz, ['emor_durumu' => 'yok', 'gizlenme_zamani' => '2026-02-01 10:00:00']);
        $f($protel, ['durum' => 'Received', 'sap_islendi' => true, 'erp_okundu' => null]);
        $f($protel, ['durum' => 'Received', 'harici_red_zamani' => '2026-02-02 10:00:00', 'erp_okundu' => null]);
        $f($protel, ['durum' => 'Received', 'belge_tarihi' => '2026-02-14', 'gonderici_vkn' => '2222222222', 'tutar' => '75.05', 'erp_okundu' => null]);
        $f($logo, ['kaynak_id' => null, 'ettn' => null, 'belge_anahtari' => 'aaaa', 'durum' => 'Alındı', 'gonderici_vkn' => '2222222222', 'tutar' => '10.10']);
        // Kapsam dışı
        $f(EntegratorBaglanti::factory()->create(), ['emor_durumu' => 'yok']);
        $f($izibiz, ['emor_durumu' => 'yok', 'yon' => 'giden']);
        $f($izibiz, ['emor_durumu' => 'yok', 'belge_tarihi' => '2025-12-31']);
    }

    public function test_portal_bazinda_sayilar_ve_aylara_gore_islenecekler(): void
    {
        $this->veri();

        $pano = $this->actingAs($this->izinli('efatura_panosu.goruntule'))
            ->getJson('/api/v1/efatura/pano?'.self::ARALIK)
            ->assertOk()
            ->json('data');

        $this->assertSame([
            ['saglayici' => 'logo', 'gelen' => 1, 'islenen' => 0, 'red' => 0, 'islenecek' => 1, 'red_islenmis' => 0, 'gizlenen' => 0],
            ['saglayici' => 'protel', 'gelen' => 3, 'islenen' => 1, 'red' => 1, 'islenecek' => 1, 'red_islenmis' => 0, 'gizlenen' => 0],
            ['saglayici' => 'izibiz', 'gelen' => 5, 'islenen' => 2, 'red' => 1, 'islenecek' => 2, 'red_islenmis' => 1, 'gizlenen' => 1],
            ['saglayici' => 'earsiv', 'gelen' => 0, 'islenen' => 0, 'red' => 0, 'islenecek' => 0, 'red_islenmis' => 0, 'gizlenen' => 0],
        ], $pano['portallar']);
        $this->assertSame(['gelen' => 9, 'islenen' => 3, 'red' => 2, 'islenecek' => 4, 'red_islenmis' => 1, 'gizlenen' => 1], $pano['toplam']);
        $this->assertSame([
            ['saglayici' => 'logo', 'aylar' => ['2026-01' => 1]],
            ['saglayici' => 'protel', 'aylar' => ['2026-02' => 1]],
            ['saglayici' => 'izibiz', 'aylar' => ['2026-01' => 1, '2026-03' => 1]],
            ['saglayici' => 'earsiv', 'aylar' => []],
        ], $pano['aylik']);
    }

    /** Panodaki sayı ile aynı süzgeçle açılan Tüm Faturalar listesinin toplamı eşit. */
    public function test_pano_sayisi_ayni_suzgecle_acilan_listenin_toplamina_esittir(): void
    {
        $this->veri();
        $kullanici = $this->izinli('efatura_panosu.goruntule', 'tum_faturalar.goruntule');
        $pano = $this->actingAs($kullanici)->getJson('/api/v1/efatura/pano?'.self::ARALIK)->json('data');

        foreach ($pano['portallar'] as $portal) {
            foreach (['gelen' => '', 'islenen' => '&sinif=islendi', 'red' => '&sinif=red', 'islenecek' => '&sinif=islenecek', 'red_islenmis' => '&sinif=red_islenmis', 'gizlenen' => '&gizlenenler=yalniz'] as $sayi => $suzgec) {
                $toplam = $this->actingAs($kullanici)
                    ->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK.'&entegrator='.$portal['saglayici'].$suzgec)
                    ->assertOk()
                    ->json('meta.total');
                $this->assertSame($portal[$sayi], $toplam, "{$portal['saglayici']} / {$sayi}");
            }
        }
    }

    public function test_islenecek_firma_dokumu_para_birimi_ayri_excel_ayni_satirlar(): void
    {
        $this->veri();
        $kullanici = $this->izinli('efatura_panosu.goruntule');
        $sorgu = self::ARALIK.'&sinif=islenecek';

        $this->actingAs($kullanici)->getJson('/api/v1/efatura/pano/firmalar?'.$sorgu)
            ->assertOk()
            ->assertJsonPath('data', [
                ['vkn' => '2222222222', 'unvan' => 'Gönderen A.Ş.', 'para_birimi' => 'TRY', 'adet' => 2, 'tutar' => '85.15'],
                ['vkn' => '1111111111', 'unvan' => 'Gönderen A.Ş.', 'para_birimi' => 'TRY', 'adet' => 1, 'tutar' => '200.25'],
                ['vkn' => '1111111111', 'unvan' => 'Gönderen A.Ş.', 'para_birimi' => 'USD', 'adet' => 1, 'tutar' => '50.50'],
            ]);

        $yanit = $this->actingAs($kullanici)->get('/api/v1/efatura/pano/firmalar/excel?'.$sorgu);
        $yanit->assertOk()->assertDownload('efatura-firmalar-2026-01-01-2026-12-31.xlsx');
        $yol = $yanit->baseResponse->getFile()->getPathname();
        $sayfa = IOFactory::load($yol)->getActiveSheet();
        unlink($yol);
        $this->assertSame([
            ['Gönderici VKN/TCKN', 'Gönderici', 'Para Birimi', 'Adet', 'Tutar'],
            ['2222222222', 'Gönderen A.Ş.', 'TRY', 2, 85.15],
            ['1111111111', 'Gönderen A.Ş.', 'TRY', 1, 200.25],
            ['1111111111', 'Gönderen A.Ş.', 'USD', 1, 50.5],
        ], $sayfa->rangeToArray('A1:E4', null, false, false));
    }

    /** Gün sınırı `< ertesi gün` (T6): SQLite tarihi saatle saklar, bitiş günü yine sayılır */
    public function test_araligin_son_gunundeki_fatura_panoya_ve_ayni_suzgecli_listeye_girer(): void
    {
        $izibiz = EntegratorBaglanti::factory()->canli()->aktif()->create();
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_tarihi' => '2026-12-31', 'emor_durumu' => 'yok']);
        EFatura::factory()->create(['entegrator_baglanti_id' => $izibiz->id, 'belge_tarihi' => '2027-01-01', 'emor_durumu' => 'yok']);
        $kullanici = $this->izinli('efatura_panosu.goruntule', 'tum_faturalar.goruntule');

        $this->actingAs($kullanici)->getJson('/api/v1/efatura/pano?'.self::ARALIK)
            ->assertOk()
            ->assertJsonPath('data.toplam.gelen', 1)
            ->assertJsonPath('data.toplam.islenecek', 1);
        $this->actingAs($kullanici)->getJson('/api/v1/tum-faturalar/faturalar?'.self::ARALIK.'&entegrator=izibiz&sinif=islenecek')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    /** Şahıs göndericinin ünvanı boş: adı ad soyaddan (rapordaki kuralla aynı) */
    public function test_firma_dokumu_unvani_bos_gondericide_ad_soyadi_gosterir(): void
    {
        $izibiz = EntegratorBaglanti::factory()->canli()->aktif()->create();
        EFatura::factory()->create([
            'entegrator_baglanti_id' => $izibiz->id, 'belge_tarihi' => '2026-01-10', 'emor_durumu' => 'yok',
            'gonderici_vkn' => '12345678901', 'gonderici_unvan' => null, 'gonderici_ad_soyad' => 'Ali Veli', 'tutar' => '10.00',
        ]);

        $this->actingAs($this->izinli('efatura_panosu.goruntule'))
            ->getJson('/api/v1/efatura/pano/firmalar?'.self::ARALIK.'&sinif=islenecek')
            ->assertOk()
            ->assertJsonPath('data', [['vkn' => '12345678901', 'unvan' => 'Ali Veli', 'para_birimi' => 'TRY', 'adet' => 1, 'tutar' => '10.00']]);
    }

    public function test_pano_izni_olmayan_kullaniciya_403(): void
    {
        $this->actingAs($this->izinli('tum_faturalar.goruntule'))
            ->getJson('/api/v1/efatura/pano?'.self::ARALIK)
            ->assertForbidden();
    }
}
