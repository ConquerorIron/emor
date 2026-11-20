<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekPlani;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Plan vade hesabı (README §4.2, §7.8; BY-009). Saatler İSTANBUL saatiyle
 * hesaplanır ve UTC döner: sunucunun ve veritabanının saat diliminden
 * bağımsızdır (Türkiye'de yaz saati yok; "var olmayan saat" sorunu yok).
 *
 * Sıklıklar (`siklik` alanı — SiklikTuru):
 * - saatlik: `aralik` saatte bir, ilk çalışma `saat`; isteğe bağlı
 *   `saat_baslangic` / `saat_bitis` (SS:DD, ikisi dahil) ve `gunler` (1 = Pzt … 7 = Paz)
 * - gunluk: her gün `saat`
 * - n_gunluk: `aralik` günde bir `saat`; sayım `baslangic_tarihi`nden
 *   (YYYY-AA-GG; yoksa planın açıldığı gün)
 * - haftalik: `gunler`deki günlerde `saat`
 * - aylik: ayın `ayin_gunu`nde `saat`; o gün ayda yoksa (29–31) ayın son günü
 */
final class PlanTakvimi
{
    public const SAAT_DILIMI = 'Europe/Istanbul';

    /** Bir sonraki çalışma bu kadar gün içinde bulunamazsa plan tanımı çalışmıyor demektir */
    private const EN_COK_GUN = 400;

    /**
     * $sonra'dan KESİN olarak sonraki ilk çalışma (UTC).
     *
     * @throws InvalidArgumentException Plan tanımı geçersiz ya da hiç çalışmıyor
     */
    public function sonraki(YedekPlani $plan, CarbonImmutable $sonra): CarbonImmutable
    {
        $yerel = $sonra->setTimezone(self::SAAT_DILIMI);
        $gun = $yerel->startOfDay();

        for ($i = 0; $i <= self::EN_COK_GUN; $i++, $gun = $gun->addDay()) {
            foreach ($this->gununSaatleri($plan, $gun) as $an) {
                if ($an->greaterThan($yerel)) {
                    return $an->utc();
                }
            }
        }

        throw new InvalidArgumentException("Plan {$plan->ad}: {$this->gunSayisi()} gün içinde çalışma yok (tanımı kontrol edin).");
    }

    /**
     * Sıradaki $adet çalışma (önizleme; README §4.2).
     *
     * @return list<CarbonImmutable> UTC
     */
    public function siradakiler(YedekPlani $plan, int $adet, CarbonImmutable $sonra): array
    {
        $liste = [];
        $an = $sonra;

        for ($i = 0; $i < $adet; $i++) {
            $an = $this->sonraki($plan, $an);
            $liste[] = $an;
        }

        return $liste;
    }

    /**
     * Ayın 29–31'ine kurulu aylık plan bazı aylarda son güne kayar (formda uyarı).
     */
    public function ayinSonunaKayabilir(YedekPlani $plan): bool
    {
        return $plan->siklik_turu === SiklikTuru::Aylik && (int) ($plan->siklik['ayin_gunu'] ?? 1) >= 29;
    }

    /**
     * Bir İstanbul gününde planın çalışma anları (sıralı).
     *
     * @return list<CarbonImmutable>
     */
    private function gununSaatleri(YedekPlani $plan, CarbonImmutable $gun): array
    {
        $s = $plan->siklik ?? [];
        [$saat, $dakika] = $this->saat($plan->saat);
        $an = $gun->setTime($saat, $dakika);

        return match ($plan->siklik_turu) {
            SiklikTuru::Gunluk => [$an],
            SiklikTuru::NGunluk => $this->nGunluk($plan, $gun, $an),
            SiklikTuru::Haftalik => in_array($gun->isoWeekday(), $this->gunler($s, true), true) ? [$an] : [],
            SiklikTuru::Aylik => $gun->day === min($this->ayinGunu($s), $gun->daysInMonth) ? [$an] : [],
            SiklikTuru::Saatlik => $this->saatlik($s, $gun, $an),
        };
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function nGunluk(YedekPlani $plan, CarbonImmutable $gun, CarbonImmutable $an): array
    {
        $aralik = $this->pozitif($plan->siklik['aralik'] ?? null, 'aralik', 366);
        $baslangic = $plan->siklik['baslangic_tarihi'] ?? null;
        $kok = is_string($baslangic) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $baslangic) === 1
            ? CarbonImmutable::createFromFormat('!Y-m-d', $baslangic, self::SAAT_DILIMI)
            : ($plan->created_at ?? CarbonImmutable::now())->setTimezone(self::SAAT_DILIMI)->startOfDay();

        if (! $kok instanceof CarbonImmutable || $gun->lessThan($kok)) {
            return [];
        }

        return ((int) $kok->diffInDays($gun)) % $aralik === 0 ? [$an] : [];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<CarbonImmutable>
     */
    private function saatlik(array $s, CarbonImmutable $gun, CarbonImmutable $ilk): array
    {
        $gunler = $this->gunler($s, false);
        if ($gunler !== [] && ! in_array($gun->isoWeekday(), $gunler, true)) {
            return [];
        }

        $aralik = $this->pozitif($s['aralik'] ?? null, 'aralik', 24);
        [$bs, $bd] = $this->saat(is_string($s['saat_baslangic'] ?? null) ? $s['saat_baslangic'] : '00:00');
        [$ss, $sd] = $this->saat(is_string($s['saat_bitis'] ?? null) ? $s['saat_bitis'] : '23:59');
        $alt = $gun->setTime($bs, $bd);
        $ust = $gun->setTime($ss, $sd);
        $sonGun = $gun->addDay();
        $liste = [];

        for ($an = $ilk; $an->lessThan($sonGun); $an = $an->addHours($aralik)) {
            if ($an->greaterThanOrEqualTo($alt) && $an->lessThanOrEqualTo($ust)) {
                $liste[] = $an;
            }
        }

        return $liste;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return list<int>
     */
    private function gunler(array $s, bool $zorunlu): array
    {
        $gunler = $s['gunler'] ?? [];
        $gecerli = is_array($gunler) ? array_values(array_unique(array_filter($gunler, fn (mixed $g): bool => is_int($g) && $g >= 1 && $g <= 7))) : [];

        if ($zorunlu && $gecerli === []) {
            throw new InvalidArgumentException('Haftalık planda en az bir gün seçilmeli.');
        }

        return $gecerli;
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function ayinGunu(array $s): int
    {
        return $this->pozitif($s['ayin_gunu'] ?? null, 'ayin_gunu', 31);
    }

    private function pozitif(mixed $deger, string $ad, int $enCok): int
    {
        if (! is_int($deger) || $deger < 1 || $deger > $enCok) {
            throw new InvalidArgumentException("Plan sıklığında {$ad} 1 ile {$enCok} arasında tam sayı olmalı.");
        }

        return $deger;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function saat(string $metin): array
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/D', $metin, $p) !== 1) {
            throw new InvalidArgumentException('Saat SS:DD biçiminde olmalı.');
        }

        return [(int) $p[1], (int) $p[2]];
    }

    private function gunSayisi(): int
    {
        return self::EN_COK_GUN;
    }
}
