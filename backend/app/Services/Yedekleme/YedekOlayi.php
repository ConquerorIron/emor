<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Models\YedekKopyasi;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Olay günlüğünün bir satırı. Ayrıntı alanları türe göre (Backup-Jira BY-006):
 * - dogrulandi: {durum: DogrulamaDurumu}
 * - aciklama: {aciklama: string|null}
 * - kopya: {hedef_id, hedef_ad, durum: KopyaDurumu, uzak_kimlik, sifreli, sifreli_sha256, konum_izi, plan_idleri}
 *   (konum_izi: kopyanın yapıldığı yer, HedefKonumu::iz; eski olaylarda yok — BY-028-R5)
 * - kopya_silindi: {hedef_id, hedef_ad, hata_kodu?} (hata_kodu: fiziksel silinmedi, ör. HEDEF_SILME_YETKISI_YOK)
 * - sabitlendi, sabit_cozuldu, yerel_silindi, silindi: {}
 */
final readonly class YedekOlayi
{
    private const TARIH = 'Y-m-d\TH:i:s.uP';

    /**
     * @param  array<string, mixed>  $ayrinti
     */
    public function __construct(
        public CarbonImmutable $zaman,
        public string $yedekKimligi,
        public OlayTuru $tur,
        public array $ayrinti = [],
        public ?int $kullaniciId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $ayrinti
     */
    public static function simdi(string $yedekKimligi, OlayTuru $tur, array $ayrinti = [], ?int $kullaniciId = null): self
    {
        return new self(CarbonImmutable::now('UTC'), $yedekKimligi, $tur, $ayrinti, $kullaniciId);
    }

    /**
     * Kopyanın güncel hâlini taşıyan `kopya` olayı: kopyalayıcı da saklama da
     * aynı biçimi yazar (konum izi dahil; BY-028-R5).
     */
    public static function kopya(YedekKopyasi $kopya, string $yedekKimligi, ?string $hedefAd): self
    {
        return self::simdi($yedekKimligi, OlayTuru::Kopya, [
            'hedef_id' => (int) $kopya->yedek_hedefi_id,
            'hedef_ad' => $hedefAd,
            'durum' => $kopya->durum->value,
            'uzak_kimlik' => $kopya->uzak_kimlik,
            'sifreli' => $kopya->sifreli,
            'sifreli_sha256' => $kopya->sifreli_sha256,
            'konum_izi' => $kopya->konum_izi,
            'plan_idleri' => array_map('intval', $kopya->planlar()->pluck('yedek_planlari.id')->all()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function diziye(): array
    {
        return [
            'zaman' => $this->zaman->utc()->format(self::TARIH),
            'yedek_kimligi' => $this->yedekKimligi,
            'tur' => $this->tur->value,
            'ayrinti' => $this->ayrinti === [] ? new \stdClass : $this->ayrinti,
            'kullanici_id' => $this->kullaniciId,
        ];
    }

    /** Geçersiz satır null: tek bozuk satır günlüğün tamamını okunmaz yapmaz. */
    public static function diziden(mixed $veri): ?self
    {
        if (! is_array($veri) || count($veri) !== 5
            || ! is_string($veri['zaman'] ?? null)
            || ! is_string($veri['yedek_kimligi'] ?? null)
            || preg_match('/^[0-9a-f-]{36}$/D', $veri['yedek_kimligi']) !== 1
            || ! is_string($veri['tur'] ?? null)
            || ! array_key_exists('ayrinti', $veri) || ! is_array($veri['ayrinti'])
            || ! array_key_exists('kullanici_id', $veri)
            || ($veri['kullanici_id'] !== null && ! is_int($veri['kullanici_id']))) {
            return null;
        }

        $tur = OlayTuru::tryFrom($veri['tur']);

        try {
            $zaman = CarbonImmutable::createFromFormat(self::TARIH, $veri['zaman']);
        } catch (Throwable) {
            $zaman = null;
        }

        if ($tur === null || ! $zaman instanceof CarbonImmutable || $zaman->format(self::TARIH) !== $veri['zaman']) {
            return null;
        }

        return new self($zaman->utc(), $veri['yedek_kimligi'], $tur, $veri['ayrinti'], $veri['kullanici_id']);
    }
}
