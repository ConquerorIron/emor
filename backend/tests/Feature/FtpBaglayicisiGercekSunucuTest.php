<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\HedefTuru;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BY-020 kabulü: FTP / FTPS bağlayıcısı GERÇEK vsftpd sunucusuna karşı.
 * Sunucular `scripts/tests/gercek-hedef-sunuculari.sh kur` ile yerelde
 * kurulur (yalnız 127.0.0.1). Yalnız açıkça istenince çalışır:
 *
 *   source ~/.cache/emor-yedek-deneme/ortam.sh
 *   php artisan test --compact tests/Feature/FtpBaglayicisiGercekSunucuTest.php
 *
 * Deneme CA'sına güven (`curl.cainfo`) yalnız ortam dosyası yüklenmiş kabukta
 * vardır; yoksa başarılı FTPS ve alan adı uyuşmazlığı testleri atlanır.
 * Kesinti testleri sunucudaki oturumu `sudo -n pkill` ile öldürür.
 */
final class FtpBaglayicisiGercekSunucuTest extends TestCase
{
    private string $kok;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('YEDEKLEME_GERCEK_FTP') !== '1') {
            $this->markTestSkipped('Gerçek FTP testi: YEDEKLEME_GERCEK_FTP=1 ile çalışır (scripts/tests/gercek-hedef-sunuculari.sh kur).');
        }

        $this->kok = sys_get_temp_dir().'/emor-ftp-gercek-'.bin2hex(random_bytes(5));
        mkdir($this->kok, 0700);
        $this->sunucudakiDenemeDosyalariniTemizle();
    }

    protected function tearDown(): void
    {
        if (isset($this->kok)) {
            $this->sunucudakiDenemeDosyalariniTemizle();
            File::deleteDirectory($this->kok);
        }
        parent::tearDown();
    }

    private function ortam(string $ad): string
    {
        $deger = getenv($ad);

        if (! is_string($deger) || $deger === '') {
            $this->markTestSkipped("{$ad} tanımlı değil (ortam.sh yüklenmedi).");
        }

        return $deger;
    }

    /** @param array<string, mixed> $baglanti */
    private function baglayici(array $baglanti = [], HedefTuru $tur = HedefTuru::Ftp, ?string $sifre = null): HedefBaglayicisi
    {
        $hedef = YedekHedefi::factory()->ftp()->make([
            'tur' => $tur,
            'baglanti' => $baglanti + [
                'sunucu' => $this->ortam('YEDEKLEME_FTP_SUNUCU'),
                'port' => (int) $this->ortam('YEDEKLEME_FTP_PORT'),
                'kullanici_adi' => $this->ortam('YEDEKLEME_FTP_KULLANICI'),
                'klasor' => 'yedekler',
                'pasif' => true,
            ],
            'gizli_bilgiler' => ['sifre' => $sifre ?? $this->ortam('YEDEKLEME_FTP_SIFRE')],
        ]);

        return app(HedefKatalogu::class)->olustur($hedef);
    }

    /** Deneme CA'sı ile imzalı, yalnız "localhost" adına sertifikası olan FTPS */
    private function caliFtps(string $sunucu = 'localhost'): HedefBaglayicisi
    {
        if (ini_get('curl.cainfo') !== $this->ortam('YEDEKLEME_FTPS_CA_PAKETI')) {
            $this->markTestSkipped('Deneme CA\'sına güven yok: ortam.sh yüklenmiş kabukta çalıştırın (PHP_INI_SCAN_DIR → curl.cainfo).');
        }

        return $this->baglayici(['sunucu' => $sunucu, 'port' => (int) $this->ortam('YEDEKLEME_FTPS_CA_PORT')], HedefTuru::Ftps);
    }

    private function yerelDosya(int $bayt): string
    {
        $yol = $this->kok.'/yerel-'.bin2hex(random_bytes(4));
        $tutamac = fopen($yol, 'wb');
        for ($kalan = $bayt; $kalan > 0; $kalan -= 1048576) {
            fwrite($tutamac, random_bytes(min(1048576, $kalan)));
        }
        fclose($tutamac);

        return $yol;
    }

    /** @return list<string> */
    private function sunucudakiDosyalar(string $klasor = 'yedekler'): array
    {
        $yol = $this->ortam('YEDEKLEME_FTP_EV').'/'.$klasor;

        return array_values(array_filter(scandir($yol) ?: [], fn (string $ad): bool => is_file($yol.'/'.$ad)));
    }

    private function sunucudakiDenemeDosyalariniTemizle(): void
    {
        foreach (['', 'yedekler', 'yedekler/alt', 'kucuk'] as $klasor) {
            $baglayici = $this->baglayici(['klasor' => $klasor]);
            foreach ($this->sunucudakiDosyalar($klasor) as $ad) {
                $baglayici->sil($ad);
            }
        }
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

    /** Günlüğün şu anki sonu: sonra yalnız yeni satırlar okunur */
    private function gunlukSonu(int $port): int
    {
        clearstatcache();

        return (int) filesize($this->ortam('YEDEKLEME_HEDEF_GUNLUK')."/vsftpd-{$port}.log");
    }

    private function yeniGunluk(int $port, int $baslangic): string
    {
        clearstatcache();

        return (string) file_get_contents($this->ortam('YEDEKLEME_HEDEF_GUNLUK')."/vsftpd-{$port}.log", offset: $baslangic);
    }

    /**
     * Sunucudaki dosya ya da yereldeki indirme 1 MB'ı geçince deneme
     * kullanıcısının bütün oturumlarını öldüren arka plan süreci.
     */
    private function kesiciBaslat(string $izlenenKlasor, string $desen, string $sinyal = 'KILL'): InvokedProcess
    {
        if (Process::run(['sudo', '-n', 'true'])->failed()) {
            $this->markTestSkipped('Kesinti testi sudo -n ister (sunucu oturumunu öldürmek için).');
        }

        $kullanici = $this->ortam('YEDEKLEME_FTP_KULLANICI');
        $betik = 'for i in $(seq 1 400); do f=$(find "$1" -maxdepth 1 -name "$2" -size +1M 2>/dev/null | head -n 1); '
            .'if [ -n "$f" ]; then sudo -n pkill -"$4" -u "$3"; echo kesildi; exit 0; fi; sleep 0.05; done; exit 1';

        return Process::timeout(60)->start(['bash', '-c', $betik, 'kesici', $izlenenKlasor, $desen, $kullanici, $sinyal]);
    }

    public function test_duz_ftp_sinamasi_yaz_oku_dogrula_sil_dongusunu_gecer(): void
    {
        $sonuc = app(HedefSinayici::class)->sonuc($this->baglayici());

        $this->assertTrue($sonuc['basarili'], json_encode($sonuc['adimlar'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(['baglan', 'yaz', 'oku', 'dogrula', 'sil'], array_column($sonuc['adimlar'], 'adim'));
        $this->assertNull($sonuc['kalan_alan'], 'FTP kalan alanı bildirmez');
        $this->assertSame([], $this->sunucudakiDosyalar(), 'Sınama hedefte dosya bırakmamalı');
    }

    public function test_ca_imzali_ftps_sinamasi_dongusunu_gecer_ve_parola_sifreli_kanaldan_gider(): void
    {
        $baglayici = $this->caliFtps();
        $port = (int) $this->ortam('YEDEKLEME_FTPS_CA_PORT');
        $once = $this->gunlukSonu($port);

        $sonuc = app(HedefSinayici::class)->sonuc($baglayici);

        $this->assertTrue($sonuc['basarili'], json_encode($sonuc['adimlar'], JSON_UNESCAPED_UNICODE));
        $gunluk = $this->yeniGunluk($port, $once);
        $this->assertLessThan(strpos($gunluk, '"USER '), strpos($gunluk, '"AUTH TLS"'), 'AUTH TLS, USER\'dan önce');
        $this->assertStringContainsString('"PROT P"', $gunluk, 'Veri kanalı da şifreli');
    }

    /** @return array<string, array{bool}> */
    public static function kipler(): array
    {
        return ['pasif' => [true], 'aktif' => [false]];
    }

    #[DataProvider('kipler')]
    public function test_50_mb_dosya_yazilir_listelenir_ayni_baytlarla_okunur_ve_silinir(bool $pasif): void
    {
        $baglayici = $this->baglayici(['pasif' => $pasif]);
        $kaynak = $this->yerelDosya(50 * 1048576);
        $ad = 'erp_elle_2026-11-22_101500+0300.dump';

        $this->assertSame($ad, $baglayici->yaz($kaynak, $ad));
        $this->assertSame([$ad], $baglayici->listele());
        $this->assertSame([$ad], $this->sunucudakiDosyalar(), 'Geçici dosya kalmamalı');

        $geri = $this->kok.'/geri';
        $baglayici->oku($ad, $geri);
        $this->assertSame(hash_file('sha256', $kaynak), hash_file('sha256', $geri));

        $baglayici->sil($ad);
        $this->assertSame([], $baglayici->listele());
        $this->assertNull($baglayici->kalanAlan());
    }

    public function test_ftps_50_mb_dosya_sifreli_veri_kanalindan_ayni_baytlarla_yazilir_ve_okunur(): void
    {
        $baglayici = $this->caliFtps();
        $kaynak = $this->yerelDosya(50 * 1048576);
        $geri = $this->kok.'/geri';

        $baglayici->yaz($kaynak, 'erp_a.dump');
        $baglayici->oku('erp_a.dump', $geri);

        $this->assertSame(hash_file('sha256', $kaynak), hash_file('sha256', $geri));
        $this->assertSame(['erp_a.dump'], $this->sunucudakiDosyalar());
    }

    public function test_mutlak_klasor_ve_alt_klasor_yazilir(): void
    {
        $kaynak = $this->yerelDosya(1024);

        $this->baglayici(['klasor' => '/yedekler'])->yaz($kaynak, 'erp_a.dump');
        $this->baglayici(['klasor' => 'yedekler/alt/'])->yaz($kaynak, 'erp_b.dump');

        $this->assertSame(['erp_a.dump'], $this->sunucudakiDosyalar());
        $this->assertSame(['erp_b.dump'], $this->sunucudakiDosyalar('yedekler/alt'));
    }

    public function test_bos_klasor_ve_kok_klasor_giris_klasorune_yazar_ve_siler(): void
    {
        $kaynak = $this->yerelDosya(1024);
        $bos = $this->baglayici(['klasor' => '']);
        $kokKlasor = $this->baglayici(['klasor' => '/']);

        $bos->yaz($kaynak, 'erp_a.dump');
        $kokKlasor->yaz($kaynak, 'erp_b.dump');
        $this->assertSame(['erp_a.dump', 'erp_b.dump'], $this->sunucudakiDosyalar(''));
        $this->assertSame(['erp_a.dump', 'erp_b.dump'], $kokKlasor->listele());

        $bos->sil('erp_b.dump');
        $kokKlasor->sil('erp_a.dump');
        $this->assertSame([], $this->sunucudakiDosyalar(''));
    }

    public function test_olmayan_klasor_acilmaz_baglanti_ve_yazma_hata_verir(): void
    {
        $baglayici = $this->baglayici(['klasor' => 'yedekler/olmayan']);

        $baglan = $this->hata(fn () => $baglayici->baglan());
        $yaz = $this->hata(fn () => $baglayici->yaz($this->yerelDosya(10), 'erp_a.dump'));

        $this->assertSame(HedefHatasi::ERISILEMEDI, $baglan->kod);
        $this->assertStringContainsString('klasöre erişilemedi', $baglan->getMessage());
        $this->assertSame(HedefHatasi::YAZILAMADI, $yaz->kod);
        $this->assertDirectoryDoesNotExist($this->ortam('YEDEKLEME_FTP_EV').'/yedekler/olmayan');
    }

    public function test_turkce_karakterli_ve_bosluklu_klasor_adi_baglanmadan_reddedilir(): void
    {
        $port = (int) $this->ortam('YEDEKLEME_FTP_PORT');
        $once = $this->gunlukSonu($port);

        $hata = $this->hata(fn () => $this->baglayici(['klasor' => 'Yedek Klasörü'])->baglan());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        $this->assertSame('', $this->yeniGunluk($port, $once), 'Sunucuya hiç bağlanılmamalı');
    }

    public function test_hatali_parola_ve_hatali_kullanici_kimlik_hatasi_verir_mesajda_parola_yok(): void
    {
        $yanlis = 'Yanlis "parola" 1';

        $parola = $this->hata(fn () => $this->baglayici(sifre: $yanlis)->baglan());
        $kullanici = $this->hata(fn () => $this->baglayici(['kullanici_adi' => 'olmayan_kullanici'])->baglan());

        $this->assertSame(HedefHatasi::KIMLIK, $parola->kod);
        $this->assertSame(HedefHatasi::KIMLIK, $kullanici->kod);
        $this->assertStringNotContainsString($yanlis, $parola->getMessage());
        $this->assertStringNotContainsString($this->ortam('YEDEKLEME_FTP_SIFRE'), $kullanici->getMessage());
    }

    public function test_kapali_port_ve_cozulemeyen_sunucu_erisilemedi_hatasi_verir(): void
    {
        $kapali = $this->hata(fn () => $this->baglayici(['port' => 2199])->baglan());
        $cozulemeyen = $this->hata(fn () => $this->baglayici(['sunucu' => 'olmayan-sunucu.invalid'])->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $kapali->kod);
        $this->assertStringContainsString('sunucuya bağlanılamadı', $kapali->getMessage());
        $this->assertSame(HedefHatasi::ERISILEMEDI, $cozulemeyen->kod);
    }

    public function test_yanit_vermeyen_adres_baglanti_sure_sinirinda_birakilir(): void
    {
        config(['yedekleme.sure_siniri.baglanti' => 2]);
        $baslangic = hrtime(true);

        // TEST-NET-1 (RFC 5737): yönlendirilmez, paketler düşer
        $hata = $this->hata(fn () => $this->baglayici(['sunucu' => '192.0.2.1'])->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $hata->kod);
        $this->assertLessThan(5.0, (hrtime(true) - $baslangic) / 1e9);
    }

    public function test_yazma_izni_olmayan_klasore_yazma_yazilamadi_hatasi_verir_ve_dosya_birakmaz(): void
    {
        $hata = $this->hata(fn () => $this->baglayici(['klasor' => 'salt-okunur'])->yaz($this->yerelDosya(1024), 'erp_a.dump'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->sunucudakiDosyalar('salt-okunur'));
    }

    public function test_sunucuda_yer_kalmazsa_yazma_basarisiz_olur_ve_yarim_dosya_kalmaz(): void
    {
        $baglayici = $this->baglayici(['klasor' => 'kucuk']);

        // Klasör 8 MB'lık bellek diski
        $hata = $this->hata(fn () => $baglayici->yaz($this->yerelDosya(12 * 1048576), 'erp_a.dump'));

        // vsftpd doluluğu "451 Failure writing to local file" ile bildirir (452 değil): genel yazma hatası
        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->sunucudakiDosyalar('kucuk'), 'Ne asıl adda ne geçici adda dosya kalmalı');
    }

    public function test_ftps_kendinden_imzali_sertifikayi_reddeder_ve_kullanici_adi_gondermez(): void
    {
        $port = (int) $this->ortam('YEDEKLEME_FTPS_KENDINDEN_PORT');
        $once = $this->gunlukSonu($port);

        $hata = $this->hata(fn () => $this->baglayici(['sunucu' => 'localhost', 'port' => $port], HedefTuru::Ftps)->baglan());

        $this->assertSame(HedefHatasi::SERTIFIKA, $hata->kod);
        $gunluk = $this->yeniGunluk($port, $once);
        $this->assertStringContainsString('"AUTH TLS"', $gunluk);
        $this->assertStringNotContainsString('"USER', $gunluk);
        $this->assertStringNotContainsString('"PASS', $gunluk);
    }

    public function test_ftps_alan_adi_uyusmayan_sertifikayi_reddeder(): void
    {
        $port = (int) $this->ortam('YEDEKLEME_FTPS_CA_PORT');
        $baglayici = $this->caliFtps('127.0.0.1');
        $once = $this->gunlukSonu($port);

        $hata = $this->hata(fn () => $baglayici->baglan());

        $this->assertSame(HedefHatasi::SERTIFIKA, $hata->kod);
        $this->assertStringNotContainsString('"USER', $this->yeniGunluk($port, $once));
    }

    public function test_ftps_tls_desteklemeyen_sunucuda_parola_gondermeden_durur(): void
    {
        $port = (int) $this->ortam('YEDEKLEME_FTP_PORT');
        $once = $this->gunlukSonu($port);

        $hata = $this->hata(fn () => $this->baglayici(tur: HedefTuru::Ftps)->baglan());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        $gunluk = $this->yeniGunluk($port, $once);
        $this->assertStringContainsString('"AUTH TLS"', $gunluk);
        $this->assertStringNotContainsString('"USER', $gunluk);
        $this->assertStringNotContainsString('"PASS', $gunluk);
    }

    public function test_yukleme_ortasinda_baglanti_koparsa_asil_adda_ve_gecici_adda_dosya_kalmaz_yeniden_deneme_tamamlanir(): void
    {
        $baglayici = $this->baglayici(['port' => (int) $this->ortam('YEDEKLEME_FTP_YAVAS_PORT')]);
        $kaynak = $this->yerelDosya(16 * 1048576);
        $ad = 'erp_elle_2026-11-22_101500+0300.dump';
        $kesici = $this->kesiciBaslat($this->ortam('YEDEKLEME_FTP_EV').'/yedekler', '.erp_*.tmp');

        $hata = $this->hata(fn () => $baglayici->yaz($kaynak, $ad));

        $this->assertSame('kesildi', trim($kesici->wait()->output()), 'Kesici aktarımı yakalayamadı');
        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->sunucudakiDosyalar(), 'Yarım dosya hedefte kalmamalı');

        $this->baglayici()->yaz($kaynak, $ad);
        $geri = $this->kok.'/geri';
        $this->baglayici()->oku($ad, $geri);
        $this->assertSame(hash_file('sha256', $kaynak), hash_file('sha256', $geri));
    }

    public function test_yukleme_ortasinda_asili_kalan_sunucu_kopya_sure_sinirinda_birakilir_ve_yarim_dosya_kalmaz(): void
    {
        config(['yedekleme.sure_siniri.kopya' => 3]);
        $baglayici = $this->baglayici(['port' => (int) $this->ortam('YEDEKLEME_FTP_YAVAS_PORT')]);
        // Oturum dondurulur (SIGSTOP): bağlantı açık kalır, veri akmaz
        $kesici = $this->kesiciBaslat($this->ortam('YEDEKLEME_FTP_EV').'/yedekler', '.erp_*.tmp', 'STOP');
        $baslangic = hrtime(true);

        try {
            $hata = $this->hata(fn () => $baglayici->yaz($this->yerelDosya(16 * 1048576), 'erp_a.dump'));
            $sure = (hrtime(true) - $baslangic) / 1e9;
        } finally {
            Process::run(['sudo', '-n', 'pkill', '-KILL', '-u', $this->ortam('YEDEKLEME_FTP_KULLANICI')]);
        }

        $this->assertSame('kesildi', trim($kesici->wait()->output()), 'Kesici aktarımı yakalayamadı');
        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertStringContainsString('zaman aşımı', $hata->getMessage());
        $this->assertLessThan(8.0, $sure);
        $this->assertSame([], $this->sunucudakiDosyalar(), 'Geçici dosya yeni bağlantıyla silinmeli');
    }

    public function test_ayni_adla_ikinci_yazma_oncekinin_ustune_yazar(): void
    {
        $baglayici = $this->baglayici();
        $baglayici->yaz($this->yerelDosya(2048), 'erp_a.dump');
        $ikinci = $this->yerelDosya(4096);

        $baglayici->yaz($ikinci, 'erp_a.dump');

        $geri = $this->kok.'/geri';
        $baglayici->oku('erp_a.dump', $geri);
        $this->assertSame(hash_file('sha256', $ikinci), hash_file('sha256', $geri));
        $this->assertSame(['erp_a.dump'], $this->sunucudakiDosyalar());
    }

    public function test_indirme_ortasinda_baglanti_koparsa_okunamadi_hatasi_verir_ve_yerelde_yarim_dosya_kalmaz(): void
    {
        $kaynak = $this->yerelDosya(16 * 1048576);
        $this->baglayici()->yaz($kaynak, 'erp_a.dump');
        mkdir($this->kok.'/indirme');
        $geri = $this->kok.'/indirme/geri.dump';
        $kesici = $this->kesiciBaslat($this->kok.'/indirme', 'geri.dump');

        $hata = $this->hata(fn () => $this->baglayici(['port' => (int) $this->ortam('YEDEKLEME_FTP_YAVAS_PORT')])->oku('erp_a.dump', $geri));

        $this->assertSame('kesildi', trim($kesici->wait()->output()), 'Kesici aktarımı yakalayamadı');
        $this->assertSame(HedefHatasi::OKUNAMADI, $hata->kod);
        $this->assertFileDoesNotExist($geri);
    }

    public function test_olmayan_dosyayi_silmek_hata_vermez_okumak_okunamadi_verir(): void
    {
        $baglayici = $this->baglayici();

        $baglayici->sil('erp_olmayan.dump');
        $hata = $this->hata(fn () => $baglayici->oku('erp_olmayan.dump', $this->kok.'/geri'));

        $this->assertSame(HedefHatasi::OKUNAMADI, $hata->kod);
        $this->assertFileDoesNotExist($this->kok.'/geri');
    }

    public function test_listele_yalniz_yedek_dosyalarini_dondurur(): void
    {
        $baglayici = $this->baglayici();
        $kaynak = $this->yerelDosya(16);
        foreach (['erp_a.dump', 'erp_b.gpg', 'baska.txt'] as $ad) {
            $baglayici->yaz($kaynak, $ad);
        }

        $liste = $baglayici->listele();
        sort($liste);

        $this->assertSame(['erp_a.dump', 'erp_b.gpg'], $liste);
    }
}
