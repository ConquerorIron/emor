<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use RuntimeException;

/**
 * Şifreleme anahtarı ya da şifre çözme hatası (BY-019). `kod` makine
 * okunurdur (API sözleşmesi, komut çıktısı). Mesaj anahtar içeriği, parola
 * ve gpg'nin ham hata çıktısını taşımaz (Dikkat_Edilecekler D2).
 */
final class SifrelemeHatasi extends RuntimeException
{
    /** Metin tek bir geçerli, şifrelemeye uygun açık anahtar değil */
    public const ANAHTAR_GECERSIZ = 'YEDEK_ANAHTAR_GECERSIZ';

    /** Yüklenen metinde özel (gizli) anahtar var: sunucu özel anahtar saklamaz */
    public const OZEL_ANAHTAR = 'YEDEK_ANAHTAR_OZEL';

    public const SURESI_DOLMUS = 'YEDEK_ANAHTAR_SURESI_DOLMUS';

    /** Şifre çözmede verilen özel anahtar dosyası okunamadı ya da özel anahtar içermiyor */
    public const OZEL_ANAHTAR_OKUNAMADI = 'YEDEK_OZEL_ANAHTAR_OKUNAMADI';

    /** Özel anahtarın parolası eksik ya da yanlış */
    public const PAROLA = 'YEDEK_ANAHTAR_PAROLASI_HATALI';

    /** Dosya verilen anahtarla şifrelenmemiş ya da bozuk */
    public const COZULEMEDI = 'YEDEK_SIFRE_COZULEMEDI';

    /** gpg çalıştırılamadı, süre sınırını aştı ya da beklenmeyen biçimde bitti */
    public const ARAC = 'YEDEK_GPG_HATASI';

    public function __construct(public readonly string $kod, string $mesaj)
    {
        parent::__construct($mesaj);
    }
}
