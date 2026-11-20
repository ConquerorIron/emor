<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\HedefTuru;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\SahteFtpSunucusu;

/**
 * BY-020: FTP / FTPS hedefi. Gerçek curl çağrıları, testin açtığı yerel FTP
 * sunucusuna gider (SahteFtpSunucusu); gerçek bir sunucuyla deneme ayrıca
 * yapılır (kabul ölçütü, günlükte not).
 */
final class FtpBaglayicisiTest extends TestCase
{
    private const SIFRE = 'Gizli-FTP"parola;42';

    private string $kok;

    private string $gunluk;

    private ?SahteFtpSunucusu $sunucu = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kok = sys_get_temp_dir().'/emor-ftp-test-'.bin2hex(random_bytes(5));
        mkdir($this->kok.'/sunucu/yedekler', 0700, true);
        $this->gunluk = $this->kok.'/gunluk';
    }

    protected function tearDown(): void
    {
        $this->sunucu?->durdur();
        File::deleteDirectory($this->kok);
        parent::tearDown();
    }

    /** @param array<string, mixed> $secenekler */
    private function sunucuBaslat(array $secenekler = []): void
    {
        $this->sunucu = SahteFtpSunucusu::baslat($this->kok.'/sunucu', $secenekler + [
            'kullanici' => 'yedekci',
            'sifre' => self::SIFRE,
            'gunluk' => $this->gunluk,
        ]);
    }

    /**
     * Katalog üzerinden: 'ftp' / 'ftps' türünün bu sınıfa bağlandığı da sınanır.
     *
     * @param  array<string, mixed>  $baglanti
     */
    private function baglayici(array $baglanti = [], HedefTuru $tur = HedefTuru::Ftp, string $sifre = self::SIFRE): HedefBaglayicisi
    {
        $hedef = YedekHedefi::factory()->ftp()->make([
            'tur' => $tur,
            'baglanti' => $baglanti + [
                'sunucu' => '127.0.0.1',
                'port' => $this->sunucu?->port ?? 1,
                'kullanici_adi' => 'yedekci',
                'klasor' => 'yedekler',
            ],
            'gizli_bilgiler' => ['sifre' => $sifre],
        ]);

        return app(HedefKatalogu::class)->olustur($hedef);
    }

    private function yerelDosya(string $icerik): string
    {
        $yol = $this->kok.'/yerel-'.bin2hex(random_bytes(4));
        file_put_contents($yol, $icerik);

        return $yol;
    }

    /** @return list<string> */
    private function sunucudakiDosyalar(string $klasor = 'yedekler'): array
    {
        return array_values(array_diff(scandir($this->kok.'/sunucu/'.$klasor) ?: [], ['.', '..']));
    }

    /** @return list<string> */
    private function komutlar(): array
    {
        return is_file($this->gunluk) ? file($this->gunluk, FILE_IGNORE_NEW_LINES) ?: [] : [];
    }

    private function hata(callable $islem): HedefHatasi
    {
        try {
            $islem();
        } catch (HedefHatasi $hata) {
            return $hata;
        }

        $this->fail('HedefHatasi bekleniyordu.');
    }

    private function kendindenImzaliSertifika(): string
    {
        $anahtar = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $istek = openssl_csr_new(['commonName' => '127.0.0.1'], $anahtar, ['digest_alg' => 'sha256']);
        $sertifika = openssl_csr_sign($istek, null, $anahtar, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($sertifika, $sertifikaPem);
        openssl_pkey_export($anahtar, $anahtarPem);
        $yol = $this->kok.'/sunucu.pem';
        file_put_contents($yol, $sertifikaPem.$anahtarPem);

        return $yol;
    }

    public function test_sinama_yaz_oku_dogrula_sil_dongusunu_gecer(): void
    {
        $this->sunucuBaslat();

        $adimlar = app(HedefSinayici::class)->sina($this->baglayici());

        $this->assertTrue(HedefSinayici::basariliMi($adimlar), json_encode($adimlar, JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $this->sunucudakiDosyalar());
    }

    public function test_yedek_once_gecici_ada_yuklenir_sonra_asil_ada_tasinir(): void
    {
        $this->sunucuBaslat();
        $kaynak = $this->yerelDosya(random_bytes(300_000));

        $kimlik = $this->baglayici()->yaz($kaynak, 'erp_20261121_0300.dump.gpg');

        $this->assertSame('erp_20261121_0300.dump.gpg', $kimlik);
        $this->assertSame(['erp_20261121_0300.dump.gpg'], $this->sunucudakiDosyalar());
        $this->assertSame(hash_file('sha256', $kaynak), hash_file('sha256', $this->kok.'/sunucu/yedekler/erp_20261121_0300.dump.gpg'));
        $aktarim = array_values(array_filter($this->komutlar(), fn (string $k): bool => preg_match('/^(STOR|RNFR|RNTO) /', $k) === 1));
        $this->assertCount(3, $aktarim);
        $this->assertMatchesRegularExpression('/^STOR \.erp_20261121_0300\.dump\.gpg\.[0-9a-f]{12}\.tmp$/D', $aktarim[0]);
        $this->assertSame(['RNFR '.substr($aktarim[0], 5), 'RNTO erp_20261121_0300.dump.gpg'], [$aktarim[1], $aktarim[2]]);
    }

    public function test_aktarim_yarida_koparsa_asil_adda_dosya_kalmaz_ve_gecici_dosya_silinir(): void
    {
        $this->sunucuBaslat(['stor_kopar' => 65_536]);
        $kaynak = $this->yerelDosya(random_bytes(4_000_000));

        $hata = $this->hata(fn () => $this->baglayici()->yaz($kaynak, 'erp_kopuk.dump.gpg'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->sunucudakiDosyalar());
    }

    public function test_aktarim_koptuktan_sonra_yeniden_deneme_tamamlanir(): void
    {
        $this->sunucuBaslat(['stor_kopar' => 65_536]);
        $kaynak = $this->yerelDosya(random_bytes(4_000_000));
        $baglayici = $this->baglayici();
        $this->hata(fn () => $baglayici->yaz($kaynak, 'erp_kopuk.dump.gpg'));

        $baglayici->yaz($kaynak, 'erp_kopuk.dump.gpg');

        $this->assertSame(['erp_kopuk.dump.gpg'], $this->sunucudakiDosyalar());
        $this->assertSame(hash_file('sha256', $kaynak), hash_file('sha256', $this->kok.'/sunucu/yedekler/erp_kopuk.dump.gpg'));
    }

    public function test_yeniden_adlandirma_reddedilirse_gecici_dosya_silinir_ve_yazilamadi_hatasi_verilir(): void
    {
        $this->sunucuBaslat(['rnto_reddet' => true]);
        $kaynak = $this->yerelDosya('yedek-icerigi');

        $hata = $this->hata(fn () => $this->baglayici()->yaz($kaynak, 'erp_x.dump.gpg'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->sunucudakiDosyalar());
    }

    public function test_pasif_kip_kapaliyken_aktif_kiple_yukler(): void
    {
        $this->sunucuBaslat();
        $kaynak = $this->yerelDosya('aktif-kip');

        $this->baglayici(['pasif' => false])->yaz($kaynak, 'erp_aktif.dump.gpg');

        $this->assertSame(['erp_aktif.dump.gpg'], $this->sunucudakiDosyalar());
        $veriKomutlari = array_values(array_filter($this->komutlar(), fn (string $k): bool => preg_match('/^(EPRT|PORT|EPSV|PASV)\b/', $k) === 1));
        $this->assertNotSame([], $veriKomutlari);
        $this->assertSame([], array_filter($veriKomutlari, fn (string $k): bool => preg_match('/^(EPSV|PASV)\b/', $k) === 1));
    }

    public function test_mutlak_klasore_yazar(): void
    {
        mkdir($this->kok.'/sunucu/arsiv/ftp', 0700, true);
        $this->sunucuBaslat();
        $kaynak = $this->yerelDosya('mutlak');

        $this->baglayici(['klasor' => '/arsiv/ftp/'])->yaz($kaynak, 'erp_mutlak.dump.gpg');

        $this->assertSame(['erp_mutlak.dump.gpg'], $this->sunucudakiDosyalar('arsiv/ftp'));
        $this->assertSame([], $this->sunucudakiDosyalar());
    }

    public function test_listele_yalniz_bu_uygulamanin_yedek_dosyalarini_dondurur(): void
    {
        foreach (['erp_a.dump', 'erp_b.dump.gpg', 'notlar.txt', 'erp_c.json', '.erp_d.dump.abc.tmp'] as $ad) {
            file_put_contents($this->kok.'/sunucu/yedekler/'.$ad, 'x');
        }
        $this->sunucuBaslat();

        $liste = $this->baglayici()->listele();

        sort($liste);
        $this->assertSame(['erp_a.dump', 'erp_b.dump.gpg'], $liste);
    }

    public function test_olmayan_dosyayi_silmek_hata_vermez(): void
    {
        file_put_contents($this->kok.'/sunucu/yedekler/erp_kalan.dump', 'x');
        $this->sunucuBaslat();

        $this->baglayici()->sil('erp_olmayan.dump');

        $this->assertSame(['erp_kalan.dump'], $this->sunucudakiDosyalar());
    }

    public function test_olmayan_dosya_okunamadi_hatasi_verir_ve_yerelde_artik_birakmaz(): void
    {
        $this->sunucuBaslat();
        $yerel = $this->kok.'/indirilen';

        $hata = $this->hata(fn () => $this->baglayici()->oku('erp_olmayan.dump', $yerel));

        $this->assertSame(HedefHatasi::OKUNAMADI, $hata->kod);
        $this->assertFileDoesNotExist($yerel);
    }

    public function test_olmayan_klasore_baglanti_erisilemedi_hatasi_verir(): void
    {
        $this->sunucuBaslat();

        $hata = $this->hata(fn () => $this->baglayici(['klasor' => 'olmayan'])->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $hata->kod);
    }

    public function test_hatali_parola_kimlik_hatasi_verir_ve_mesajda_parola_yok(): void
    {
        $this->sunucuBaslat();

        $hata = $this->hata(fn () => $this->baglayici(sifre: 'Yanlis-parola-987')->baglan());

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        $this->assertStringNotContainsString('Yanlis-parola-987', $hata->getMessage());
        $this->assertStringNotContainsString('giris reddedildi', $hata->getMessage());
    }

    public function test_hatali_parolayla_yazmada_ikinci_giris_denenmez(): void
    {
        $this->sunucuBaslat();
        $kaynak = $this->yerelDosya('x');

        $hata = $this->hata(fn () => $this->baglayici(sifre: 'Yanlis-parola-987')->yaz($kaynak, 'erp_x.dump.gpg'));

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        $this->assertCount(1, array_filter($this->komutlar(), fn (string $k): bool => str_starts_with($k, 'PASS ')));
    }

    public function test_sunucuya_baglanilamazsa_erisilemedi_hatasi_verir(): void
    {
        $dinleyici = stream_socket_server('tcp://127.0.0.1:0');
        $ad = (string) stream_socket_get_name($dinleyici, false);
        $kapaliPort = (int) substr($ad, (int) strrpos($ad, ':') + 1);
        fclose($dinleyici);

        $hata = $this->hata(fn () => $this->baglayici(['port' => $kapaliPort])->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $hata->kod);
    }

    public function test_aktarim_ortasinda_asili_kalan_sunucu_kopya_sure_sinirinda_birakilir(): void
    {
        config(['yedekleme.sure_siniri.kopya' => 2, 'yedekleme.sure_siniri.baglanti' => 1]);
        $this->sunucuBaslat(['stor_asili' => true]);
        $kaynak = $this->yerelDosya(random_bytes(8_000_000));
        $baslangic = hrtime(true);

        $hata = $this->hata(fn () => $this->baglayici()->yaz($kaynak, 'erp_asili.dump.gpg'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertStringContainsString('zaman aşımı', $hata->getMessage());
        $this->assertLessThan(15, (hrtime(true) - $baslangic) / 1e9);
    }

    public function test_karsilama_vermeyen_sunucu_baglanti_sure_sinirinda_birakilir(): void
    {
        config(['yedekleme.sure_siniri.kopya' => 1, 'yedekleme.sure_siniri.baglanti' => 1]);
        $asili = stream_socket_server('tcp://127.0.0.1:0');
        $ad = (string) stream_socket_get_name($asili, false);
        $port = (int) substr($ad, (int) strrpos($ad, ':') + 1);
        $kaynak = $this->yerelDosya('asili');
        $baslangic = hrtime(true);

        $hata = $this->hata(fn () => $this->baglayici(['port' => $port])->yaz($kaynak, 'erp_asili.dump.gpg'));

        fclose($asili);
        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertStringContainsString('zaman aşımı', $hata->getMessage());
        $this->assertLessThan(15, (hrtime(true) - $baslangic) / 1e9);
    }

    public function test_ftps_sertifikasi_dogrulanamayan_sunucuyu_reddeder_ve_parola_gondermez(): void
    {
        $this->sunucuBaslat(['tls' => $this->kendindenImzaliSertifika()]);

        $hata = $this->hata(fn () => $this->baglayici(tur: HedefTuru::Ftps)->baglan());

        $this->assertSame(HedefHatasi::SERTIFIKA, $hata->kod);
        $this->assertContains('AUTH TLS', $this->komutlar());
        $this->assertSame([], array_filter($this->komutlar(), fn (string $k): bool => preg_match('/^(USER|PASS)\b/', $k) === 1));
    }

    public function test_ftps_sifreli_baglantiyi_desteklemeyen_sunucuya_duz_baglanmaz(): void
    {
        $this->sunucuBaslat();

        $hata = $this->hata(fn () => $this->baglayici(tur: HedefTuru::Ftps)->baglan());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        $this->assertSame([], array_filter($this->komutlar(), fn (string $k): bool => preg_match('/^(USER|PASS)\b/', $k) === 1));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function gecersizTanimlar(): array
    {
        return [
            'ters eğik çizgili sunucu' => [['sunucu' => 'kotu.com\\.izinli.com.tr'], self::SIFRE],
            'seçenek gibi sunucu' => [['sunucu' => '-oProxyCommand'], self::SIFRE],
            'boşluklu sunucu' => [['sunucu' => 'ftp.ornek.test /x'], self::SIFRE],
            'yollu sunucu' => [['sunucu' => 'ftp.ornek.test/%2f'], self::SIFRE],
            'üst klasöre çıkan klasör' => [['klasor' => '../etc'], self::SIFRE],
            'arada üst klasör' => [['klasor' => 'yedek/../../etc'], self::SIFRE],
            'NUL içeren klasör' => [['klasor' => "yedek\0x"], self::SIFRE],
            'satır sonlu kullanıcı' => [['kullanici_adi' => "yedekci\r\nDELE x"], self::SIFRE],
            'satır sonlu parola' => [[], "parola\r\nDELE erp_a.dump"],
            'geçersiz port' => [['port' => 70000], self::SIFRE],
        ];
    }

    /** @param array<string, mixed> $baglanti */
    #[DataProvider('gecersizTanimlar')]
    public function test_gecersiz_tanimla_hic_baglanmadan_yapilandirma_hatasi_verir(array $baglanti, string $sifre): void
    {
        $baglayici = $this->baglayici($baglanti, sifre: $sifre);

        $hata = $this->hata(fn () => $baglayici->baglan());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        $this->assertStringNotContainsString($sifre, $hata->getMessage());
    }

    public function test_klasor_disina_cikan_dosya_adi_reddedilir(): void
    {
        $kaynak = $this->yerelDosya('x');

        $hata = $this->hata(fn () => $this->baglayici()->yaz($kaynak, '../erp_x.dump'));

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
    }

    public function test_duz_ftp_tanimi_acik_metin_uyarisi_tasir_ftps_tasimaz(): void
    {
        $katalog = app(HedefKatalogu::class);

        $ftp = $katalog->tanim(HedefTuru::Ftp);
        $ftps = $katalog->tanim(HedefTuru::Ftps);

        $this->assertSame('yedekleme.hedef.uyari.duz_ftp', $ftp->uyari);
        $this->assertNull($ftps->uyari);
        $this->assertTrue($ftp->sunucuDisi);
        $gizliler = array_values(array_map(fn ($a) => $a->ad, array_filter($ftp->alanlar, fn ($a) => $a->gizli)));
        $this->assertSame(['sifre'], $gizliler);
    }
}
