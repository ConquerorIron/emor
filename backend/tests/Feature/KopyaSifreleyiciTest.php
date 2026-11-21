<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Yedek;
use App\Models\YedekAyari;
use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\KopyaDurumu;
use App\Services\Yedekleme\KopyaSifreleyici;
use App\Services\Yedekleme\SifrelemeHatasi;
use App\Services\Yedekleme\YedekKopyalayici;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Sleep;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Yedekleme\BellekHedefi;
use Tests\Yedekleme\GpgAnahtarlari;
use Tests\Yedekleme\YedeklemeOrtami;

/**
 * BY-019: sunucu dışı kopyaların gpg ile şifrelenmesi ve geri yüklemede
 * çözülmesi (README §4.7, §4.6). Gerçek gpg ile; yoksa atlanır (T11).
 */
final class KopyaSifreleyiciTest extends TestCase
{
    use GpgAnahtarlari;
    use LazilyRefreshDatabase;
    use YedeklemeOrtami;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gpgAnahtarlariniHazirla();
        $this->yedeklemeOrtaminiKur();
        BellekHedefi::sifirla();
        Sleep::fake();
        app(HedefKatalogu::class)->kaydet(HedefTuru::S3, BellekHedefi::class);
    }

    protected function tearDown(): void
    {
        $this->yedeklemeOrtaminiKaldir();
        parent::tearDown();
    }

    private function sifreleyici(): KopyaSifreleyici
    {
        return app(KopyaSifreleyici::class);
    }

    /** Anahtarı ayarlar ekranının yapacağı gibi doğrulayıp kaydeder */
    private function anahtariKaydet(string $ad): void
    {
        $bilgi = $this->sifreleyici()->acikAnahtarBilgisi(self::$anahtarlar[$ad]['acik']);

        YedekAyari::query()->create([
            'sifreleme_acik_anahtari' => self::$anahtarlar[$ad]['acik'],
            'sifreleme_parmak_izi' => $bilgi['parmak_izi'],
            'sifreleme_anahtar_kullanicisi' => $bilgi['kullanici'],
            'sifreleme_anahtar_bitisi' => $bilgi['bitis'],
        ]);
    }

    private function dosya(string $ad, string $icerik): string
    {
        $yol = $this->yedekKoku.'/'.$ad;
        file_put_contents($yol, $icerik);

        return $yol;
    }

    /** @return list<string> */
    private function geciciAnahtarliklar(): array
    {
        return glob(sys_get_temp_dir().'/emor-gpg-[0-9a-f]*') ?: [];
    }

    private function hataKodu(Closure $is): ?string
    {
        try {
            $is();
        } catch (SifrelemeHatasi|HedefHatasi $hata) {
            return $hata->kod;
        }

        return null;
    }

    public function test_acik_anahtarla_sifrelenen_dosya_ozel_anahtarla_cozulur_gecici_anahtarlik_kalmaz(): void
    {
        $this->anahtariKaydet('a');
        $icerik = 'PGDMP-'.str_repeat('gizli veri ', 1000);
        $once = $this->geciciAnahtarliklar();

        $sifreli = $this->sifreleyici()->sifrele($this->dosya('yedek.dump', $icerik), $this->yedekKoku.'/yedek.dump.gpg');
        $this->sifreleyici()->coz($sifreli, $this->yedekKoku.'/cozulen.dump', $this->ozelAnahtarDosyasi('a', $this->yedekKoku));

        $this->assertStringNotContainsString('gizli veri', (string) file_get_contents($sifreli));
        $this->assertSame($icerik, file_get_contents($this->yedekKoku.'/cozulen.dump'));
        $this->assertSame([], array_values(array_diff($this->geciciAnahtarliklar(), $once)));
    }

    public function test_acik_anahtar_bilgisi_parmak_izini_kullaniciyi_ve_bitisi_dondurur(): void
    {
        $bilgi = $this->sifreleyici()->acikAnahtarBilgisi("\n".self::$anahtarlar['a']['acik']."\n");

        $this->assertSame(self::$anahtarlar['a']['parmak_izi'], $bilgi['parmak_izi']);
        $this->assertSame('Deneme a <a@example.invalid>', $bilgi['kullanici']);
        $this->assertNull($bilgi['bitis']);
    }

    /**
     * @return array<string, array{Closure(): string, string}>
     */
    public static function reddedilenMetinler(): array
    {
        $acik = fn (string $ad): string => self::$anahtarlar[$ad]['acik'];
        $ozel = fn (string $ad): string => self::$anahtarlar[$ad]['ozel'];

        return [
            'yalnız özel anahtar' => [fn () => $ozel('a'), SifrelemeHatasi::OZEL_ANAHTAR],
            'açık + özel anahtar' => [fn () => $acik('a')."\n".$ozel('a'), SifrelemeHatasi::OZEL_ANAHTAR],
            // Başlık "PUBLIC" ama içi özel anahtar paketleri: metin denetimi geçer, gpg listesi yakalar
            'açık başlıklı özel anahtar' => [fn () => str_replace('PRIVATE KEY BLOCK', 'PUBLIC KEY BLOCK', $ozel('a')), SifrelemeHatasi::OZEL_ANAHTAR],
            'iki ayrı blok' => [fn () => $acik('a')."\n".$acik('b'), SifrelemeHatasi::ANAHTAR_GECERSIZ],
            'bozuk blok' => [fn () => "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nbozukveri==\n-----END PGP PUBLIC KEY BLOCK-----", SifrelemeHatasi::ANAHTAR_GECERSIZ],
            'boş' => [fn () => '', SifrelemeHatasi::ANAHTAR_GECERSIZ],
            'süresi dolmuş' => [fn () => $acik('eski'), SifrelemeHatasi::SURESI_DOLMUS],
        ];
    }

    /**
     * @param  Closure(): string  $metin
     */
    #[DataProvider('reddedilenMetinler')]
    public function test_tek_gecerli_acik_anahtar_olmayan_metin_reddedilir(Closure $metin, string $kod): void
    {
        $this->assertSame($kod, $this->hataKodu(fn () => $this->sifreleyici()->acikAnahtarBilgisi($metin())));
    }

    public function test_tek_blokta_iki_anahtar_reddedilir(): void
    {
        $ikisi = (new Process(['/usr/bin/gpg', '--homedir', self::$anahtarEvi, '--batch', '--armor', '--export', self::$anahtarlar['a']['parmak_izi'], self::$anahtarlar['b']['parmak_izi']]))->mustRun()->getOutput();

        $this->assertSame(SifrelemeHatasi::ANAHTAR_GECERSIZ, $this->hataKodu(fn () => $this->sifreleyici()->acikAnahtarBilgisi($ikisi)));
    }

    /** Yükleme doğrulamasını atlayan yazım da özel anahtarı veritabanına koyamaz (K3) */
    public function test_ozel_anahtar_yedek_ayarlarina_kaydedilemez(): void
    {
        $this->expectException(LogicException::class);

        try {
            YedekAyari::query()->create(['sifreleme_acik_anahtari' => self::$anahtarlar['a']['ozel']]);
        } finally {
            $this->assertSame(0, YedekAyari::query()->count());
        }
    }

    public function test_acik_anahtar_tanimli_degilken_sifreleme_hata_verir_cikti_yazilmaz(): void
    {
        $kod = $this->hataKodu(fn () => $this->sifreleyici()->sifrele($this->dosya('yedek.dump', 'PGDMP'), $this->yedekKoku.'/yedek.dump.gpg'));

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $kod);
        $this->assertFileDoesNotExist($this->yedekKoku.'/yedek.dump.gpg');
    }

    public function test_kayitli_parmak_izi_anahtarla_uyusmazsa_sifrelemez(): void
    {
        YedekAyari::query()->create([
            'sifreleme_acik_anahtari' => self::$anahtarlar['a']['acik'],
            'sifreleme_parmak_izi' => self::$anahtarlar['b']['parmak_izi'],
        ]);

        $kod = $this->hataKodu(fn () => $this->sifreleyici()->sifrele($this->dosya('yedek.dump', 'PGDMP'), $this->yedekKoku.'/yedek.dump.gpg'));

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $kod);
        $this->assertFileDoesNotExist($this->yedekKoku.'/yedek.dump.gpg');
    }

    public function test_yanlis_anahtarla_cozme_anlasilir_hata_verir_dosya_birakmaz(): void
    {
        $this->anahtariKaydet('a');
        $sifreli = $this->sifreleyici()->sifrele($this->dosya('yedek.dump', 'PGDMP-icerik'), $this->yedekKoku.'/yedek.dump.gpg');
        $cikis = $this->yedekKoku.'/cikis';
        mkdir($cikis, 0700);

        $kod = $this->hataKodu(fn () => $this->sifreleyici()->coz($sifreli, $cikis.'/yedek.dump', $this->ozelAnahtarDosyasi('b', $this->yedekKoku)));

        $this->assertSame(SifrelemeHatasi::COZULEMEDI, $kod);
        $this->assertSame([], array_values(array_diff(scandir($cikis) ?: [], ['.', '..'])));
    }

    /** Bozuk şifreli dosyada gpg çözdüğü kısmı yazar; o yarım çıktı kalmamalı (D3) */
    public function test_bozuk_sifreli_dosya_cozulemez_yarim_cikti_kalmaz(): void
    {
        $this->anahtariKaydet('a');
        $sifreli = $this->sifreleyici()->sifrele($this->dosya('yedek.dump', random_bytes(200_000)), $this->yedekKoku.'/yedek.dump.gpg');
        $icerik = (string) file_get_contents($sifreli);
        file_put_contents($sifreli, substr($icerik, 0, intdiv(strlen($icerik), 2)));
        $cikis = $this->yedekKoku.'/cikis';
        mkdir($cikis, 0700);

        $kod = $this->hataKodu(fn () => $this->sifreleyici()->coz($sifreli, $cikis.'/yedek.dump', $this->ozelAnahtarDosyasi('a', $this->yedekKoku)));

        $this->assertSame(SifrelemeHatasi::COZULEMEDI, $kod);
        $this->assertSame([], array_values(array_diff(scandir($cikis) ?: [], ['.', '..'])));
    }

    public function test_parolali_anahtar_yalniz_dogru_parola_dosyasiyla_cozulur(): void
    {
        $this->anahtariKaydet('p');
        $sifreli = $this->sifreleyici()->sifrele($this->dosya('yedek.dump', 'PGDMP-parolali'), $this->yedekKoku.'/yedek.dump.gpg');
        $anahtar = $this->ozelAnahtarDosyasi('p', $this->yedekKoku);
        $yanlis = $this->dosya('yanlis-parola', "baska parola\n");
        $dogru = $this->dosya('dogru-parola', self::TEST_PAROLASI."\n");

        $this->assertSame(SifrelemeHatasi::PAROLA, $this->hataKodu(fn () => $this->sifreleyici()->coz($sifreli, $this->yedekKoku.'/a.dump', $anahtar)));
        $this->assertSame(SifrelemeHatasi::PAROLA, $this->hataKodu(fn () => $this->sifreleyici()->coz($sifreli, $this->yedekKoku.'/a.dump', $anahtar, $yanlis)));
        $this->assertFileDoesNotExist($this->yedekKoku.'/a.dump');

        $this->sifreleyici()->coz($sifreli, $this->yedekKoku.'/a.dump', $anahtar, $dogru);
        $this->assertSame('PGDMP-parolali', file_get_contents($this->yedekKoku.'/a.dump'));
    }

    public function test_acik_anahtar_ozel_anahtar_yerine_verilirse_cozmez(): void
    {
        $this->anahtariKaydet('a');
        $sifreli = $this->sifreleyici()->sifrele($this->dosya('yedek.dump', 'PGDMP'), $this->yedekKoku.'/yedek.dump.gpg');
        $acik = $this->dosya('acik.asc', self::$anahtarlar['a']['acik']);

        $this->assertSame(SifrelemeHatasi::OZEL_ANAHTAR_OKUNAMADI, $this->hataKodu(fn () => $this->sifreleyici()->coz($sifreli, $this->yedekKoku.'/a.dump', $acik)));
    }

    /** Kabul ölçütü: şifreli kopyanın özeti kayıtlı; uzak dosya onunla doğrulanır ve çözülür */
    public function test_sunucu_disi_kopya_sifreli_gider_ozeti_uzak_dosyayla_dogrulanir(): void
    {
        $this->anahtariKaydet('a');
        BellekHedefi::$sunucuDisi = true;
        $icerik = 'PGDMP-uzak-kopya';
        $yedek = Yedek::factory()->create(['sha256' => hash('sha256', $icerik), 'boyut' => strlen($icerik)]);
        @mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(config('yedekleme.klasor').'/'.$yedek->dosya_adi, $icerik);
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);

        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);

        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertTrue($kopya->sifreli);
        $this->assertStringEndsWith($yedek->dosya_adi.'.gpg', $kopya->uzak_kimlik);
        $uzak = BellekHedefi::$dosyalar[$kopya->uzak_kimlik];
        $this->assertSame($kopya->sifreli_sha256, hash('sha256', $uzak));
        $this->assertNotSame($icerik, $uzak);

        file_put_contents($this->yedekKoku.'/indirilen.gpg', $uzak);
        $this->sifreleyici()->coz($this->yedekKoku.'/indirilen.gpg', $this->yedekKoku.'/indirilen.dump', $this->ozelAnahtarDosyasi('a', $this->yedekKoku));
        $this->assertSame($yedek->sha256, hash_file('sha256', $this->yedekKoku.'/indirilen.dump'));
    }

    /**
     * BY-019-R1: gpg'nin süre aşımı geçicidir (D8 yalnız kendiliğinden
     * düzelmeyeni keser): kopya yeniden denenir ve ikinci denemede yalnız
     * şifreli dosya gider. Anahtar yokken tek deneme:
     * YedekKopyalayiciTest::test_sunucu_disi_hedefe_sifreleme_yokken_kopya_gitmez.
     */
    public function test_gpg_sure_asiminda_kopya_yeniden_denenir_yalniz_sifreli_dosya_gider(): void
    {
        $this->anahtariKaydet('a');
        BellekHedefi::$sunucuDisi = true;
        // İlk şifreleme çağrısı süre sınırını aşar (asılı gpg), sonrakiler gerçek gpg
        $isaret = $this->yedekKoku.'/ilk-sifreleme-asildi';
        $sarmalayici = $this->dosya('gpg-sarmalayici', "#!/bin/sh\nfor a in \"\$@\"; do\n  if [ \"\$a\" = --encrypt ] && [ ! -e '{$isaret}' ]; then : > '{$isaret}'; exec sleep 5; fi\ndone\nexec /usr/bin/gpg \"\$@\"\n");
        chmod($sarmalayici, 0700);
        config(['yedekleme.gpg' => $sarmalayici, 'yedekleme.sure_siniri.sifreleme' => 1]);
        $icerik = 'PGDMP-sure-asimi';
        $yedek = Yedek::factory()->create(['sha256' => hash('sha256', $icerik), 'boyut' => strlen($icerik)]);
        @mkdir(config('yedekleme.klasor'), 0750, true);
        file_put_contents(config('yedekleme.klasor').'/'.$yedek->dosya_adi, $icerik);
        $hedef = YedekHedefi::factory()->create(['tur' => HedefTuru::S3, 'baglanti' => ['kova' => 'k']]);

        $kopya = app(YedekKopyalayici::class)->kopyala($yedek, $hedef);

        $this->assertFileExists($isaret);
        $this->assertSame(KopyaDurumu::Tamam, $kopya->durum);
        $this->assertSame(2, $kopya->deneme);
        $this->assertSame($hedef->id.'/'.$yedek->dosya_adi.'.gpg', $kopya->uzak_kimlik);
        $this->assertArrayNotHasKey($hedef->id.'/'.$yedek->dosya_adi, BellekHedefi::$dosyalar);
        $this->assertNotContains($icerik, BellekHedefi::$dosyalar);
        $this->assertSame($kopya->sifreli_sha256, hash('sha256', BellekHedefi::$dosyalar[$kopya->uzak_kimlik]));
    }

    public function test_sifre_coz_komutu_dosyayi_verilen_anahtarla_cozer(): void
    {
        $this->anahtariKaydet('a');
        $sifreli = $this->sifreleyici()->sifrele($this->dosya('yedek.dump', 'PGDMP-komut'), $this->yedekKoku.'/yedek.dump.gpg');

        $this->artisan('yedek:sifre-coz', [
            'sifreli' => $sifreli,
            'cikis' => $this->yedekKoku.'/komut.dump',
            '--anahtar' => $this->ozelAnahtarDosyasi('a', $this->yedekKoku),
        ])->assertSuccessful();

        $this->assertSame('PGDMP-komut', file_get_contents($this->yedekKoku.'/komut.dump'));
        $this->artisan('yedek:sifre-coz', [
            'sifreli' => $sifreli,
            'cikis' => $this->yedekKoku.'/yanlis.dump',
            '--anahtar' => $this->ozelAnahtarDosyasi('b', $this->yedekKoku),
        ])->expectsOutputToContain(SifrelemeHatasi::COZULEMEDI)->assertFailed();
    }
}
