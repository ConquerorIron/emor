<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use Carbon\CarbonImmutable;

/**
 * Üstüne geri yüklemenin işleme özel, süreli erişim anahtarı (README §7.2;
 * BY-029, BY-030). İşlemi başlatan yöneticiye bir kez verilir; yalnız O
 * işlemin ilerlemesini okumaya ve onay / geri dönüş istemeye yarar. Oturuma
 * dayanmaz: oturumlar veritabanındadır ve yer değiştirmeyle düşer.
 *
 * Saklanan yalnız SHA-256 özeti ve bitiş anıdır (işlem durumu dosyası);
 * anahtarın kendisi hiçbir dosyada kalmaz (onay isteği dosyası çalıştırıcı
 * işleyince silinir). Karşılaştırma sabit süreli (hash_equals). Süresi
 * dolunca ekran yolu kapanır, komut satırı yolu (`yedek:acil`) açık kalır.
 */
final class ErisimAnahtari
{
    /** Anahtarın geçerlilik süresi: geri yükleme + yöneticinin incelemesi için */
    public const GECERLILIK_SAAT = 8;

    public const GECERLI = 'gecerli';

    public const GECERSIZ = 'gecersiz';

    public const SURESI_DOLDU = 'suresi_doldu';

    /** 256 bit rastgele, 64 onaltılık karakter */
    public static function uret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function ozet(string $anahtar): string
    {
        return hash('sha256', $anahtar);
    }

    public static function bicimliMi(mixed $anahtar): bool
    {
        return is_string($anahtar) && preg_match('/^[0-9a-f]{64}$/D', $anahtar) === 1;
    }

    /**
     * Anahtar bu işlemin anahtarı mı ve süresi dolmamış mı. Önce özet
     * karşılaştırılır: süresi dolmuş anahtarı yalnız sahibi öğrenir.
     *
     * @param  string|null  $ozet  İşlem durumundaki özet
     * @return self::GECERLI|self::GECERSIZ|self::SURESI_DOLDU
     */
    public static function denetle(?string $anahtar, ?string $ozet, ?CarbonImmutable $bitis): string
    {
        if (! self::bicimliMi($anahtar) || $ozet === null || ! hash_equals($ozet, self::ozet((string) $anahtar))) {
            return self::GECERSIZ;
        }

        if ($bitis === null || ! $bitis->isFuture()) {
            return self::SURESI_DOLDU;
        }

        return self::GECERLI;
    }
}
