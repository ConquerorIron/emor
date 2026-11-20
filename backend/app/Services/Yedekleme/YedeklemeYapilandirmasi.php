<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

/**
 * Yedekleme yapılandırmasının tek okuma noktası (config/yedekleme.php).
 * Değeri tüketen yer doğrular: geçersiz ayar sessizce dönüştürülmez, açık
 * hatayla durur (Dikkat_Edilecekler G6, G9; BY-004-R5).
 */
final class YedeklemeYapilandirmasi
{
    public function aktif(): bool
    {
        return config('yedekleme.aktif') === true;
    }

    public function klasor(): string
    {
        return $this->yol('klasor');
    }

    public function durumKlasoru(): string
    {
        return $this->yol('durum_klasoru');
    }

    public function kaliciKok(): string
    {
        return $this->yol('kalici_kok');
    }

    public function imzaAnahtariYolu(): string
    {
        return $this->yol('imza_anahtari');
    }

    public function istekKlasoru(): string
    {
        return $this->yol('istek_klasoru');
    }

    public function ayarKopyasiYolu(): string
    {
        return $this->yol('ayar_kopyasi');
    }

    public function parolaDosyasi(): ?string
    {
        $yol = config('yedekleme.parola_dosyasi');

        return is_string($yol) && $yol !== '' ? $yol : null;
    }

    public function baglantiAdi(): string
    {
        $ad = config('yedekleme.baglanti');

        return is_string($ad) && $ad !== '' ? $ad : 'pgsql';
    }

    public function rol(): ?string
    {
        $rol = config('yedekleme.rol');

        return is_string($rol) && $rol !== '' ? $rol : null;
    }

    public function arac(string $ad): string
    {
        $yol = config("yedekleme.araclar.{$ad}") ?? config("yedekleme.{$ad}");

        if (! is_string($yol) || ! str_starts_with($yol, '/')) {
            throw new YapilandirmaHatasi("yedekleme aracı '{$ad}' için mutlak bir yol tanımlı değil.");
        }

        return $yol;
    }

    /** @return list<string> */
    public function verisiAlinmayanTablolar(): array
    {
        $tablolar = config('yedekleme.verisi_alinmayan_tablolar');

        return is_array($tablolar) ? array_values(array_filter($tablolar, 'is_string')) : [];
    }

    /**
     * Saniye cinsinden süre sınırı. Yalnız pozitif tam sayı (ya da onun
     * metni) kabul edilir: `abc`, `0`, `-10`, `1.5` açık hatadır — Symfony
     * Process'te 0 "sınırsız" demektir (G9).
     *
     * @throws YapilandirmaHatasi
     */
    public function sureSiniri(string $ad): int
    {
        return $this->pozitifTamSayi("sure_siniri.{$ad}");
    }

    public function kopyaDenemeSayisi(): int
    {
        return $this->pozitifTamSayi('kopya_deneme');
    }

    public function kopyaBeklemeGunu(): int
    {
        return $this->pozitifTamSayi('kopya_bekleme_gun');
    }

    public function sinamaGeriYuklemesi(): bool
    {
        return config('yedekleme.sinama_geri_yukleme') === true;
    }

    public function islemDurumu(): IslemDurumu
    {
        return new IslemDurumu($this->durumKlasoru().'/islem.json', $this->kaliciKok());
    }

    /**
     * @throws YapilandirmaHatasi
     */
    private function pozitifTamSayi(string $anahtar): int
    {
        $deger = config("yedekleme.{$anahtar}");

        if (is_int($deger) && $deger > 0) {
            return $deger;
        }

        if (is_string($deger) && preg_match('/^[1-9][0-9]{0,8}$/D', $deger) === 1) {
            return (int) $deger;
        }

        throw new YapilandirmaHatasi("yedekleme.{$anahtar} pozitif bir tam sayı olmalı (geçersiz değer sınırsıza dönüştürülmez).");
    }

    private function yol(string $anahtar): string
    {
        $yol = config("yedekleme.{$anahtar}");

        if (! is_string($yol) || ! str_starts_with($yol, '/')) {
            throw new YapilandirmaHatasi("yedekleme.{$anahtar} mutlak bir yol olmalı.");
        }

        return rtrim($yol, '/');
    }
}
