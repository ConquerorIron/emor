<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use InvalidArgumentException;
use RuntimeException;

/**
 * Yedek / geri yükleme / yayın kilidi (BY-005, README §5.2): işletim sistemi
 * dosya kilidi (flock). Veritabanına ve önbelleğe DAYANMAZ — ikisi de geri
 * yüklenen veritabanının içinde. Kilit, onu tutan süreç bitince (öldürülse de)
 * çekirdek tarafından bırakılır.
 *
 * Aynı kilidi `deploy.sh` da `flock` komutuyla alır (BY-013). Sunucuda kilit
 * dosyası önceden açılır ve yayın kullanıcısı ona yalnız okuma iznine sahiptir
 * (BY-003): dosya bu yüzden salt okunur açılır — flock için yazma izni gerekmez.
 *
 * "Meşgul" ile "kilit dosyası açılamıyor" ayrıdır: ilki false döner, ikincisi
 * istisnadır (yanlış izin, sessizce "başka işlem var" sanılmasın).
 */
final class YedekKilidi
{
    /** bekleyerekAl() denemeleri arası (mikrosaniye) */
    private const BEKLEME_ARALIGI_US = 200_000;

    /** @var resource|null */
    private $tutamac = null;

    public function __construct(private readonly string $yol) {}

    public function __destruct()
    {
        $this->birak();
    }

    /** Kilidi almayı bir kez dener; başka işlem tutuyorsa beklemeden false döner. */
    public function al(): bool
    {
        if ($this->tutamac !== null) {
            return true;
        }

        $tutamac = $this->ac();

        if (! flock($tutamac, LOCK_EX | LOCK_NB)) {
            fclose($tutamac);

            return false;
        }

        $this->tutamac = $tutamac;

        return true;
    }

    /**
     * En çok $saniye bekleyerek kilidi almaya çalışır; süre dolarsa false.
     * Süresiz bekleme yoktur (Dikkat_Edilecekler D1). Süre monoton saatle
     * ölçülür: sistem saati ayarlansa da bekleme uzamaz / erken bitmez.
     *
     * @throws InvalidArgumentException Süre sonlu ve negatif olmayan bir sayı değil
     */
    public function bekleyerekAl(float $saniye): bool
    {
        if (! is_finite($saniye) || $saniye < 0) {
            throw new InvalidArgumentException('Kilit bekleme süresi sonlu ve negatif olmayan bir sayı olmalı.');
        }

        $son = hrtime(true) + (int) ($saniye * 1e9);

        while (true) {
            if ($this->al()) {
                return true;
            }

            $kalanNs = $son - hrtime(true);
            if ($kalanNs <= 0) {
                return false;
            }

            usleep(min(self::BEKLEME_ARALIGI_US, intdiv($kalanNs, 1000) + 1));
        }
    }

    public function birak(): void
    {
        if ($this->tutamac === null) {
            return;
        }

        flock($this->tutamac, LOCK_UN);
        fclose($this->tutamac);
        $this->tutamac = null;
    }

    /**
     * Var olan dosya salt okunur açılır; yoksa klasörüyle birlikte açılır
     * (geçiş dönemi: uygulama kullanıcısı). Açılamazsa istisna.
     *
     * @return resource
     */
    private function ac()
    {
        if (is_file($this->yol)) {
            $tutamac = @fopen($this->yol, 'r');
        } else {
            $klasor = dirname($this->yol);
            if (! is_dir($klasor)) {
                @mkdir($klasor, 0750, true);
            }

            $tutamac = @fopen($this->yol, 'c');
            if ($tutamac !== false) {
                @chmod($this->yol, 0640);
            }
        }

        if ($tutamac === false) {
            throw new RuntimeException("Yedekleme kilit dosyası açılamadı: {$this->yol} (izinleri inceleyin).");
        }

        return $tutamac;
    }
}
