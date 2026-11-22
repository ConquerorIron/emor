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
use Tests\TestCase;

/**
 * BY-022 kabulü: SFTP bağlayıcısı GERÇEK OpenSSH sunucusuna (sshd, ayrı
 * port) karşı. Sunucu `scripts/tests/gercek-hedef-sunuculari.sh kur` ile
 * yerelde kurulur (yalnız 127.0.0.1). Yalnız açıkça istenince çalışır:
 *
 *   source ~/.cache/emor-yedek-deneme/ortam.sh
 *   php artisan test --compact tests/Feature/SftpBaglayicisiGercekSunucuTest.php
 *
 * Kesinti testi 2 MB/sn sınırlı porttaki oturumu `sudo -n pkill` ile öldürür.
 */
final class SftpBaglayicisiGercekSunucuTest extends TestCase
{
    private string $kok;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('YEDEKLEME_GERCEK_SFTP') !== '1') {
            $this->markTestSkipped('Gerçek SFTP testi: YEDEKLEME_GERCEK_SFTP=1 ile çalışır (scripts/tests/gercek-hedef-sunuculari.sh kur).');
        }

        // Önek, bağlayıcının sır klasörü deseniyle (emor-sftp-*) çakışmaz
        $this->kok = sys_get_temp_dir().'/emor-gercek-sftp-'.bin2hex(random_bytes(5));
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

    /**
     * Kaydedilmemiş hedef; sunucu anahtarı ilk baglan()'da modele yazılır.
     *
     * @param  array<string, mixed>  $baglanti
     * @param  array<string, string>|null  $gizli
     */
    private function hedef(array $baglanti = [], ?array $gizli = null): YedekHedefi
    {
        return YedekHedefi::factory()->make([
            'ad' => 'SFTP gerçek',
            'tur' => HedefTuru::Sftp,
            'baglanti' => $baglanti + [
                'sunucu' => $this->ortam('YEDEKLEME_SFTP_SUNUCU'),
                'port' => (int) $this->ortam('YEDEKLEME_SFTP_PORT'),
                'kullanici_adi' => $this->ortam('YEDEKLEME_SFTP_KULLANICI'),
                'klasor' => 'yedekler',
            ],
            'gizli_bilgiler' => $gizli ?? ['sifre' => $this->ortam('YEDEKLEME_SFTP_SIFRE')],
        ]);
    }

    private function baglayici(YedekHedefi $hedef): HedefBaglayicisi
    {
        return app(HedefKatalogu::class)->olustur($hedef);
    }

    /**
     * Sunucu anahtarı kayıtlı (ilk Bağlantıyı Sına yapılmış) bağlayıcı.
     *
     * @param  array<string, mixed>  $baglanti
     */
    private function sinanmis(array $baglanti = []): HedefBaglayicisi
    {
        $baglayici = $this->baglayici($this->hedef($baglanti));
        $baglayici->baglan();

        return $baglayici;
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

    /** @return list<string> Sunucudaki düz dosyalar (geçici adlılar dahil) */
    private function sunucudakiDosyalar(string $klasor = 'yedekler'): array
    {
        $yol = $this->ortam('YEDEKLEME_SFTP_EV').'/'.$klasor;

        return array_values(array_filter(scandir($yol) ?: [], fn (string $ad): bool => is_file($yol.'/'.$ad)));
    }

    private function sunucudakiDenemeDosyalariniTemizle(): void
    {
        foreach (['yedekler', 'kucuk'] as $klasor) {
            $dosyalar = $this->sunucudakiDosyalar($klasor);
            if ($dosyalar === []) {
                continue;
            }
            $baglayici = $this->sinanmis(['klasor' => $klasor]);
            foreach ($dosyalar as $ad) {
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

    private function gunlukSonu(): int
    {
        clearstatcache();

        return (int) filesize($this->ortam('YEDEKLEME_HEDEF_GUNLUK').'/sshd.log');
    }

    private function yeniGunluk(int $baslangic): string
    {
        clearstatcache();

        return (string) file_get_contents($this->ortam('YEDEKLEME_HEDEF_GUNLUK').'/sshd.log', offset: $baslangic);
    }

    /** @return list<string> Bu sürecin açtığı sır klasörleri */
    private function sirKlasorleri(): array
    {
        return glob(rtrim(sys_get_temp_dir(), '/').'/emor-sftp-*', GLOB_ONLYDIR) ?: [];
    }

    private function kesiciBaslat(string $izlenenKlasor, string $desen, string $sinyal = 'KILL'): InvokedProcess
    {
        if (Process::run(['sudo', '-n', 'true'])->failed()) {
            $this->markTestSkipped('Kesinti testi sudo -n ister (sunucu oturumunu öldürmek için).');
        }

        $betik = 'for i in $(seq 1 400); do f=$(find "$1" -maxdepth 1 -name "$2" -size +1M 2>/dev/null | head -n 1); '
            .'if [ -n "$f" ]; then sudo -n pkill -"$4" -u "$3"; echo kesildi; exit 0; fi; sleep 0.05; done; exit 1';

        return Process::timeout(60)->start(['bash', '-c', $betik, 'kesici', $izlenenKlasor, $desen, $this->ortam('YEDEKLEME_SFTP_KULLANICI'), $sinyal]);
    }

    public function test_ilk_sinamada_sunucunun_gercek_anahtari_kaydedilir_ve_dongu_gecer(): void
    {
        $hedef = $this->hedef();
        $sunucuAnahtari = $this->kok.'/sunucu.pub';
        file_put_contents($sunucuAnahtari, $this->ortam('YEDEKLEME_SFTP_SUNUCU_ANAHTARI')."\n");
        // ssh-keygen -lf çıktısı: "256 SHA256:… yorum (ED25519)"
        $beklenenIz = explode(' ', Process::run(['ssh-keygen', '-lf', $sunucuAnahtari])->throw()->output())[1];

        $sonuc = app(HedefSinayici::class)->sonuc($this->baglayici($hedef));

        $this->assertTrue($sonuc['basarili'], json_encode($sonuc['adimlar'], JSON_UNESCAPED_UNICODE));
        $this->assertSame($this->ortam('YEDEKLEME_SFTP_SUNUCU_ANAHTARI'), $hedef->baglanti['sunucu_anahtari']);
        $this->assertSame($beklenenIz, $hedef->baglanti['sunucu_parmak_izi']);
        $this->assertSame([], $this->sunucudakiDosyalar(), 'Sınama hedefte dosya bırakmamalı');
        $bos = disk_free_space($this->ortam('YEDEKLEME_SFTP_EV').'/yedekler');
        $this->assertIsInt($sonuc['kalan_alan']);
        $this->assertEqualsWithDelta($bos, $sonuc['kalan_alan'], 256 * 1048576, 'df, sunucudaki boş alanı vermeli');
    }

    public function test_parolayla_50_mb_dosya_yazilir_listelenir_ayni_baytlarla_okunur_ve_silinir(): void
    {
        $baglayici = $this->sinanmis();
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
    }

    public function test_ozel_anahtarla_parolasiz_sinama_dongusu_gecer(): void
    {
        $hedef = $this->hedef(gizli: ['ozel_anahtar' => (string) file_get_contents($this->ortam('YEDEKLEME_SFTP_ANAHTAR'))]);
        $once = $this->gunlukSonu();

        $sonuc = app(HedefSinayici::class)->sonuc($this->baglayici($hedef));

        $this->assertTrue($sonuc['basarili'], json_encode($sonuc['adimlar'], JSON_UNESCAPED_UNICODE));
        $gunluk = $this->yeniGunluk($once);
        $this->assertStringContainsString('Accepted publickey for '.$this->ortam('YEDEKLEME_SFTP_KULLANICI'), $gunluk);
        $this->assertStringNotContainsString('Accepted password', $gunluk);
    }

    public function test_windows_satir_sonlu_ozel_anahtar_da_kabul_edilir(): void
    {
        $anahtar = str_replace("\n", "\r\n", (string) file_get_contents($this->ortam('YEDEKLEME_SFTP_ANAHTAR')));

        $this->baglayici($this->hedef(gizli: ['ozel_anahtar' => $anahtar]))->baglan();

        $this->addToAssertionCount(1);
    }

    public function test_parolali_ozel_anahtar_desteklenmez_kimlik_hatasi_verir(): void
    {
        $hedef = $this->hedef(gizli: ['ozel_anahtar' => (string) file_get_contents($this->ortam('YEDEKLEME_SFTP_PAROLALI_ANAHTAR'))]);

        $hata = $this->hata(fn () => $this->baglayici($hedef)->baglan());

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
    }

    public function test_sunucu_anahtari_degisince_reddedilir_ve_kayitli_anahtar_degismez(): void
    {
        $hedef = $this->hedef();
        $this->baglayici($hedef)->baglan();
        // Kayıtlı anahtar başka bir makinenin: sunucu "değişmiş" görünür
        $yabanci = $this->kok.'/yabanci';
        Process::run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', $yabanci])->throw();
        $yabanciAnahtar = implode(' ', array_slice(explode(' ', trim((string) file_get_contents($yabanci.'.pub'))), 0, 2));
        $hedef->baglanti = ['sunucu_anahtari' => $yabanciAnahtar, 'sunucu_parmak_izi' => 'SHA256:kayitli'] + $hedef->baglanti;
        $once = $this->gunlukSonu();

        $baglan = $this->hata(fn () => $this->baglayici($hedef)->baglan());
        $yaz = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya(10), 'erp_a.dump'));

        $this->assertSame(HedefHatasi::SUNUCU_PARMAK_IZI, $baglan->kod);
        $this->assertStringContainsString('SHA256:kayitli', $baglan->getMessage());
        $this->assertSame(HedefHatasi::SUNUCU_PARMAK_IZI, $yaz->kod);
        $this->assertSame($yabanciAnahtar, $hedef->baglanti['sunucu_anahtari']);
        $this->assertStringNotContainsString('Failed password', $this->yeniGunluk($once), 'Parola, doğrulanmayan sunucuya gönderilmemeli');
        $this->assertSame([], $this->sunucudakiDosyalar());
    }

    public function test_hatali_parola_tek_giris_denemesiyle_kimlik_hatasi_verir_mesajda_sir_yok(): void
    {
        $hedef = $this->hedef();
        $this->baglayici($hedef)->baglan();
        $yanlis = 'Yanlis "parola" $x 1';
        $hedef->gizli_bilgiler = ['sifre' => $yanlis];
        $once = $this->gunlukSonu();

        $baglan = $this->hata(fn () => $this->baglayici($hedef)->baglan());
        $yaz = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya(10), 'erp_a.dump'));

        $this->assertSame(HedefHatasi::KIMLIK, $baglan->kod);
        $this->assertSame(HedefHatasi::KIMLIK, $yaz->kod);
        $this->assertStringNotContainsString($yanlis, $baglan->getMessage());
        // İki işlem: her biri tek giriş denemesi (yazmada temizlik için ikinci bağlantı yok; D8)
        $this->assertSame(2, substr_count($this->yeniGunluk($once), 'Failed password for '.$this->ortam('YEDEKLEME_SFTP_KULLANICI')));
    }

    public function test_hatali_kullanici_kimlik_hatasi_verir(): void
    {
        $hata = $this->hata(fn () => $this->baglayici($this->hedef(['kullanici_adi' => 'olmayan_kullanici']))->baglan());

        $this->assertSame(HedefHatasi::KIMLIK, $hata->kod);
        $this->assertStringNotContainsString($this->ortam('YEDEKLEME_SFTP_SIFRE'), $hata->getMessage());
    }

    public function test_kapali_port_ve_cozulemeyen_sunucu_erisilemedi_hatasi_verir(): void
    {
        $kapali = $this->hata(fn () => $this->baglayici($this->hedef(['port' => 2299]))->baglan());
        $cozulemeyen = $this->hata(fn () => $this->baglayici($this->hedef(['sunucu' => 'olmayan-sunucu.invalid']))->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $kapali->kod);
        $this->assertSame(HedefHatasi::ERISILEMEDI, $cozulemeyen->kod);
    }

    public function test_anahtari_kayitli_sunucu_kapaninca_erisilemedi_hatasi_verir(): void
    {
        $hedef = $this->hedef();
        $this->baglayici($hedef)->baglan();
        // Aynı makinede kapalı port: kayıt o porta taşınır (sunucu kapanmış gibi)
        $hedef->baglanti = ['port' => 2299, 'sunucu_anahtari_icin' => $hedef->baglanti['sunucu'].':2299'] + $hedef->baglanti;

        $hata = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya(10), 'erp_a.dump'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertStringContainsString('sunucuya bağlanılamadı', $hata->getMessage());
    }

    public function test_yanit_vermeyen_adres_baglanti_sure_sinirinda_birakilir(): void
    {
        config(['yedekleme.sure_siniri.baglanti' => 2]);
        $baslangic = hrtime(true);

        $hata = $this->hata(fn () => $this->baglayici($this->hedef(['sunucu' => '192.0.2.1']))->baglan());

        $this->assertSame(HedefHatasi::ERISILEMEDI, $hata->kod);
        // ssh-keyscan -T 2; süreç sınırı 2 × 2 + 5
        $this->assertLessThan(10.0, (hrtime(true) - $baslangic) / 1e9);
    }

    public function test_olmayan_klasor_acilmaz_baglanti_ve_yazma_hata_verir(): void
    {
        $hedef = $this->hedef(['klasor' => 'olmayan']);

        $baglan = $this->hata(fn () => $this->baglayici($hedef)->baglan());
        $yaz = $this->hata(fn () => $this->baglayici($hedef)->yaz($this->yerelDosya(10), 'erp_a.dump'));

        $this->assertSame(HedefHatasi::ERISILEMEDI, $baglan->kod);
        $this->assertSame(HedefHatasi::YAZILAMADI, $yaz->kod);
        $this->assertDirectoryDoesNotExist($this->ortam('YEDEKLEME_SFTP_EV').'/olmayan');
    }

    public function test_mutlak_klasore_yazar(): void
    {
        $this->sinanmis(['klasor' => $this->ortam('YEDEKLEME_SFTP_EV').'/yedekler/'])->yaz($this->yerelDosya(10), 'erp_a.dump');

        $this->assertSame(['erp_a.dump'], $this->sunucudakiDosyalar());
    }

    public function test_turkce_karakterli_ve_bosluklu_klasor_adi_baglanmadan_reddedilir(): void
    {
        $once = $this->gunlukSonu();

        $hata = $this->hata(fn () => $this->baglayici($this->hedef(['klasor' => 'Yedek Klasörü']))->baglan());

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        $this->assertSame('', $this->yeniGunluk($once), 'Sunucuya hiç bağlanılmamalı');
    }

    public function test_yazma_izni_olmayan_klasore_yazma_yazilamadi_hatasi_verir_ve_dosya_birakmaz(): void
    {
        $hata = $this->hata(fn () => $this->sinanmis(['klasor' => 'salt-okunur'])->yaz($this->yerelDosya(1024), 'erp_a.dump'));

        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->sunucudakiDosyalar('salt-okunur'));
    }

    public function test_sunucuda_yer_kalmazsa_yazma_basarisiz_olur_ve_yarim_dosya_kalmaz(): void
    {
        $baglayici = $this->sinanmis(['klasor' => 'kucuk']);

        $hata = $this->hata(fn () => $baglayici->yaz($this->yerelDosya(12 * 1048576), 'erp_a.dump'));

        // OpenSSH sftp-server ENOSPC'yi genel "Failure" (SSH2_FX_FAILURE) olarak bildirir: kota ayırt edilemez
        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->sunucudakiDosyalar('kucuk'), 'Ne asıl adda ne geçici adda dosya kalmalı');
    }

    public function test_yukleme_ortasinda_baglanti_koparsa_yarim_dosya_kalmaz_yeniden_deneme_tamamlanir(): void
    {
        $baglayici = $this->sinanmis(['port' => (int) $this->ortam('YEDEKLEME_SFTP_YAVAS_PORT')]);
        $kaynak = $this->yerelDosya(16 * 1048576);
        $ad = 'erp_elle_2026-11-22_101500+0300.dump';
        $kesici = $this->kesiciBaslat($this->ortam('YEDEKLEME_SFTP_EV').'/yedekler', '.erp_*.tmp');

        $hata = $this->hata(fn () => $baglayici->yaz($kaynak, $ad));

        $this->assertSame('kesildi', trim($kesici->wait()->output()), 'Kesici aktarımı yakalayamadı');
        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertSame([], $this->sunucudakiDosyalar(), 'Yarım dosya hedefte kalmamalı');

        $hizli = $this->sinanmis();
        $hizli->yaz($kaynak, $ad);
        $geri = $this->kok.'/geri';
        $hizli->oku($ad, $geri);
        $this->assertSame(hash_file('sha256', $kaynak), hash_file('sha256', $geri));
    }

    public function test_yukleme_ortasinda_asili_kalan_sunucu_kopya_sure_sinirinda_birakilir_ve_yarim_dosya_kalmaz(): void
    {
        $baglayici = $this->sinanmis(['port' => (int) $this->ortam('YEDEKLEME_SFTP_YAVAS_PORT')]);
        config(['yedekleme.sure_siniri.kopya' => 3]);
        // Oturum dondurulur (SIGSTOP): bağlantı açık kalır, veri akmaz
        $kesici = $this->kesiciBaslat($this->ortam('YEDEKLEME_SFTP_EV').'/yedekler', '.erp_*.tmp', 'STOP');
        $baslangic = hrtime(true);

        try {
            $hata = $this->hata(fn () => $baglayici->yaz($this->yerelDosya(16 * 1048576), 'erp_a.dump'));
            $sure = (hrtime(true) - $baslangic) / 1e9;
        } finally {
            Process::run(['sudo', '-n', 'pkill', '-KILL', '-u', $this->ortam('YEDEKLEME_SFTP_KULLANICI')]);
        }

        $this->assertSame('kesildi', trim($kesici->wait()->output()), 'Kesici aktarımı yakalayamadı');
        $this->assertSame(HedefHatasi::YAZILAMADI, $hata->kod);
        $this->assertStringContainsString('zaman aşımı', $hata->getMessage());
        $this->assertLessThan(8.0, $sure);
        $this->assertSame([], $this->sunucudakiDosyalar(), 'Geçici dosya yeni bağlantıyla silinmeli');
    }

    public function test_ayni_adla_ikinci_yazma_oncekinin_ustune_yazar(): void
    {
        $baglayici = $this->sinanmis();
        $baglayici->yaz($this->yerelDosya(2048), 'erp_a.dump');
        $ikinci = $this->yerelDosya(4096);

        $baglayici->yaz($ikinci, 'erp_a.dump');

        $geri = $this->kok.'/geri';
        $baglayici->oku('erp_a.dump', $geri);
        $this->assertSame(hash_file('sha256', $ikinci), hash_file('sha256', $geri));
        $this->assertSame(['erp_a.dump'], $this->sunucudakiDosyalar());
    }

    public function test_sir_komut_satirina_ve_ortama_girmez_gecici_dosyalar_is_bitince_kalmaz(): void
    {
        $sifre = $this->ortam('YEDEKLEME_SFTP_SIFRE');
        $anahtar = (string) file_get_contents($this->ortam('YEDEKLEME_SFTP_ANAHTAR'));
        $hedef = $this->hedef(['port' => (int) $this->ortam('YEDEKLEME_SFTP_YAVAS_PORT')], ['sifre' => $sifre, 'ozel_anahtar' => $anahtar]);
        $this->baglayici($hedef)->baglan();
        $onceki = $this->sirKlasorleri();
        $kayit = $this->kok.'/gozlem';
        // Aktarım sürerken bağlayıcının başlattığı sftp / ssh süreçlerinin komut satırı + ortamı ve sır klasörlerinin izinleri
        // (testi başlatan kabuk sırrı ortamında taşır; o gözlenmez)
        $betik = 'for i in $(seq 1 200); do for p in $(pgrep -u "$(id -u)" -x "sftp|ssh"); do '
            .'echo "SUREC $p"; tr "\0" " " < /proc/$p/cmdline; echo; tr "\0" "\n" < /proc/$p/environ; done >> "$1" 2>/dev/null; '
            .'stat -c "IZIN %a %n" "$2"/emor-sftp-*/ "$2"/emor-sftp-*/* >> "$1" 2>/dev/null; sleep 0.1; done';
        // Testin kendi ortamındaki sır bağlayıcının alt süreçlerine geçmesin (üretimde ortamda yok)
        putenv('YEDEKLEME_SFTP_SIFRE');
        unset($_ENV['YEDEKLEME_SFTP_SIFRE'], $_SERVER['YEDEKLEME_SFTP_SIFRE']);

        try {
            $gozlemci = Process::timeout(60)->start(['bash', '-c', $betik, 'gozlemci', $kayit, rtrim(sys_get_temp_dir(), '/')]);
            $this->baglayici($hedef)->yaz($this->yerelDosya(6 * 1048576), 'erp_a.dump');
            $gozlemci->signal(SIGTERM);
            $gozlemci->wait();
        } finally {
            putenv('YEDEKLEME_SFTP_SIFRE='.$sifre);
        }

        $gozlem = (string) file_get_contents($kayit);
        $this->assertStringContainsString('SUREC ', $gozlem, 'Gözlemci sftp sürecini yakalayamadı');
        $this->assertStringContainsString('SSH_ASKPASS=', $gozlem);
        $this->assertStringNotContainsString($sifre, $gozlem);
        $this->assertStringNotContainsString('PRIVATE KEY', $gozlem);
        preg_match_all('/^IZIN (\d+) (.+)$/m', $gozlem, $izinler, PREG_SET_ORDER);
        $this->assertNotEmpty($izinler);
        foreach ($izinler as [, $izin, $yol]) {
            $this->assertContains($izin, str_ends_with($yol, '/') || str_ends_with($yol, '/askpass') ? ['700'] : ['600'], $yol);
        }
        $this->assertSame($onceki, $this->sirKlasorleri(), 'Sır klasörü iş bitince silinmeli');
    }

    public function test_olmayan_dosyayi_silmek_hata_vermez_okumak_okunamadi_verir(): void
    {
        $baglayici = $this->sinanmis();

        $baglayici->sil('erp_olmayan.dump');
        $hata = $this->hata(fn () => $baglayici->oku('erp_olmayan.dump', $this->kok.'/geri'));

        $this->assertSame(HedefHatasi::OKUNAMADI, $hata->kod);
        $this->assertFileDoesNotExist($this->kok.'/geri');
    }

    public function test_listele_yalniz_yedek_dosyalarini_dondurur(): void
    {
        $baglayici = $this->sinanmis();
        $kaynak = $this->yerelDosya(16);
        foreach (['erp_a.dump', 'erp_b.gpg', 'baska.txt'] as $ad) {
            $baglayici->yaz($kaynak, $ad);
        }

        $liste = $baglayici->listele();
        sort($liste);

        $this->assertSame(['erp_a.dump', 'erp_b.gpg'], $liste);
    }
}
