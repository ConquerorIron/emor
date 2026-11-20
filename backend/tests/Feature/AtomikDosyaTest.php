<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Yedekleme\AtomikDosya;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * AtomikDosya: yarım hâli görünmeyen yazma ve kopyalama (Dikkat_Edilecekler D3).
 */
final class AtomikDosyaTest extends TestCase
{
    /** @var list<string> */
    private array $temizlenecek = [];

    protected function tearDown(): void
    {
        foreach ($this->temizlenecek as $yol) {
            File::deleteDirectory($yol);
        }

        parent::tearDown();
    }

    private function klasor(string $kok): string
    {
        $klasor = $kok.'/emor-atomik-'.bin2hex(random_bytes(4));
        mkdir($klasor, 0700);
        $this->temizlenecek[] = $klasor;

        return $klasor;
    }

    /**
     * Farklı dosya sistemleri arasında `stream_copy_to_stream` bütün baytları
     * kopyalasa da `feof()` false kalabiliyor; kopya başarısız sayılıyordu
     * (NAS hedefinde görüldü). Ölçüt kopyalanan bayt sayısı.
     */
    public function test_baska_dosya_sistemine_kopya_basarili_sayilir_ve_icerik_ayni(): void
    {
        if (! is_dir('/dev/shm') || ! is_writable('/dev/shm')) {
            $this->markTestSkipped('/dev/shm yok: ayrı dosya sistemi bulunamadı.');
        }

        $kaynak = $this->klasor(storage_path('framework')).'/kaynak.dump';
        file_put_contents($kaynak, random_bytes(100_000));
        $hedef = $this->klasor('/dev/shm').'/hedef.dump';

        AtomikDosya::kopyala($kaynak, $hedef);

        $this->assertSame(hash_file('sha256', $kaynak), hash_file('sha256', $hedef));
        $this->assertSame([], glob(dirname($hedef).'/.*.tmp') ?: []);
    }

    public function test_bos_dosya_da_kopyalanir(): void
    {
        $klasor = $this->klasor(sys_get_temp_dir());
        file_put_contents($klasor.'/bos', '');

        AtomikDosya::kopyala($klasor.'/bos', $klasor.'/kopya');

        $this->assertSame('', file_get_contents($klasor.'/kopya'));
    }
}
