<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Carbon\CarbonImmutable;

/**
 * Bir yedeğin olay günlüğünden hesaplanan güncel durumu (README §5.3).
 * Olaylar zaman sırasıyla uygulanır; aynı alana sonra gelen olay öncekini
 * geçer (sabitle → çöz → sabitle = sabit).
 */
final readonly class GuncelDurum
{
    /**
     * @param  array<string, array{hedef_id: int|null, hedef_ad: string|null, durum: string, uzak_kimlik: string|null, sifreli: bool, sifreli_sha256: string|null, konum_izi: string|null, plan_idleri: list<int>, silindi: bool, hata_kodu: string|null, zaman: CarbonImmutable}>  $kopyalar  olaydaki hedef anahtarı ("id:<n>" ya da "ad:<ad>") => o anahtarın son kopyası; `zaman` onu belirleyen son olayın (kopya ya da silme) zamanı
     */
    public function __construct(
        public bool $sabit,
        public bool $aciklamaDegisti,
        public ?string $aciklama,
        public ?DogrulamaDurumu $dogrulama,
        public array $kopyalar,
        public ?CarbonImmutable $yerelSilinme,
        public ?CarbonImmutable $silinme,
    ) {}

    /**
     * @param  list<YedekOlayi>  $olaylar  Aynı yedeğin olayları
     */
    public static function hesapla(array $olaylar): self
    {
        usort($olaylar, fn (YedekOlayi $a, YedekOlayi $b): int => $a->zaman <=> $b->zaman);

        $sabit = false;
        $aciklamaDegisti = false;
        $aciklama = null;
        $dogrulama = null;
        $kopyalar = [];
        $yerelSilinme = null;
        $silinme = null;

        foreach ($olaylar as $olay) {
            $a = $olay->ayrinti;

            switch ($olay->tur) {
                case OlayTuru::Sabitlendi:
                    $sabit = true;
                    break;
                case OlayTuru::SabitCozuldu:
                    $sabit = false;
                    break;
                case OlayTuru::Aciklama:
                    $aciklamaDegisti = true;
                    $aciklama = is_string($a['aciklama'] ?? null) ? $a['aciklama'] : null;
                    break;
                case OlayTuru::Dogrulandi:
                    $dogrulama = is_string($a['durum'] ?? null) ? DogrulamaDurumu::tryFrom($a['durum']) ?? $dogrulama : $dogrulama;
                    break;
                case OlayTuru::Kopya:
                    $anahtar = self::hedefAnahtari($a);
                    if ($anahtar !== null && is_string($a['durum'] ?? null) && KopyaDurumu::tryFrom($a['durum']) !== null) {
                        $kopyalar[$anahtar] = [
                            'hedef_id' => is_int($a['hedef_id'] ?? null) ? $a['hedef_id'] : null,
                            'hedef_ad' => is_string($a['hedef_ad'] ?? null) ? $a['hedef_ad'] : null,
                            'durum' => $a['durum'],
                            'uzak_kimlik' => self::uzakKimlik($a),
                            'sifreli' => ($a['sifreli'] ?? false) === true,
                            'sifreli_sha256' => is_string($a['sifreli_sha256'] ?? null) ? $a['sifreli_sha256'] : null,
                            'konum_izi' => self::konumIzi($a),
                            'plan_idleri' => array_values(array_filter((array) ($a['plan_idleri'] ?? []), 'is_int')),
                            'silindi' => false,
                            'hata_kodu' => null,
                            'zaman' => $olay->zaman,
                        ];
                    }
                    break;
                case OlayTuru::KopyaSilindi:
                    foreach (self::silinenler($kopyalar, $a) as $anahtar) {
                        $kopyalar[$anahtar]['silindi'] = true;
                        $kopyalar[$anahtar]['durum'] = KopyaDurumu::Silindi->value;
                        // Doluysa kopya fiziksel silinmedi, bizden çıkmış sayıldı (BY-023-R1)
                        $kopyalar[$anahtar]['hata_kodu'] = is_string($a['hata_kodu'] ?? null) ? $a['hata_kodu'] : null;
                        $kopyalar[$anahtar]['zaman'] = $olay->zaman;
                    }
                    break;
                case OlayTuru::YerelSilindi:
                    $yerelSilinme ??= $olay->zaman;
                    break;
                case OlayTuru::Silindi:
                    $silinme ??= $olay->zaman;
                    break;
            }
        }

        return new self($sabit, $aciklamaDegisti, $aciklama, $dogrulama, $kopyalar, $yerelSilinme, $silinme);
    }

    /**
     * Hedef kimliği tercih edilir; kimlik bilinmiyorsa ad (dizin yeniden
     * kurulurken hedef tablosu farklı kimliklerle gelmiş olabilir).
     *
     * @param  array<string, mixed>  $ayrinti
     */
    public static function hedefAnahtari(array $ayrinti): ?string
    {
        if (is_int($ayrinti['hedef_id'] ?? null)) {
            return 'id:'.$ayrinti['hedef_id'];
        }

        return is_string($ayrinti['hedef_ad'] ?? null) ? 'ad:'.$ayrinti['hedef_ad'] : null;
    }

    /**
     * Silme olayının ait olduğu kopyaların hedef anahtarları (BY-028-R10).
     * Olay silinen kaydın uzak kimliğini taşıyorsa (KopyaSilmeKaydi) yalnız o
     * fiziksel nesneye uygulanır: kendi anahtarındaki kopyaya uzak kimliği
     * aynıysa, başka anahtardaki silinmemiş kopyaya uzak kimliği VE konum izi
     * aynıysa (aynı yerdeki aynı nesne: dizin kurulurken konum iziyle bu
     * hedefe bağlanmış başka bir olay hedefinin kopyası). Aynı hedefteki
     * farklı nesne (ör. Google Drive'da aynı klasördeki başka dosya) silinmiş
     * sayılmaz. Uzak kimliksiz (eski) olay yalnız kendi anahtarına uygulanır.
     *
     * @param  array<string, array{uzak_kimlik: string|null, konum_izi: string|null, silindi: bool}>  $kopyalar
     * @param  array<string, mixed>  $ayrinti
     * @return list<string>
     */
    private static function silinenler(array $kopyalar, array $ayrinti): array
    {
        $anahtar = self::hedefAnahtari($ayrinti);
        $uzak = self::uzakKimlik($ayrinti);
        if ($uzak === null) {
            return $anahtar !== null && isset($kopyalar[$anahtar]) ? [$anahtar] : [];
        }

        $iz = self::konumIzi($ayrinti);
        $silinenler = [];
        foreach ($kopyalar as $a => $k) {
            $a = (string) $a;
            if ($k['uzak_kimlik'] === $uzak && ($a === $anahtar || ($iz !== null && ! $k['silindi'] && $k['konum_izi'] === $iz))) {
                $silinenler[] = $a;
            }
        }

        return $silinenler;
    }

    /** @param  array<string, mixed>  $ayrinti */
    private static function uzakKimlik(array $ayrinti): ?string
    {
        return is_string($ayrinti['uzak_kimlik'] ?? null) ? $ayrinti['uzak_kimlik'] : null;
    }

    /**
     * Biçimsiz iz izsiz sayılır: konum doğrulanamaz (BY-028-R5)
     *
     * @param  array<string, mixed>  $ayrinti
     */
    private static function konumIzi(array $ayrinti): ?string
    {
        return is_string($ayrinti['konum_izi'] ?? null) && preg_match('/^[0-9a-f]{64}$/D', $ayrinti['konum_izi']) === 1 ? $ayrinti['konum_izi'] : null;
    }
}
