<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefKatalogu;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\Hedefler\NasBaglayicisi;
use App\Services\Yedekleme\HedefTuru;
use Closure;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * BY-023: NAS hedefi — paylaşım bağlı değilken bağlantı noktasındaki yerel
 * klasöre yazılıp "kopyalandı" sayılmaz. Bağlı paylaşım yerine gerçek bir
 * bağlantı noktası olan /dev/shm (tmpfs) kullanılır.
 */
final class NasBaglayicisiTest extends TestCase
{
    private const BAGLI_NOKTA = '/dev/shm';

    private string $yerelKok;

    private ?string $bagliKlasor = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yerelKok = sys_get_temp_dir().'/emor-nas-test-'.bin2hex(random_bytes(5));
        mkdir($this->yerelKok, 0700);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->yerelKok);
        if ($this->bagliKlasor !== null) {
            File::deleteDirectory($this->bagliKlasor);
        }
        parent::tearDown();
    }

    private function baglayici(string $nokta, string $klasor): NasBaglayicisi
    {
        $hedef = YedekHedefi::factory()->make([
            'tur' => HedefTuru::Nas,
            'baglanti' => ['baglanti_noktasi' => $nokta, 'klasor' => $klasor],
        ]);

        $baglayici = app(HedefKatalogu::class)->olustur($hedef);
        $this->assertInstanceOf(NasBaglayicisi::class, $baglayici);

        return $baglayici;
    }

    /** /dev/shm'de bu teste ait klasör; ortamda bağlantı noktası değilse test atlanır (T11) */
    private function bagliKlasor(): string
    {
        $nokta = @stat(self::BAGLI_NOKTA);
        $ust = @stat(dirname(self::BAGLI_NOKTA));

        if ($nokta === false || $ust === false || $nokta['dev'] === $ust['dev'] || ! is_writable(self::BAGLI_NOKTA)) {
            $this->markTestSkipped('/dev/shm bu ortamda yazılabilir bir bağlantı noktası değil.');
        }

        $this->bagliKlasor = self::BAGLI_NOKTA.'/emor-nas-test-'.bin2hex(random_bytes(5));
        mkdir($this->bagliKlasor, 0700);

        return $this->bagliKlasor;
    }

    private function hataBekle(Closure $islem): HedefHatasi
    {
        try {
            $islem();
        } catch (HedefHatasi $hata) {
            return $hata;
        }

        $this->fail('HedefHatasi bekleniyordu.');
    }

    public function test_paylasim_bagli_degilken_yazilmaz_ve_yerel_klasore_dosya_birakilmaz(): void
    {
        $nokta = $this->yerelKok.'/nas';
        mkdir($nokta.'/yedek', 0700, true);
        $kaynak = $this->yerelKok.'/kaynak.dump';
        file_put_contents($kaynak, 'PGDMP-yedek');

        $hata = $this->hataBekle(fn () => $this->baglayici($nokta, $nokta.'/yedek')->yaz($kaynak, 'erp_20261121.dump.gpg'));

        $this->assertSame(HedefHatasi::BAGLI_DEGIL, $hata->kod);
        $this->assertSame(['.', '..'], scandir($nokta.'/yedek'));
    }

    public function test_bagli_paylasimda_yaz_oku_dogrula_sil_dongusu_gecer_ve_liste_yalniz_yedekleri_gosterir(): void
    {
        $klasor = $this->bagliKlasor();
        file_put_contents($klasor.'/notlar.txt', 'yedek degil');
        $baglayici = $this->baglayici(self::BAGLI_NOKTA, $klasor);
        $kaynak = $this->yerelKok.'/kaynak.dump';
        file_put_contents($kaynak, 'PGDMP-yedek');

        $adimlar = app(HedefSinayici::class)->sina($baglayici);
        $kimlik = $baglayici->yaz($kaynak, 'erp_20261121.dump.gpg');

        $this->assertTrue(HedefSinayici::basariliMi($adimlar));
        $this->assertSame('erp_20261121.dump.gpg', $kimlik);
        $this->assertSame('PGDMP-yedek', file_get_contents($klasor.'/erp_20261121.dump.gpg'));
        $this->assertSame(['erp_20261121.dump.gpg'], $baglayici->listele());
    }

    public function test_klasor_baglantiyla_paylasim_disina_cikiyorsa_yazilmaz(): void
    {
        $bagliKlasor = $this->bagliKlasor();
        mkdir($this->yerelKok.'/yerel-disk', 0700);
        symlink($this->yerelKok.'/yerel-disk', $bagliKlasor.'/yedek');
        $kaynak = $this->yerelKok.'/kaynak.dump';
        file_put_contents($kaynak, 'PGDMP-yedek');

        $hata = $this->hataBekle(fn () => $this->baglayici(self::BAGLI_NOKTA, $bagliKlasor.'/yedek')->yaz($kaynak, 'erp_20261121.dump.gpg'));

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        $this->assertSame(['.', '..'], scandir($this->yerelKok.'/yerel-disk'));
    }
}
