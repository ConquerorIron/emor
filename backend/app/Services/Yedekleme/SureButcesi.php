<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Services\Yedekleme\Hedefler\HedefHatasi;
use App\Services\Yedekleme\Hedefler\HedefSinayici;
use Closure;
use InvalidArgumentException;

/**
 * Birden çok adımı olan etkileşimli işin TOPLAM süre bütçesi (D9). Süre
 * monoton saatle ölçülür (D1): sistem saati ayarlanınca bütçe uzayıp kısalmaz.
 * Saat testte sahte verilebilir (ns döndüren kapanış).
 *
 * Etkin bütçe (BY-016-R4): `icinde()` süresince bütçe sürecin ortak son
 * zamanıdır. Bağlayıcıların her ağ çağrısı, yeniden denemesi ve dış süreci
 * süre sınırını ondan alır (`YedeklemeYapilandirmasi::sureSiniri`, `sinirla`,
 * `denemeSiniri`): sınır kalan süreye kısalır, son zaman geçtiyse çağrı
 * yapılmaz. Etkin bütçe yokken (gece kopyası) hiçbir sınır değişmez.
 */
final class SureButcesi
{
    private static ?self $etkin = null;

    private int $sonNs;

    /** @var Closure(): int */
    private readonly Closure $saat;

    /**
     * @param  (Closure(): int)|null  $saat  Monoton saat (ns); boşsa hrtime
     */
    public function __construct(int $saniye, ?Closure $saat = null)
    {
        if ($saniye < 1) {
            throw new InvalidArgumentException('Süre bütçesi pozitif bir saniye olmalı.');
        }

        $this->saat = $saat ?? static fn (): int => hrtime(true);
        $this->sonNs = ($this->saat)() + $saniye * 1_000_000_000;
    }

    /** Kalan süre (sn); bütçe aşıldıysa 0 */
    public function kalanSaniye(): float
    {
        return max(0.0, ($this->sonNs - ($this->saat)()) / 1_000_000_000);
    }

    /** Aynı saatle, şimdiden `$saniye` sonra biten bütçe (bir adımın payı ayrılmış hâli) */
    public function alt(float $saniye): self
    {
        $alt = clone $this;
        $alt->sonNs = ($this->saat)() + (int) round(max(0.0, $saniye) * 1_000_000_000);

        return $alt;
    }

    /**
     * İşi bu bütçe etkinken çalıştırır; sonra önceki etkin bütçe geri gelir.
     *
     * @template T
     *
     * @param  Closure(): T  $is
     * @return T
     */
    public function icinde(Closure $is): mixed
    {
        $onceki = self::$etkin;
        self::$etkin = $this;

        try {
            return $is();
        } finally {
            self::$etkin = $onceki;
        }
    }

    /**
     * Çağrının süre sınırı: etkin bütçe varsa kalan süreye kısaltılır (en az
     * 1 sn — Symfony Process'te 0 "sınırsız" demektir, G9). Kalan 1 sn'den
     * azsa çağrı yapılmaz. Etkin bütçe yoksa `$saniye` aynen döner.
     *
     * @throws HedefHatasi Son zaman geçti (HedefSinayici::SURE_DOLDU)
     */
    public static function sinirla(int $saniye): int
    {
        $butce = self::$etkin;
        if ($butce === null) {
            return $saniye;
        }

        $kalan = $butce->kalanSaniye();
        if ($kalan < 1) {
            throw new HedefHatasi(HedefSinayici::SURE_DOLDU, 'Sınamanın süresi doldu; bu çağrı yapılmadı.');
        }

        return max(1, min($saniye, (int) floor($kalan)));
    }

    /**
     * Yeniden denemeden önce: `$beklemeMs` bekleme + en az 1 sn'lik deneme
     * etkin bütçeye sığıyor mu. Sığıyorsa sonraki denemenin süre sınırı
     * (kalan süreye kısaltılmış `$saniye`), sığmıyorsa null — deneme yapılmaz.
     * Etkin bütçe yoksa `$saniye` aynen döner.
     */
    public static function denemeSiniri(int $saniye, int $beklemeMs): ?int
    {
        $butce = self::$etkin;
        if ($butce === null) {
            return $saniye;
        }

        $kalan = $butce->kalanSaniye() - $beklemeMs / 1000;

        return $kalan < 1 ? null : max(1, min($saniye, (int) floor($kalan)));
    }
}
