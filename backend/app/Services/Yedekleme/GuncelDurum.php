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
     * @param  array<string, array{hedef_id: int|null, hedef_ad: string|null, durum: string, uzak_kimlik: string|null, sifreli: bool, sifreli_sha256: string|null, plan_idleri: list<int>, silindi: bool}>  $kopyalar  hedef anahtarı ("id:<n>" ya da "ad:<ad>") => kopya
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
                            'uzak_kimlik' => is_string($a['uzak_kimlik'] ?? null) ? $a['uzak_kimlik'] : null,
                            'sifreli' => ($a['sifreli'] ?? false) === true,
                            'sifreli_sha256' => is_string($a['sifreli_sha256'] ?? null) ? $a['sifreli_sha256'] : null,
                            'plan_idleri' => array_values(array_filter((array) ($a['plan_idleri'] ?? []), 'is_int')),
                            'silindi' => false,
                        ];
                    }
                    break;
                case OlayTuru::KopyaSilindi:
                    $anahtar = self::hedefAnahtari($a);
                    if ($anahtar !== null && isset($kopyalar[$anahtar])) {
                        $kopyalar[$anahtar]['silindi'] = true;
                        $kopyalar[$anahtar]['durum'] = KopyaDurumu::Silindi->value;
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
}
