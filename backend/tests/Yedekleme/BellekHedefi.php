<?php

declare(strict_types=1);

namespace Tests\Yedekleme;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\Hedefler\HedefAlani;
use App\Services\Yedekleme\Hedefler\HedefBaglayicisi;
use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefTuruTanimi;
use App\Services\Yedekleme\HedefTuru;

/**
 * Testlerin hedef bağlayıcısı: dosyaları bellekte tutar; istenen adımda hata
 * verir ya da geri okumada içeriği bozar. Yalnız arayüzü uygular — yeni bir
 * türün başka kod değişikliği gerektirmediğinin kanıtıdır (BY-008).
 */
final class BellekHedefi implements HedefBaglayicisi
{
    /** @var array<string, string> uzak kimlik => içerik */
    public static array $dosyalar = [];

    public static ?string $hataAdimi = null;

    /** Hata adımında fırlatılacak kod (boşsa adımın olağan kodu) */
    public static ?string $hataKodu = null;

    public static bool $bozukOku = false;

    public static bool $sunucuDisi = false;

    public static int $yazmaSayisi = 0;

    /** Google Drive gibi: her yazım aynı adla da olsa yeni kimlikli dosya açar (üstüne yazmaz) */
    public static bool $herYazimdaYeniKimlik = false;

    /** Adı bu metinle biten dosyanın yazımı hata verir (çok dosyalı işin ara adımı) */
    public static ?string $yazilamayanAdSonu = null;

    public function __construct(public readonly YedekHedefi $hedef) {}

    public static function sifirla(): void
    {
        self::$dosyalar = [];
        self::$hataAdimi = null;
        self::$hataKodu = null;
        self::$bozukOku = false;
        self::$sunucuDisi = false;
        self::$yazmaSayisi = 0;
        self::$herYazimdaYeniKimlik = false;
        self::$yazilamayanAdSonu = null;
    }

    public static function tanim(HedefTuru $tur): HedefTuruTanimi
    {
        return new HedefTuruTanimi($tur, [new HedefAlani('kova', 'yedekleme.hedef.alan.kova', zorunlu: true)], sunucuDisi: self::$sunucuDisi);
    }

    public function baglan(): void
    {
        $this->hata('baglan', HedefHatasi::ERISILEMEDI);
    }

    public function yaz(string $yerelYol, string $ad): string
    {
        $this->hata('yaz', HedefHatasi::YAZILAMADI);
        if (self::$yazilamayanAdSonu !== null && str_ends_with($ad, self::$yazilamayanAdSonu)) {
            throw new HedefHatasi(HedefHatasi::YAZILAMADI, "sahte yazma hatası: {$ad}");
        }
        self::$yazmaSayisi++;
        $kimlik = $this->hedef->id.'/'.$ad.(self::$herYazimdaYeniKimlik ? '#'.self::$yazmaSayisi : '');
        self::$dosyalar[$kimlik] = (string) file_get_contents($yerelYol);

        return $kimlik;
    }

    public function oku(string $uzakKimlik, string $yerelYol): void
    {
        $this->hata('oku', HedefHatasi::OKUNAMADI);
        if (! isset(self::$dosyalar[$uzakKimlik])) {
            throw new HedefHatasi(HedefHatasi::OKUNAMADI, 'yok');
        }
        file_put_contents($yerelYol, self::$dosyalar[$uzakKimlik].(self::$bozukOku ? 'X' : ''));
    }

    public function sil(string $uzakKimlik): void
    {
        $this->hata('sil', HedefHatasi::SILINEMEDI);
        unset(self::$dosyalar[$uzakKimlik]);
    }

    public function listele(): array
    {
        $this->hata('listele', HedefHatasi::ERISILEMEDI);

        return array_keys(self::$dosyalar);
    }

    public function kalanAlan(): ?int
    {
        return null;
    }

    private function hata(string $adim, string $kod): void
    {
        if (self::$hataAdimi === $adim) {
            throw new HedefHatasi(self::$hataKodu ?? $kod, "sahte {$adim} hatası");
        }
    }
}
