<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\Yedek;
use App\Models\YedekKopyasi;

/**
 * Bir yedeğin web'den (çalıştırıcıyla, özel anahtarsız) geri yüklenebileceği
 * kaynaklar — TEK doğruluk kaynağı (BY-027-R1): GeriYukleyici dosyayı bu
 * sırayla arar, Yedekler ekranı eylemi buna göre açar.
 *
 * 1. Yerel dosya.
 * 2. Tamamlanmış, ŞİFRESİZ uzak kopyalar (ör. ikinci yerel disk), eskiden yeniye.
 *    Yeri doğrulanamayan (`konum_belirsiz`) kopya kaynak değildir (BY-028-R5).
 *
 * Yalnız şifreli kopya kalmışsa çözmek için özel anahtar gerekir; sunucu özel
 * anahtar saklamaz (README §4.7): komut satırından `yedek:sifre-coz` ile
 * yapılır (docs/yedekleme/kurtarma.md).
 */
final readonly class GeriYuklemeKaynaklari
{
    /**
     * @param  list<YedekKopyasi>  $uzaklar  Denenecek şifresiz kopyalar, sırasıyla
     */
    private function __construct(
        public bool $yerel,
        public array $uzaklar,
        public bool $sifreliVar,
    ) {}

    /**
     * @param  iterable<YedekKopyasi>  $kopyalar  Yedeğin bütün kopyaları (süzgeç burada)
     */
    public static function sec(bool $yerelVar, iterable $kopyalar): self
    {
        $uzaklar = [];
        $sifreliVar = false;

        foreach ($kopyalar as $kopya) {
            if ($kopya->durum !== KopyaDurumu::Tamam || $kopya->uzak_kimlik === null) {
                continue;
            }

            if ($kopya->sifreli) {
                $sifreliVar = true;
            } else {
                $uzaklar[] = $kopya;
            }
        }

        usort($uzaklar, fn (YedekKopyasi $a, YedekKopyasi $b): int => $a->id <=> $b->id);

        return new self($yerelVar, $uzaklar, $sifreliVar);
    }

    /**
     * Ekran için: web'den neden geri yüklenemez (kod), ya da null. Yerel
     * dosyanın varlığı dizin kaydından okunur; çalıştırıcı dosyayı ayrıca
     * manifestle doğrular.
     */
    public static function engel(Yedek $yedek): ?string
    {
        if (! $yedek->geriYuklenebilirMi()) {
            return GeriYuklemeHatasi::YEDEK_GERI_YUKLENEMEZ;
        }

        $kaynaklar = self::sec($yedek->yerel_silinme_zamani === null, $yedek->kopyalar);

        if ($kaynaklar->yerel || $kaynaklar->uzaklar !== []) {
            return null;
        }

        return $kaynaklar->sifreliVar ? GeriYuklemeHatasi::OZEL_ANAHTAR_GEREKLI : GeriYuklemeHatasi::YEDEK_DOSYASI_YOK;
    }
}
