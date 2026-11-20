<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * PostgreSQL parola dosyası (PGPASSFILE; README §5.2). Parola komut satırına
 * ya da ortam değişkenine girmez (Dikkat_Edilecekler D2): araçlara yalnız
 * dosyanın YOLU verilir.
 *
 * - Servis hesabı: kurulumun yazdığı dosya (yapılandırma `parola_dosyasi`)
 *   olduğu gibi kullanılır, silinmez.
 * - Geçiş dönemi: uygulamanın bağlantı tanımındaki parola, yalnız bu süreç
 *   kullanıcısının okuyabildiği geçici bir klasöre (0700) dosya olarak (0600)
 *   yazılır; iş bitince `sil()` ile kaldırılır.
 */
final class ParolaDosyasi
{
    private function __construct(
        public readonly string $yol,
        private readonly ?string $geciciKlasor,
    ) {}

    public static function kalici(string $yol): self
    {
        if (! is_file($yol) || ! is_readable($yol)) {
            throw new RuntimeException("Yedekleme parola dosyası okunamıyor: {$yol}");
        }

        return new self($yol, null);
    }

    /**
     * Veritabanı adı `*`: aynı dosya `postgres` ve geri yüklenen
     * veritabanlarına bağlanırken de kullanılır.
     */
    public static function gecici(string $host, int $port, string $kullanici, string $parola): self
    {
        $klasor = sys_get_temp_dir().'/emor-yedek-pg-'.bin2hex(random_bytes(8));

        if (! @mkdir($klasor, 0700)) {
            throw new RuntimeException('Geçici parola klasörü açılamadı.');
        }

        $yol = $klasor.'/pgpass';
        $kacis = fn (string $deger): string => str_replace(['\\', ':'], ['\\\\', '\\:'], $deger);
        $satir = implode(':', [$kacis($host), (string) $port, '*', $kacis($kullanici), $kacis($parola)])."\n";

        $tutamac = @fopen($yol, 'x');
        if ($tutamac === false || ! chmod($yol, 0600) || fwrite($tutamac, $satir) !== strlen($satir)) {
            if ($tutamac !== false) {
                fclose($tutamac);
            }
            @unlink($yol);
            @rmdir($klasor);
            throw new RuntimeException('Geçici parola dosyası yazılamadı.');
        }
        fclose($tutamac);

        return new self($yol, $klasor);
    }

    public function sil(): void
    {
        if ($this->geciciKlasor === null) {
            return;
        }

        @unlink($this->yol);
        @rmdir($this->geciciKlasor);
    }
}
