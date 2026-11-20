<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Yedekleme\SahteSftpSunucusu;

/**
 * BY-022: SFTP hedefi. `sftp` ve `ssh-keyscan` Process::fake ile; sahte sftp
 * toplu komut dosyasını yerel bir klasörde uygular (SahteSftpSunucusu).
 * Gerçek bir SSH sunucusuyla deneme ayrıca yapılır.
 */
final class SftpBaglayicisiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SIFRE = 'Gizli-SFTP"parola;7';

    /** ssh-keygen ile üretilmiş deneme anahtarları ve `ssh-keygen -lf` çıktısındaki parmak izleri */
    private const ANAHTAR_ED = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMe0NgRXiRgIHEKi16ZxwBWlZnAgh5lzMpXqkOF+T8Aq';

    private const IZ_ED = 'SHA256:bQoij+8Q4Nz5jmVzeLk0k8WGznU0GewFFc/kwOrbhRY';

    private const ANAHTAR_EC = 'ecdsa-sha2-nistp256 AAAAE2VjZHNhLXNoYTItbmlzdHAyNTYAAAAIbmlzdHAyNTYAAABBBPUNfZA2epaxuCfylN2Y3BannSX64p31Z+4+SBIPAUAzvB6ee2dAjQhBTIQzMKRGfrq2kl+Yl5mHGyp7RRXx/58=';

    private string $kok;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kok = sys_get_temp_dir().'/emor-sftp-test-'.bin2hex(random_bytes(5));
        mkdir($this->kok.'/uzak/yedekler', 0700, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->kok);
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $baglanti
     * @param  array<string, string>  $gizli
     */
    private function hedef(array $baglanti = [], array $gizli = ['sifre' => self::SIFRE], bool $anahtarKayitli = true): YedekHedefi
    {
        $kayit = $anahtarKayitli ? [
            'sunucu_anahtari' => self::ANAHTAR_ED,
            'sunucu_parmak_izi' => self::IZ_ED,
            'sunucu_anahtari_icin' => 'sftp.ornek.test:2222',
        ] : [];

        return YedekHedefi::factory()->create([
            'ad' => 'SFTP deneme',
            'tur' => HedefTuru::Sftp,
            'baglanti' => $baglanti + $kayit + ['sunucu' => 'sftp.ornek.test', 'port' => 2222, 'kullanici_adi' => 'yedekci', 'klasor' => 'yedekler'],
            'gizli_bilgiler' => $gizli,
        ]);
    }

    /** Katalog üzerinden: 'sftp' türünün bu sınıfa bağlandığı da sınanır */
    private function baglayici(YedekHedefi $hedef): HedefBaglayicisi
    {
        return app(HedefKatalogu::class)->olustur($hedef);
    }

    private function sahteSftp(): SahteSftpSunucusu
    {
        $sahte = new SahteSftpSunucusu($this->kok.'/uzak');
        Process::fake(['*ssh-keyscan*' => '', '*sftp*' => $sahte->isle(...)]);

        return $sahte;
    }

    private function yerelDosya(string $icerik): string
    {
        $yol = $this->kok.'/yerel-'.bin2hex(random_bytes(4));
        file_put_contents($yol, $icerik);

        return $yol;
    }

    /** @return list<string> */
    private function uzaktakiDosyalar(): array
    {
        return array_values(array_diff(scandir($this->kok.'/uzak/yedekler') ?: [], ['.', '..']));
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

    /** @param list<string> $komut */
    private function secenek(array $komut, string $ad): ?string
    {
        foreach ($komut as $i => $arguman) {
            if ($arguman === '-o' && str_starts_with($komut[$i + 1] ?? '', $ad.'=')) {
                return substr($komut[$i + 1], strlen($ad) + 1);
            }
        }

        return null;
    }

    public function test_ilk_baglantida_sunucu_anahtari_ve_parmak_izi_kaydedilir(): void
    {
        $hedef = $this->hedef(anahtarKayitli: false);
        $sahte = new SahteSftpSunucusu($this->kok.'/uzak');
        Process::fake([
            '*ssh-keyscan*' => '[sftp.ornek.test]:2222 '.self::ANAHTAR_EC."\n[sftp.ornek.test]:2222 ".self::ANAHTAR_ED."\n",
            '*sftp*' => $sahte->isle(...),
        ]);

        $this->baglayici($hedef)->baglan();

        $kayit = $hedef->refresh()->baglanti;
        $this->assertSame(self::ANAHTAR_ED, $kayit['sunucu_anahtari']);
        $this->assertSame(self::IZ_ED, $kayit['sunucu_parmak_izi']);
        $this->assertSame('sftp.ornek.test', $kayit['sunucu']);
        $this->assertSame('[sftp.ornek.test]:2222 '.self::ANAHTAR_ED."\n", $sahte->cagrilar[0]['dosyalar']['known_hosts']['icerik']);
        Process::assertRan(fn ($p): bool => in_array('-p', $p->command, true) && in_array('2222', $p->command, true) && str_contains($p->command[0], 'ssh-keyscan'));
    }

    public function test_sunucu_anahtari_degisince_baglanti_uyariyla_reddedilir_ve_kayit_degismez(): void
    {
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();
        $sahte->zorunluHata = ["@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@@\n@    WARNING: REMOTE HOST IDENTIFICATION HAS CHANGED!     @\nHost key verification failed.\r\n", 255];

        $hata = $this->hata(fn () => $this->baglayici($hedef)->baglan());

        $this->assertSame(HedefHatasi::SUNUCU_PARMAK_IZI, $hata->kod);
        $this->assertStringContainsString(self::IZ_ED, $hata->getMessage());
        $this->assertSame(self::IZ_ED, $hedef->refresh()->baglanti['sunucu_parmak_izi']);
        Process::assertNotRan('*ssh-keyscan*');
        $cagri = $sahte->cagrilar[0];
        $this->assertSame('yes', $this->secenek($cagri['komut'], 'StrictHostKeyChecking'));
        $this->assertSame($cagri['klasor'].'/known_hosts', $this->secenek($cagri['komut'], 'UserKnownHostsFile'));
        $this->assertSame('/dev/null', $this->secenek($cagri['komut'], 'GlobalKnownHostsFile'));
        $this->assertSame('[sftp.ornek.test]:2222 '.self::ANAHTAR_ED."\n", $cagri['dosyalar']['known_hosts']['icerik']);
    }

    public function test_parmak_izi_kayitli_degilken_kopya_baglanmaz(): void
    {
        $hedef = $this->hedef(anahtarKayitli: false);
        Process::fake();

        $hata = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya('x'), 'erp_x.dump.gpg'));

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        Process::assertNothingRan();
    }

    public function test_sunucu_degisince_eski_sunucunun_parmak_izi_kullanilmaz(): void
    {
        $hedef = $this->hedef(['sunucu' => 'yeni.ornek.test']);
        Process::fake();

        $hata = $this->hata(fn () => $this->baglayici($hedef)->listele());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        Process::assertNothingRan();
    }

    public function test_parola_komut_satirina_ve_ortama_girmez_askpass_ile_dosyadan_okunur(): void
    {
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();

        $this->baglayici($hedef)->listele();

        $cagri = $sahte->cagrilar[0];
        $this->assertStringNotContainsString(self::SIFRE, implode(' ', $cagri['komut']));
        $this->assertStringNotContainsString(self::SIFRE, implode(' ', array_map(fn ($d) => (string) $d, $cagri['ortam'])));
        $this->assertSame('force', $cagri['ortam']['SSH_ASKPASS_REQUIRE']);
        $this->assertSame($cagri['klasor'].'/askpass', $cagri['ortam']['SSH_ASKPASS']);
        $this->assertSame(self::SIFRE, $cagri['askpass']);
        $this->assertStringNotContainsString(self::SIFRE, $cagri['dosyalar']['askpass']['icerik']);
        $this->assertSame(['izin' => 0600, 'icerik' => self::SIFRE], $cagri['dosyalar']['parola']);
        $this->assertSame(0700, $cagri['dosyalar']['askpass']['izin']);
        $this->assertSame(0700, $cagri['klasorIzni']);
        $this->assertFalse($cagri['ortam']['SSH_AUTH_SOCK']);
        $this->assertSame('no', $this->secenek($cagri['komut'], 'BatchMode'));
        $this->assertLessThan(array_search('-b', $cagri['komut'], true), array_search('BatchMode=no', $cagri['komut'], true));
        $this->assertSame('no', $this->secenek($cagri['komut'], 'PubkeyAuthentication'));
        $this->assertDirectoryDoesNotExist($cagri['klasor']);
    }

    public function test_ozel_anahtar_0600_gecici_dosyaya_yazilir_ve_islemden_sonra_silinir(): void
    {
        $anahtar = "-----BEGIN OPENSSH PRIVATE KEY-----\r\nb3BlbnNzaC1rZXktdjEAAAAA\r\n-----END OPENSSH PRIVATE KEY-----";
        $hedef = $this->hedef(gizli: ['ozel_anahtar' => $anahtar]);
        $sahte = $this->sahteSftp();

        $this->baglayici($hedef)->listele();

        $cagri = $sahte->cagrilar[0];
        $this->assertSame(
            ['izin' => 0600, 'icerik' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----\n"],
            $cagri['dosyalar']['anahtar'],
        );
        $this->assertSame($cagri['klasor'].'/anahtar', $cagri['komut'][array_search('-i', $cagri['komut'], true) + 1]);
        $this->assertStringNotContainsString('b3BlbnNzaC1rZXktdjEAAAAA', implode(' ', $cagri['komut']));
        $this->assertSame('yes', $this->secenek($cagri['komut'], 'BatchMode'));
        $this->assertSame('no', $this->secenek($cagri['komut'], 'PasswordAuthentication'));
        $this->assertEqualsCanonicalizing(['anahtar', 'komutlar', 'known_hosts'], array_keys($cagri['dosyalar']));
        $this->assertFalse($cagri['ortam']['SSH_ASKPASS']);
        $this->assertDirectoryDoesNotExist($cagri['klasor']);
    }

    public function test_yedek_once_gecici_ada_yuklenir_sonra_asil_ada_tasinir(): void
    {
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();
        $kaynak = $this->yerelDosya(random_bytes(10_000));

        $kimlik = $this->baglayici($hedef)->yaz($kaynak, 'erp_20261121_0300.dump.gpg');

        $this->assertSame('erp_20261121_0300.dump.gpg', $kimlik);
        $this->assertSame(['erp_20261121_0300.dump.gpg'], $this->uzaktakiDosyalar());
        $this->assertSame(hash_file('sha256', $kaynak), hash_file('sha256', $this->kok.'/uzak/yedekler/erp_20261121_0300.dump.gpg'));
        $toplu = $sahte->cagrilar[0]['toplu'];
        $this->assertCount(3, $toplu);
        $this->assertSame('@cd "yedekler"', $toplu[0]);
        $this->assertMatchesRegularExpression('/^@put "'.preg_quote($kaynak, '/').'" "(\.erp_20261121_0300\.dump\.gpg\.[0-9a-f]{12}\.tmp)"$/D', $toplu[1]);
        preg_match('/"(\.[^"]+\.tmp)"$/', $toplu[1], $gecici);
        $this->assertSame('@rename "'.$gecici[1].'" "erp_20261121_0300.dump.gpg"', $toplu[2]);
        $this->assertSame(900, $sahte->cagrilar[0]['zaman']);
    }

    public function test_aktarim_yarida_koparsa_asil_adda_dosya_kalmaz_ve_gecici_dosya_silinir(): void
    {
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();
        $sahte->kopacakKomut = 'put';

        $hata = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya(random_bytes(10_000)), 'erp_kopuk.dump.gpg'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->uzaktakiDosyalar());
        $this->assertCount(2, $sahte->cagrilar);
    }

    public function test_yeniden_adlandirma_basarisizsa_gecici_dosya_silinir(): void
    {
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();
        $sahte->reddedilecekKomut = 'rename';

        $hata = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya('icerik'), 'erp_x.dump.gpg'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->uzaktakiDosyalar());
    }

    public function test_takilan_aktarim_kopya_sure_sinirinda_kesilir_ve_baglanma_suresi_sinirlidir(): void
    {
        config(['yedekleme.sure_siniri.kopya' => 5]);
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();
        $sahte->takilacakKomut = 'put';

        $hata = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya('icerik'), 'erp_asili.dump.gpg'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertStringContainsString('zaman aşımı', $hata->getMessage());
        $this->assertSame(5, $sahte->cagrilar[0]['zaman']);
        $this->assertSame('10', $this->secenek($sahte->cagrilar[0]['komut'], 'ConnectTimeout'));
        $this->assertSame('15', $this->secenek($sahte->cagrilar[0]['komut'], 'ServerAliveInterval'));
        $this->assertSame(30, $sahte->cagrilar[1]['zaman']);
        $this->assertDirectoryDoesNotExist($sahte->cagrilar[0]['klasor']);
    }

    public function test_kimlik_reddi_kimlik_hatasi_verir_mesajda_sir_ve_sunucu_ciktisi_yok(): void
    {
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();
        $sahte->zorunluHata = ["KURUM-BANNER-METNI\nyedekci@sftp.ornek.test: Permission denied (publickey,password).\r\nConnection closed\n", 255];

        $hata = $this->hata(fn () => $this->baglayici($hedef)->listele());

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        $this->assertStringNotContainsString(self::SIFRE, $hata->getMessage());
        $this->assertStringNotContainsString('KURUM-BANNER-METNI', $hata->getMessage());
        $this->assertDirectoryDoesNotExist($sahte->cagrilar[0]['klasor']);
    }

    public function test_kimlik_reddinde_yazma_ikinci_kez_baglanmaz(): void
    {
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();
        $sahte->zorunluHata = ["yedekci@sftp.ornek.test: Permission denied (publickey,password).\r\nConnection closed\n", 255];

        $hata = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya('x'), 'erp_x.dump.gpg'));

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        $this->assertCount(1, $sahte->cagrilar);
    }

    public function test_sunucuya_baglanilamazsa_erisilemedi_hatasi_verir(): void
    {
        $hedef = $this->hedef();
        $sahte = $this->sahteSftp();
        $sahte->zorunluHata = ["ssh: connect to host sftp.ornek.test port 2222: Connection refused\r\nConnection closed\n", 255];

        $hata = $this->hata(fn () => $this->baglayici($hedef)->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $hata->kod);
    }

    public function test_listele_yalniz_bu_uygulamanin_yedek_dosyalarini_dondurur(): void
    {
        foreach (['erp_a.dump', 'erp_b.dump.gpg', 'notlar.txt', 'erp_c.json', '.erp_d.dump.abc.tmp'] as $ad) {
            file_put_contents($this->kok.'/uzak/yedekler/'.$ad, 'x');
        }
        $hedef = $this->hedef();
        $this->sahteSftp();

        $liste = $this->baglayici($hedef)->listele();

        sort($liste);
        $this->assertSame(['erp_a.dump', 'erp_b.dump.gpg'], $liste);
    }

    public function test_kalan_alan_df_ciktisindan_bayt_olarak_okunur(): void
    {
        $hedef = $this->hedef();
        $this->sahteSftp();

        $kalan = $this->baglayici($hedef)->kalanAlan();

        $this->assertSame(88_993_157_120, $kalan);
    }

    public function test_olmayan_dosyayi_silmek_hata_vermez(): void
    {
        file_put_contents($this->kok.'/uzak/yedekler/erp_kalan.dump', 'x');
        $hedef = $this->hedef();
        $this->sahteSftp();

        $this->baglayici($hedef)->sil('erp_olmayan.dump');

        $this->assertSame(['erp_kalan.dump'], $this->uzaktakiDosyalar());
    }

    public function test_olmayan_dosya_okunamadi_hatasi_verir_ve_yerelde_artik_birakmaz(): void
    {
        $hedef = $this->hedef();
        $this->sahteSftp();
        $yerel = $this->kok.'/indirilen';

        $hata = $this->hata(fn () => $this->baglayici($hedef)->oku('erp_olmayan.dump', $yerel));

        $this->assertSame(HedefHatasi::OKUNAMADI, $hata->kod);
        $this->assertFileDoesNotExist($yerel);
    }

    public function test_sunucu_anahtari_alinamazsa_erisilemedi_hatasi_verir_ve_kayit_yapilmaz(): void
    {
        $hedef = $this->hedef(anahtarKayitli: false);
        $this->sahteSftp();

        $hata = $this->hata(fn () => $this->baglayici($hedef)->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $hata->kod);
        $this->assertArrayNotHasKey('sunucu_parmak_izi', $hedef->refresh()->baglanti);
        Process::assertNotRan(fn ($p): bool => str_ends_with((string) $p->command[0], '/sftp'));
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, string>}>
     */
    public static function gecersizTanimlar(): array
    {
        return [
            'ters eğik çizgili sunucu' => [['sunucu' => 'kotu.com\\.izinli.com.tr'], ['sifre' => self::SIFRE]],
            'seçenek gibi sunucu' => [['sunucu' => '-oProxyCommand'], ['sifre' => self::SIFRE]],
            'üst klasöre çıkan klasör' => [['klasor' => '../etc'], ['sifre' => self::SIFRE]],
            'tırnaklı klasör' => [['klasor' => 'yedek" "x'], ['sifre' => self::SIFRE]],
            'seçenek gibi kullanıcı' => [['kullanici_adi' => '-oProxyCommand=x'], ['sifre' => self::SIFRE]],
            'satır sonlu parola' => [[], ['sifre' => "parola\nikinci"]],
            'anahtar da parola da yok' => [[], []],
        ];
    }

    /**
     * @param  array<string, mixed>  $baglanti
     * @param  array<string, string>  $gizli
     */
    #[DataProvider('gecersizTanimlar')]
    public function test_gecersiz_tanimla_hic_calistirmadan_yapilandirma_hatasi_verir(array $baglanti, array $gizli): void
    {
        $hedef = $this->hedef($baglanti, $gizli);
        Process::fake();

        $hata = $this->hata(fn () => $this->baglayici($hedef)->listele());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        Process::assertNothingRan();
    }

    public function test_klasor_disina_cikan_dosya_adi_reddedilir(): void
    {
        $hedef = $this->hedef();
        Process::fake();

        $hata = $this->hata(fn () => $this->baglayici($hedef)->sil('../erp_x.dump'));

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        Process::assertNothingRan();
    }

    public function test_tanim_sunucu_disi_ve_sirlari_gizli_alanda_tutar(): void
    {
        $tanim = app(HedefKatalogu::class)->tanim(HedefTuru::Sftp);

        $gizliler = array_values(array_map(fn ($a) => $a->ad, array_filter($tanim->alanlar, fn ($a) => $a->gizli)));

        $this->assertTrue($tanim->sunucuDisi);
        $this->assertSame(['ozel_anahtar', 'sifre'], $gizliler);
    }
}
