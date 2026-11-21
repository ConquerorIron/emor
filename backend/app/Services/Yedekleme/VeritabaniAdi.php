<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Carbon\CarbonImmutable;

/**
 * Geri yüklemenin açtığı veritabanlarının adları (README §4.5-A, §8: hedef
 * ad serbest metin DEĞİLDİR). Ad sistemce üretilir; kullanıcı yalnız sıkı
 * kalıba uyan kısa bir ek verebilir. Çalıştırıcı yalnız bu kalıplara uyan
 * veritabanlarını siler (GeriYuklemeVeritabani::dusur) — `erp` ya da başka
 * bir ad hiçbir yoldan silinemez.
 *
 * - Kopya: `erp_geri_<YYYYAAGG>_<SSddss>[_<ek>]` (İstanbul saati), en çok 41 karakter.
 * - Sınama: `erp_sinama_<YYYYAAGG>_<SSddss>_<6 onaltılık>`; her zaman silinir.
 * - Önceki: `erp_onceki_<YYYYAAGG>_<SSddss>` — üstüne geri yüklemede eski canlı
 *   veritabanının yeni adı (README §6.4 7. adım; BY-028). Yönetilen ad
 *   DEĞİLDİR: dusur() onu silemez; silinmesi ayrı bir karardır (§4.5-B).
 *
 * Kalıplar tırnak gerektirmeyen PostgreSQL adlarıdır (≤ 63 bayt; IslemKaydi
 * şemasına da uyar).
 */
final class VeritabaniAdi
{
    /** Kullanıcının verebileceği ek: küçük harf ve rakam, 1–16 */
    public const EK_DESENI = '/^[a-z0-9]{1,16}$/D';

    private const KOPYA_DESENI = '/^erp_geri_[0-9]{8}_[0-9]{6}(?:_[a-z0-9]{1,16})?$/D';

    private const SINAMA_DESENI = '/^erp_sinama_[0-9]{8}_[0-9]{6}_[0-9a-f]{6}$/D';

    private const ONCEKI_DESENI = '/^erp_onceki_[0-9]{8}_[0-9]{6}$/D';

    /**
     * @throws GeriYuklemeHatasi Ek kalıba uymuyor
     */
    public static function kopya(CarbonImmutable $zaman, ?string $ek = null): string
    {
        if ($ek !== null && preg_match(self::EK_DESENI, $ek) !== 1) {
            throw new GeriYuklemeHatasi(GeriYuklemeHatasi::HEDEF_ADI_GECERSIZ, 'Hedef veritabanı adının eki yalnız küçük harf ve rakamdan oluşabilir (en çok 16).');
        }

        return 'erp_geri_'.$zaman->setTimezone('Europe/Istanbul')->format('Ymd_His').($ek === null ? '' : '_'.$ek);
    }

    public static function sinama(CarbonImmutable $zaman): string
    {
        return 'erp_sinama_'.$zaman->setTimezone('Europe/Istanbul')->format('Ymd_His').'_'.bin2hex(random_bytes(3));
    }

    /** Üstüne geri yüklemede eski canlı veritabanının alacağı ad (BY-028) */
    public static function onceki(CarbonImmutable $zaman): string
    {
        return 'erp_onceki_'.$zaman->setTimezone('Europe/Istanbul')->format('Ymd_His');
    }

    public static function oncekiMi(string $ad): bool
    {
        return preg_match(self::ONCEKI_DESENI, $ad) === 1;
    }

    public static function kopyaMi(string $ad): bool
    {
        return preg_match(self::KOPYA_DESENI, $ad) === 1;
    }

    public static function sinamaMi(string $ad): bool
    {
        return preg_match(self::SINAMA_DESENI, $ad) === 1;
    }

    /** Çalıştırıcının açıp silebildiği ad mı (kopya ya da sınama) */
    public static function yonetilenMi(string $ad): bool
    {
        return self::kopyaMi($ad) || self::sinamaMi($ad);
    }
}
