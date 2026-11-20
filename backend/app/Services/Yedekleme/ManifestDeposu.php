<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use JsonException;
use RuntimeException;

/**
 * Manifestlerin dosyaya yazılması ve okunması (README §5.3). Dosya biçimi:
 * `{"manifest": {...}, "imza": "<hex>"}`; imza manifestin kanonik JSON'u
 * üzerinedir (girinti ve anahtar sırası imzayı etkilemez).
 */
final class ManifestDeposu
{
    public function __construct(private readonly YedekImzasi $imza) {}

    /** `erp_..._.dump` → aynı klasörde `erp_..._.json` */
    public static function jsonYolu(string $dumpYolu): string
    {
        return preg_replace('/\.dump$/D', '', $dumpYolu).'.json';
    }

    /**
     * İmzalayıp atomik yazar.
     *
     * @throws ImzaAnahtariYok
     * @throws RuntimeException
     */
    public function yaz(YedekManifesti $manifest, string $klasor): string
    {
        $veri = $manifest->diziye();
        $yol = self::jsonYolu($klasor.'/'.$manifest->dosyaAdi);
        $icerik = json_encode(
            ['manifest' => $veri, 'imza' => $this->imza->imzala($veri)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        )."\n";

        AtomikDosya::yaz($yol, $icerik);

        return $yol;
    }

    /** Hiçbir içerik sorunu istisna fırlatmaz; sınıflanmış sonuç döner. */
    public function oku(string $jsonYolu): ManifestOkumaSonucu
    {
        if (! is_file($jsonYolu)) {
            return ManifestOkumaSonucu::guvenilmeyen(ManifestOkumaSonucu::NEDEN_YOK);
        }

        $icerik = @file_get_contents($jsonYolu);
        if ($icerik === false) {
            return ManifestOkumaSonucu::guvenilmeyen(ManifestOkumaSonucu::NEDEN_OKUNAMADI);
        }

        try {
            $veri = json_decode($icerik, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ManifestOkumaSonucu::guvenilmeyen(ManifestOkumaSonucu::NEDEN_JSON);
        }

        if (! is_array($veri) || ! is_array($veri['manifest'] ?? null) || ! array_key_exists('imza', $veri) || count($veri) !== 2) {
            return ManifestOkumaSonucu::guvenilmeyen(ManifestOkumaSonucu::NEDEN_IMZA_YOK);
        }

        try {
            if (! $this->imza->dogrula($veri['manifest'], $veri['imza'])) {
                return ManifestOkumaSonucu::guvenilmeyen(ManifestOkumaSonucu::NEDEN_IMZA_TUTMUYOR);
            }
        } catch (ImzaAnahtariYok) {
            return ManifestOkumaSonucu::guvenilmeyen(ManifestOkumaSonucu::NEDEN_ANAHTAR_YOK);
        }

        try {
            return ManifestOkumaSonucu::guvenilir(YedekManifesti::diziden($veri['manifest']));
        } catch (ManifestGecersiz) {
            return ManifestOkumaSonucu::guvenilmeyen(ManifestOkumaSonucu::NEDEN_SEMA);
        }
    }

    /**
     * Yedek dosyası manifestle aynı mı (boyut + SHA-256). Dosya yoksa ya da
     * okunamıyorsa false.
     */
    public static function dosyaTutuyorMu(YedekManifesti $manifest, string $dumpYolu): bool
    {
        if (! is_file($dumpYolu) || @filesize($dumpYolu) !== $manifest->boyut) {
            return false;
        }

        $ozet = @hash_file('sha256', $dumpYolu);

        return is_string($ozet) && hash_equals($manifest->sha256, $ozet);
    }
}
