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
 * verilirse (ör. ikinci disk) dosya oraya atomik kopyalanır. Bir klasör
 * (yazımı ne olursa olsun) tek hedefin deposudur: aynı yeri gösteren ikinci
 * hedef kaydedilmez (HedefKonumu::ayniDepoMu; BY-010-R7).
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
        return self::anaKlasorHedefiMi($this->hedef, $this->yapilandirma);
    }

    /**
     * Hedef yedek klasörünün kendisi mi — ana klasörün TEK ölçütü: kopyalama,
     * saklama, elle silme ve dizin taraması bunu kullanır (BY-008-R1). Klasör
     * boşsa ana klasördür; verilmişse yazımına değil gerçek konumuna bakılır:
     * sondaki `/`, `..`, sembolik bağlantı ya da bind mount aynı klasördür.
     */
    public static function anaKlasorHedefiMi(YedekHedefi $hedef, YedeklemeYapilandirmasi $yapilandirma): bool
    {
        return $hedef->tur === HedefTuru::Yerel
            && self::ayniKonumMu(self::hedefKlasoru($hedef, $yapilandirma), $yapilandirma->klasor());
    }

    /**
     * İki yol aynı dosya sistemi nesnesini mi gösteriyor: ikisi de okunabiliyorsa
     * aygıt + düğüm numarası (realpath'in yakaladıklarına ek olarak sabit
     * bağlantı ve bind mount); biri yoksa ya da erişilemiyorsa sadeleştirilmiş
     * yazım (`.`, `..`, yinelenen ve sondaki `/`). Aynı yerin TEK ölçütü (G19):
     * ana klasör, ana dosya ve iki dosya sistemi hedefinin aynı depo olup
     * olmadığı (HedefKonumu::ayniDepoMu; BY-010-R7) bunu kullanır.
     */
    public static function ayniKonumMu(string $a, string $b): bool
    {
        clearstatcache(true, $a);
        clearstatcache(true, $b);
        $sa = @stat($a);
        $sb = @stat($b);

        if ($sa !== false && $sb !== false) {
            return $sa['dev'] === $sb['dev'] && $sa['ino'] === $sb['ino'];
        }

        return self::sadelestir($a) === self::sadelestir($b);
    }

    private static function sadelestir(string $yol): string
    {
        $parcalar = [];
        foreach (explode('/', $yol) as $parca) {
            if ($parca === '' || $parca === '.') {
                continue;
            }
            if ($parca === '..') {
                array_pop($parcalar);

                continue;
            }
            $parcalar[] = $parca;
        }

        return '/'.implode('/', $parcalar);
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

        // Ana klasör hedefinde kopya yapılmaz; başka bir hedefte aynı dosya
        // (sembolik / sabit bağlantı) bağımsız kopya diye kabul edilmez (D4)
        if (is_file($hedef) && self::ayniKonumMu($yerelYol, $hedef)) {
            if ($this->anaKlasorMu()) {
                return $ad;
            }

            throw new HedefHatasi(HedefHatasi::ANA_DOSYA, "Hedefteki dosya yedeğin yerel dosyasının kendisi; bağımsız kopya sayılmaz: {$ad}");
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

        if (is_file($kaynak) && is_file($yerelYol) && self::ayniKonumMu($kaynak, $yerelYol)) {
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
        return self::hedefKlasoru($this->hedef, $this->yapilandirma);
    }

    /** Hedefin yazdığı klasör: boşsa yedek klasörünün kendisi */
    public static function hedefKlasoru(YedekHedefi $hedef, YedeklemeYapilandirmasi $yapilandirma): string
    {
        $klasor = $hedef->baglanti['klasor'] ?? null;

        return is_string($klasor) && $klasor !== '' ? rtrim($klasor, '/') : $yapilandirma->klasor();
    }

    /** Yalnız düz dosya adı: hedef klasörünün dışına çıkılamaz */
    private function adDenetle(string $ad): void
    {
        if (preg_match('/^[A-Za-z0-9.][A-Za-z0-9._+-]{0,200}$/D', $ad) !== 1 || str_contains($ad, '..')) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz hedef dosya adı.');
        }
    }
}
