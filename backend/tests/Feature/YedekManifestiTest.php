<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\AppKeyParmakIzi;
use App\Services\Yedekleme\ImzaAnahtariYok;
use App\Services\Yedekleme\ManifestDeposu;
use App\Services\Yedekleme\ManifestOkumaSonucu;
use App\Services\Yedekleme\OlayGunlugu;
use App\Services\Yedekleme\OlayTuru;
use App\Services\Yedekleme\YedekImzasi;
use App\Services\Yedekleme\YedekKaynagi;
use App\Services\Yedekleme\YedekManifesti;
use App\Services\Yedekleme\YedekOlayi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * BY-006: imzalı manifest, salt eklemeli olay günlüğü, APP_KEY parmak izi
 * (README §5.3, §4.5, §7.5).
 */
final class YedekManifestiTest extends TestCase
{
    private const KIMLIK = '3f2a1b0c-9d8e-4f7a-8b6c-5d4e3f2a1b0c';

    private string $klasor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->klasor = sys_get_temp_dir().'/yedek-manifest-'.bin2hex(random_bytes(4));
        mkdir($this->klasor, 0750);
        config([
            'yedekleme.klasor' => $this->klasor,
            'yedekleme.kalici_kok' => sys_get_temp_dir(),
            'yedekleme.imza_anahtari' => $this->klasor.'/anahtar/imza.key',
        ]);
        app(YedekImzasi::class)->anahtarUret();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->klasor);

        parent::tearDown();
    }

    public static function manifest(string $kimlik = self::KIMLIK, string $sha256 = '', int $boyut = 5, string $dosyaAdi = 'erp_elle_2026-11-21_101500+0300.dump', ?string $baglanmaYetkileri = null): YedekManifesti
    {
        return new YedekManifesti(
            kimlik: $kimlik,
            alinmaZamani: CarbonImmutable::parse('2026-11-21 10:15:00', 'Europe/Istanbul'),
            kaynak: YedekKaynagi::Elle,
            planAdlari: [],
            veritabani: 'erp',
            dosyaAdi: $dosyaAdi,
            boyut: $boyut,
            sha256: $sha256 !== '' ? $sha256 : hash('sha256', 'dump!'),
            pgSunucuSurumu: '18.6',
            pgDumpSurumu: 'pg_dump (PostgreSQL) 18.6',
            uygulamaSurumu: 'abc1234',
            migrationlar: ['0001_01_01_000000_create_users_table'],
            satirSayilari: ['users' => 3, 'sessions' => 0],
            verisiAlinmayanTablolar: ['sessions'],
            veritabaniOzellikleri: ['kodlama' => 'UTF8', 'siralama' => 'en_US.UTF-8', 'karakter_turu' => 'en_US.UTF-8', 'ayarlar' => ['TimeZone=UTC'], 'baglanma_yetkileri' => $baglanmaYetkileri],
            sahipRol: 'erp',
            sifreli: false,
            appKeyParmakIzi: AppKeyParmakIzi::hesapla(),
            aciklama: 'Yıl sonu — öncesi',
            alanKullaniciId: 7,
            alanKullanici: 'Ayşe Çelik',
        );
    }

    private function depo(): ManifestDeposu
    {
        return app(ManifestDeposu::class);
    }

    public function test_imzali_manifest_yazilip_ayni_degerlerle_guvenilir_okunur(): void
    {
        $yol = $this->depo()->yaz(self::manifest(), $this->klasor);

        $sonuc = $this->depo()->oku($yol);

        $this->assertTrue($sonuc->guvenilirMi());
        $this->assertSame(self::manifest()->diziye(), $sonuc->manifest?->diziye());
        $this->assertSame(0640, fileperms($yol) & 0777);
    }

    public function test_manifestin_bir_bayti_degisince_imza_dogrulamasi_basarisiz(): void
    {
        $yol = $this->depo()->yaz(self::manifest(), $this->klasor);
        // Satır sayısındaki tek basamak değişir: JSON hâlâ geçerli ve şemaya uygun
        file_put_contents($yol, str_replace('"users": 3', '"users": 4', (string) file_get_contents($yol)));

        $sonuc = $this->depo()->oku($yol);

        $this->assertFalse($sonuc->guvenilirMi());
        $this->assertSame(ManifestOkumaSonucu::NEDEN_IMZA_TUTMUYOR, $sonuc->neden);
    }

    public function test_yedek_dosyasi_degisince_ozet_dogrulamasi_basarisiz(): void
    {
        $dump = $this->klasor.'/erp_elle_2026-11-21_101500+0300.dump';
        file_put_contents($dump, 'dump!');
        $manifest = self::manifest();
        $this->assertTrue(ManifestDeposu::dosyaTutuyorMu($manifest, $dump));

        // Aynı boyutta tek bayt farkı
        file_put_contents($dump, 'dumP!');

        $this->assertFalse(ManifestDeposu::dosyaTutuyorMu($manifest, $dump));
    }

    /**
     * Bozuk / eksik / imzasız manifest istisna değil "güvenilmeyen" sonuçtur:
     * tarama tek bozuk dosya yüzünden durmaz.
     */
    public function test_bozuk_ya_da_eksik_manifest_guvenilmeyen_sayilir_istisna_firlatmaz(): void
    {
        $gecerli = json_decode((string) file_get_contents($this->depo()->yaz(self::manifest(), $this->klasor)), true);
        $imzasiz = $this->klasor.'/imzasiz.json';
        file_put_contents($imzasiz, json_encode(['manifest' => $gecerli['manifest']]));
        $bozuk = $this->klasor.'/bozuk.json';
        file_put_contents($bozuk, '{"manifest": ');
        $semaDisi = $this->klasor.'/sema.json';
        $veri = $gecerli['manifest'];
        $veri['boyut'] = 0;
        file_put_contents($semaDisi, json_encode(['manifest' => $veri, 'imza' => app(YedekImzasi::class)->imzala($veri)]));

        $this->assertSame(ManifestOkumaSonucu::NEDEN_YOK, $this->depo()->oku($this->klasor.'/yok.json')->neden);
        $this->assertSame(ManifestOkumaSonucu::NEDEN_IMZA_YOK, $this->depo()->oku($imzasiz)->neden);
        $this->assertSame(ManifestOkumaSonucu::NEDEN_JSON, $this->depo()->oku($bozuk)->neden);
        $this->assertSame(ManifestOkumaSonucu::NEDEN_SEMA, $this->depo()->oku($semaDisi)->neden);
    }

    public function test_imza_anahtari_yoksa_manifest_guvenilmeyen_yazma_anlasilir_hata(): void
    {
        $yol = $this->depo()->yaz(self::manifest(), $this->klasor);
        config(['yedekleme.imza_anahtari' => $this->klasor.'/olmayan.key']);
        $this->app->forgetInstance(YedekImzasi::class);

        $this->assertSame(ManifestOkumaSonucu::NEDEN_ANAHTAR_YOK, app(ManifestDeposu::class)->oku($yol)->neden);
        $this->expectException(ImzaAnahtariYok::class);
        $this->expectExceptionMessage('yedek:imza-anahtari');
        app(ManifestDeposu::class)->yaz(self::manifest(), $this->klasor);
    }

    public function test_var_olan_imza_anahtarinin_ustune_yazilmaz(): void
    {
        $yol = $this->klasor.'/anahtar/imza.key';
        $once = file_get_contents($yol);

        $this->artisan('yedek:imza-anahtari')->expectsOutputToContain('zaten var')->assertSuccessful();

        $this->assertSame($once, file_get_contents($yol));
        $this->assertSame(0600, fileperms($yol) & 0777);
    }

    public function test_olay_gunlugunden_durum_yeniden_kurulur_sabitle_coz_sabitle_sabittir(): void
    {
        $gunluk = app(OlayGunlugu::class);
        $zaman = CarbonImmutable::parse('2026-11-21 08:00', 'UTC');
        foreach ([OlayTuru::Sabitlendi, OlayTuru::SabitCozuldu, OlayTuru::Sabitlendi] as $i => $tur) {
            $gunluk->ekle(new YedekOlayi($zaman->addMinutes($i), self::KIMLIK, $tur));
        }
        $gunluk->ekle(new YedekOlayi($zaman->addMinutes(5), self::KIMLIK, OlayTuru::Kopya, [
            'hedef_id' => 4, 'hedef_ad' => 'FTP', 'durum' => 'tamam', 'uzak_kimlik' => 'erp.dump.gpg',
            'sifreli' => true, 'sifreli_sha256' => str_repeat('a', 64), 'plan_idleri' => [2],
        ]));

        $durum = $gunluk->durumlar()[self::KIMLIK];

        $this->assertTrue($durum->sabit);
        $this->assertSame('tamam', $durum->kopyalar['id:4']['durum']);
        $this->assertSame([2], $durum->kopyalar['id:4']['plan_idleri']);
    }

    public function test_imzasi_tutmayan_olay_satiri_atlanir_gerisi_okunur(): void
    {
        $gunluk = app(OlayGunlugu::class);
        $gunluk->ekle(YedekOlayi::simdi(self::KIMLIK, OlayTuru::Sabitlendi));
        $satirlar = file($gunluk->yol());
        // Biri imzasız ikinci bir "çöz" olayı ekler
        $sahte = json_decode($satirlar[0], true);
        $sahte['olay']['tur'] = 'sabit_cozuldu';
        file_put_contents($gunluk->yol(), json_encode($sahte)."\n", FILE_APPEND);

        $okunan = $gunluk->oku();

        $this->assertCount(1, $okunan['olaylar']);
        $this->assertSame(1, $okunan['gecersiz']);
        $this->assertTrue($gunluk->durumlar()[self::KIMLIK]->sabit);
    }

    public function test_app_key_parmak_izi_anahtari_tasimaz_anahtar_degisince_degisir(): void
    {
        $anahtar = 'base64:'.base64_encode(str_repeat('k', 32));

        $iz = AppKeyParmakIzi::hesapla($anahtar);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/D', (string) $iz);
        $this->assertStringNotContainsString(str_repeat('k', 8), (string) $iz);
        $this->assertStringNotContainsString(substr($anahtar, 7, 16), (string) $iz);
        $this->assertNotSame($iz, AppKeyParmakIzi::hesapla('base64:'.base64_encode(str_repeat('m', 32))));
        $this->assertNull(AppKeyParmakIzi::hesapla(''));
    }

    public function test_kisa_imza_anahtarinin_icerigi_hata_mesajina_yazilmaz(): void
    {
        file_put_contents($this->klasor.'/kisa.key', 'gizli-kisa');
        config(['yedekleme.imza_anahtari' => $this->klasor.'/kisa.key']);
        $this->app->forgetInstance(YedekImzasi::class);

        try {
            app(YedekImzasi::class)->imzala(['a' => 1]);
            $this->fail('Kısa anahtar kabul edildi.');
        } catch (ImzaAnahtariYok $hata) {
            $this->assertStringNotContainsString('gizli-kisa', $hata->getMessage());
        }
    }
}
