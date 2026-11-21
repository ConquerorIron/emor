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
 * anahtarın kendisi HİÇBİR dosyada, sonuçta, denetim kaydında ya da günlükte
 * yer almaz. Onay / geri dönüş isteğine anahtar değil, web ucunun anahtarı
 * doğruladıktan sonra ürettiği kısa ömürlü KARAR KANITI yazılır (BY-030-R1):
 *
 *   kanit = HMAC-SHA256(anahtar = özet, ileti = işlem|karar|son geçerlilik|nonce|sha256(ayar seçimleri))
 *
 * Çalıştırıcı kanıtı özetle yeniden hesaplayıp sabit sürede karşılaştırır,
 * süresi (en çok KANIT_OMRU_SN) geçmişse uygulamaz, nonce'u işlem durumunda
 * tüketir (tek kullanım). Karşılaştırmalar sabit süreli (hash_equals). Anahtarın
 * süresi dolunca ekran yolu kapanır, komut satırı yolu (`yedek:acil`) açık kalır.
 *
 * Not: özet işlem durumu dosyasındadır ve o dosyayı web süreci de okur. Kanıt
 * diske düşen istekten anahtarın ele geçirilmesini, kararın yeniden
 * oynatılmasını ve başka kararla değiştirilmesini önler; ele geçirilmiş bir web
 * sürecine karşı koruma DEĞİLDİR (o süreç uygulama rolüyle veriye zaten yazar).
 */
final class ErisimAnahtari
{
    /** Anahtarın geçerlilik süresi: geri yükleme + yöneticinin incelemesi için */
    public const GECERLILIK_SAAT = 8;

    /** Karar kanıtının en uzun ömrü (saniye) */
    public const KANIT_OMRU_SN = 300;

    public const KANIT_SURESI_DOLDU = 'kanit_suresi_doldu';

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

    /** Tek kullanımlık karar kanıtının nonce'u: 128 bit, 32 onaltılık karakter */
    public static function nonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param  'onayla'|'geri_don'  $karar
     * @param  string  $ayarSecimleri  AyarSecimi::metne() (geri dönüşte boş)
     */
    public static function kanit(string $ozet, string $islem, string $karar, int $sonGecerlilik, string $nonce, string $ayarSecimleri): string
    {
        return hash_hmac('sha256', implode('|', [$islem, $karar, (string) $sonGecerlilik, $nonce, hash('sha256', $ayarSecimleri)]), $ozet);
    }

    /**
     * Karar kanıtı bu işlemin, bu kararın ve bu seçimlerin mi; anahtarın ve
     * kanıtın süresi geçmemiş mi. Önce kanıt karşılaştırılır (sabit süreli).
     * Nonce'un tüketilmesi çağıranın işidir (işlem durumu).
     *
     * @param  'onayla'|'geri_don'  $karar
     * @return self::GECERLI|self::GECERSIZ|self::SURESI_DOLDU|self::KANIT_SURESI_DOLDU
     */
    public static function kanitDenetle(?string $kanit, ?string $ozet, ?CarbonImmutable $bitis, string $islem, string $karar, int $sonGecerlilik, string $nonce, string $ayarSecimleri): string
    {
        if (! is_string($kanit) || $ozet === null || ! hash_equals(self::kanit($ozet, $islem, $karar, $sonGecerlilik, $nonce, $ayarSecimleri), $kanit)) {
            return self::GECERSIZ;
        }

        if ($bitis === null || ! $bitis->isFuture()) {
            return self::SURESI_DOLDU;
        }

        $simdi = CarbonImmutable::now()->getTimestamp();
        // Ömrü KANIT_OMRU_SN'den uzun yazılmış kanıt da kabul edilmez
        if ($sonGecerlilik < $simdi || $sonGecerlilik > $simdi + self::KANIT_OMRU_SN) {
            return self::KANIT_SURESI_DOLDU;
        }

        return self::GECERLI;
    }
}
