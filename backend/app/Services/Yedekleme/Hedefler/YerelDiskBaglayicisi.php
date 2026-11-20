<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\AtomikDosya;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use RuntimeException;

/**
 * "Yerel disk" hedefi (README §4.6): sunucunun kendi diski. Klasör boş
 * bırakılırsa hedef yedek klasörünün KENDİSİDİR (kopya yapılmaz; yedeğin
 * orada kalması bu hedefin kopyasıdır — README §4.2: planın hedeflerinde
 * yerel disk yoksa yerel dosya kopyalardan sonra silinir). Başka bir klasör
 * verilirse (ör. ikinci disk) dosya oraya atomik kopyalanır.
 */
final class YerelDiskBaglayicisi implements HedefBaglayicisi
{
    public function __construct(
        private readonly YedekHedefi $hedef,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    public static function tanim(HedefTuru $tur = HedefTuru::Yerel): HedefTuruTanimi
    {
        return new HedefTuruTanimi(HedefTuru::Yerel, [
            new HedefAlani('klasor', 'yedekleme.hedef.alan.klasor', kurallar: ['nullable', 'string', 'max:500', 'regex:#^(/[^/\0]+)+$#D']),
        ], sunucuDisi: false);
    }

    /** Hedef, yedek klasörünün kendisi mi (kopya yok). */
    public function anaKlasorMu(): bool
    {
        return $this->klasor() === $this->yapilandirma->klasor();
    }

    public function baglan(): void
    {
        $klasor = $this->klasor();

        if (! is_dir($klasor) || ! is_writable($klasor)) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, "Hedef klasörü yok ya da yazılamıyor: {$klasor}");
        }
    }

    public function yaz(string $yerelYol, string $ad): string
    {
        $this->adDenetle($ad);
        $hedef = $this->klasor().'/'.$ad;

        if (realpath($yerelYol) === realpath($hedef) && realpath($hedef) !== false) {
            return $ad;
        }

        try {
            AtomikDosya::kopyala($yerelYol, $hedef);
        } catch (RuntimeException $hata) {
            throw new HedefHatasi(HedefHatasi::YAZILAMADI, $hata->getMessage());
        }

        return $ad;
    }

    public function oku(string $uzakKimlik, string $yerelYol): void
    {
        $this->adDenetle($uzakKimlik);
        $kaynak = $this->klasor().'/'.$uzakKimlik;

        if (realpath($kaynak) !== false && realpath($kaynak) === realpath($yerelYol)) {
            return;
        }

        if (! is_file($kaynak) || ! @copy($kaynak, $yerelYol)) {
            throw new HedefHatasi(HedefHatasi::OKUNAMADI, "Hedefteki dosya okunamadı: {$uzakKimlik}");
        }
    }

    public function sil(string $uzakKimlik): void
    {
        $this->adDenetle($uzakKimlik);
        $yol = $this->klasor().'/'.$uzakKimlik;

        if (file_exists($yol) && ! @unlink($yol)) {
            throw new HedefHatasi(HedefHatasi::SILINEMEDI, "Hedefteki dosya silinemedi: {$uzakKimlik}");
        }
    }

    public function listele(): array
    {
        $dosyalar = @scandir($this->klasor());

        if ($dosyalar === false) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'Hedef klasörü listelenemedi.');
        }

        return array_values(array_filter($dosyalar, fn (string $ad): bool => preg_match('/^erp_.+\.(dump|gpg)$/D', $ad) === 1));
    }

    public function kalanAlan(): ?int
    {
        $bos = @disk_free_space($this->klasor());

        return $bos === false ? null : (int) $bos;
    }

    private function klasor(): string
    {
        $klasor = $this->hedef->baglanti['klasor'] ?? null;

        return is_string($klasor) && $klasor !== '' ? rtrim($klasor, '/') : $this->yapilandirma->klasor();
    }

    /** Yalnız düz dosya adı: hedef klasörünün dışına çıkılamaz */
    private function adDenetle(string $ad): void
    {
        if (preg_match('/^[A-Za-z0-9.][A-Za-z0-9._+-]{0,200}$/D', $ad) !== 1 || str_contains($ad, '..')) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz hedef dosya adı.');
        }
    }
}
