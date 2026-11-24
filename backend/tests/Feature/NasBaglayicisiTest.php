<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use App\Services\Yedekleme\Hedefler\NasBaglayicisi;
use App\Services\Yedekleme\HedefTuru;
use Closure;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BY-023: NAS hedefi — paylaşım bağlı değilken bağlantı noktasındaki yerel
 * klasöre yazılıp "kopyalandı" sayılmaz. Bağlantı tablosu (mountinfo) sahtedir:
 * bağlı paylaşım, tabloda ağ dosya sistemi türüyle görünen geçici bir klasördür
 * (YF-07: /dev/shm gibi bellek dosya sistemi artık paylaşım sayılmıyor).
 */
final class NasBaglayicisiTest extends TestCase
{
    private string $yerelKok;

    private string $tablo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->yerelKok = sys_get_temp_dir().'/emor-nas-test-'.bin2hex(random_bytes(5));
        mkdir($this->yerelKok, 0700);
        $this->tablo = $this->yerelKok.'/mountinfo';
        file_put_contents($this->tablo, "22 1 0:21 / /proc rw,nosuid shared:12 - proc proc rw\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->yerelKok);
        parent::tearDown();
    }

    private function baglayici(string $nokta, string $klasor): NasBaglayicisi
    {
        $hedef = YedekHedefi::factory()->make([
            'tur' => HedefTuru::Nas,
            'baglanti' => ['baglanti_noktasi' => $nokta, 'klasor' => $klasor],
        ]);

        return app()->make(NasBaglayicisi::class, ['hedef' => $hedef, 'baglantiTablosu' => $this->tablo]);
    }

    /** Bağlantı tablosunda $tur türüyle bağlı görünen nokta; içinde bu teste ait klasör */
    private function bagliNokta(string $tur = 'nfs4'): string
    {
        $nokta = $this->yerelKok.'/nas paylasimi';
        mkdir($nokta.'/yedek', 0700, true);
        // mountinfo(5): boşluk sekizlik kaçışla; isteğe bağlı alanlar "-" ile biter
        file_put_contents($this->tablo, '36 25 0:50 / '.str_replace(' ', '\\040', $nokta)." rw,relatime shared:7 master:1 - {$tur} nas.ornek.test:/yedek rw,vers=4.2\n", FILE_APPEND);

        return $nokta;
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

    private function kaynak(): string
    {
        file_put_contents($this->yerelKok.'/kaynak.dump', 'PGDMP-yedek');

        return $this->yerelKok.'/kaynak.dump';
    }

    public function test_paylasim_bagli_degilken_yazilmaz_ve_yerel_klasore_dosya_birakilmaz(): void
    {
        $nokta = $this->yerelKok.'/nas';
        mkdir($nokta.'/yedek', 0700, true);

        $hata = $this->hataBekle(fn () => $this->baglayici($nokta, $nokta.'/yedek')->yaz($this->kaynak(), 'erp_20261121.dump.gpg'));

        $this->assertSame(HedefHatasi::BAGLI_DEGIL, $hata->kod);
        $this->assertSame(['.', '..'], scandir($nokta.'/yedek'));
    }

    public function test_bagli_paylasimda_yaz_oku_dogrula_sil_dongusu_gecer_ve_liste_yalniz_yedekleri_gosterir(): void
    {
        $klasor = $this->bagliNokta().'/yedek';
        file_put_contents($klasor.'/notlar.txt', 'yedek degil');
        $baglayici = $this->baglayici($this->yerelKok.'/nas paylasimi', $klasor);

        $adimlar = app(HedefSinayici::class)->sina($baglayici);
        $kimlik = $baglayici->yaz($this->kaynak(), 'erp_20261121.dump.gpg');

        $this->assertTrue(HedefSinayici::basariliMi($adimlar));
        $this->assertSame('erp_20261121.dump.gpg', $kimlik);
        $this->assertSame('PGDMP-yedek', file_get_contents($klasor.'/erp_20261121.dump.gpg'));
        $this->assertSame(['erp_20261121.dump.gpg'], $baglayici->listele());
    }

    public function test_klasor_baglantiyla_paylasim_disina_cikiyorsa_yazilmaz(): void
    {
        $nokta = $this->bagliNokta();
        mkdir($this->yerelKok.'/yerel-disk', 0700);
        symlink($this->yerelKok.'/yerel-disk', $nokta.'/baglanti');

        $hata = $this->hataBekle(fn () => $this->baglayici($nokta, $nokta.'/baglanti')->yaz($this->kaynak(), 'erp_20261121.dump.gpg'));

        $this->assertSame(HedefHatasi::YAPILANDIRMA, $hata->kod);
        $this->assertSame(['.', '..'], scandir($this->yerelKok.'/yerel-disk'));
    }

    /** @return iterable<string, array{string}> */
    public static function yerelDosyaSistemleri(): iterable
    {
        foreach (['tmpfs', 'devtmpfs', 'proc', 'sysfs', 'overlay', 'ramfs'] as $tur) {
            yield $tur => [$tur];
        }
    }

    /**
     * YF-07: /dev/shm, /run gibi bellek ya da çekirdek dosya sistemi bağlantı
     * noktasıdır ama paylaşım değildir: kopya "sunucu dışı" sayılır, saklama
     * yerel arşivi silebilir, yeniden başlatmada hepsi giderdi.
     */
    #[DataProvider('yerelDosyaSistemleri')]
    public function test_bellek_ya_da_cekirdek_dosya_sistemi_paylasim_sayilmaz_yazilmaz(string $tur): void
    {
        $nokta = $this->bagliNokta($tur);

        $hata = $this->hataBekle(fn () => $this->baglayici($nokta, $nokta.'/yedek')->yaz($this->kaynak(), 'erp_20261121.dump.gpg'));

        $this->assertSame(HedefHatasi::BAGLI_DEGIL, $hata->kod);
        $this->assertSame(['.', '..'], scandir($nokta.'/yedek'));
    }
}
