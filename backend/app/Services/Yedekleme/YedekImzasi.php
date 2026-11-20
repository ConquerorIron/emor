<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Manifest ve olay günlüğü imzası (README §5.3, §4.5): veritabanının dışında
 * duran anahtarla HMAC-SHA256. Sistemin ürettiği yedeği dışarıdan gelen
 * dosyadan ayıran budur; özet (SHA-256) yalnız dosyanın değişmediğini söyler.
 *
 * Geçiş döneminde (servis hesabı yokken) anahtarı uygulama kullanıcısı da
 * okur: imza o dönemde ele geçirilmiş web sürecine karşı koruma değildir
 * (README §5.2). Anahtar hiçbir günlüğe, yanıta ya da hataya yazılmaz.
 */
final class YedekImzasi
{
    /** Alan ayrımı: aynı anahtar başka bir amaçla kullanılsa da imzalar karışmaz */
    private const ON_EK = 'emor-yedek-imza-v1:';

    private const EN_AZ_BAYT = 32;

    private ?string $anahtar = null;

    public function __construct(private readonly YedeklemeYapilandirmasi $yapilandirma) {}

    /**
     * @param  array<array-key, mixed>  $veri
     *
     * @throws ImzaAnahtariYok
     */
    public function imzala(array $veri): string
    {
        return hash_hmac('sha256', self::ON_EK.KanonikJson::kodla($veri), $this->anahtar());
    }

    /**
     * @param  array<array-key, mixed>  $veri
     *
     * @throws ImzaAnahtariYok
     */
    public function dogrula(array $veri, mixed $imza): bool
    {
        return is_string($imza)
            && preg_match('/^[0-9a-f]{64}$/D', $imza) === 1
            && hash_equals($this->imzala($veri), $imza);
    }

    public function anahtarVarMi(): bool
    {
        try {
            $this->anahtar();

            return true;
        } catch (ImzaAnahtariYok) {
            return false;
        }
    }

    /**
     * Anahtar yoksa üretir (0600, rastgele 64 bayt). Var olanın üstüne asla
     * yazmaz: anahtarı değiştirmek bütün yedekleri "güvenilmeyen" yapardı.
     *
     * @return bool Yeni anahtar üretildi mi
     *
     * @throws RuntimeException Yazılamadı
     */
    public function anahtarUret(): bool
    {
        $yol = $this->yapilandirma->imzaAnahtariYolu();

        if (file_exists($yol)) {
            return false;
        }

        $klasor = dirname($yol);
        if (! is_dir($klasor) && ! @mkdir($klasor, 0750, true) && ! is_dir($klasor)) {
            throw new RuntimeException("İmza anahtarının klasörü açılamadı: {$klasor}");
        }

        // 'x': aynı anda üreten ikinci süreç üstüne yazamaz
        $tutamac = @fopen($yol, 'x');
        if ($tutamac === false) {
            throw new RuntimeException("İmza anahtarı yazılamadı: {$yol}");
        }

        try {
            $yazildi = chmod($yol, 0600)
                && fwrite($tutamac, bin2hex(random_bytes(64))) === 128
                && fflush($tutamac)
                && fsync($tutamac);
        } finally {
            fclose($tutamac);
        }

        if (! $yazildi) {
            @unlink($yol);
            throw new RuntimeException("İmza anahtarı yazılamadı: {$yol}");
        }

        KaliciKlasor::esitle($klasor);
        $this->anahtar = null;

        return true;
    }

    /**
     * @throws ImzaAnahtariYok
     */
    private function anahtar(): string
    {
        if ($this->anahtar !== null) {
            return $this->anahtar;
        }

        $yol = $this->yapilandirma->imzaAnahtariYolu();

        if (! is_file($yol)) {
            throw ImzaAnahtariYok::yol($yol, 'dosya yok');
        }

        $icerik = @file_get_contents($yol);
        if ($icerik === false) {
            throw ImzaAnahtariYok::yol($yol, 'okunamıyor (izinleri inceleyin)');
        }

        $icerik = trim($icerik);
        if (strlen($icerik) < self::EN_AZ_BAYT) {
            throw ImzaAnahtariYok::yol($yol, 'en az '.self::EN_AZ_BAYT.' bayt olmalı');
        }

        return $this->anahtar = $icerik;
    }
}
