<?php

declare(strict_types=1);

namespace App\Services;

/**
 * ERP'nin bir belge için tanımladığı ÖZEL ALANLAR (VOHOM_EVRAK_OZELLIGI).
 *
 * Hangi alanların olduğunu ve zorunlu olup olmadığını ERP söyler; biz yalnız
 * nasıl göründüğünü yönetiriz. Hem ekran tasarımı (kolon listesi) hem kayıt
 * akışı aynı kaynağı kullansın diye ayrı servis: iki yerde ayrı sorgu yazmak
 * er geç ayrışır.
 *
 * EVRAK_KISMI: 1 = satır alanları, 0 = evrak geneli.
 */
final class ErpEvrakOzellikleri
{
    /** Satınalma Talep Formu — EVRAK_TURU 2 / ALT_TUR 8 */
    private const TALEP_ALT_TUR = 8;

    public function __construct(
        private readonly ErpIskelesi $iskele,
    ) {}

    /**
     * @return list<array{ozellik_id: int, ad: string, format: int, zorunlu: bool, salt_okunur: bool}>
     */
    public function satinalmaTalebi(int $evrakKismi): array
    {
        $baglanti = $this->iskele->kur();

        // Evrak konusu id'si ortamlar arasında değişebilir; sabit yazılmaz
        $evrakKonusuId = $baglanti->scalar(
            'SELECT TOP 1 EVRAK_KONUSU_ID FROM TOHOM_EVRAK_KONUSU
             WHERE EVRAK_TURU = 2 AND ALT_TUR = ? AND TEKLIFLERI_VAR IS NULL
             ORDER BY EVRAK_KONUSU_ID',
            [self::TALEP_ALT_TUR],
        );

        if ($evrakKonusuId === null) {
            return [];
        }

        return array_map(
            static fn (object $satir): array => [
                'ozellik_id' => (int) $satir->OZELLIK_ID,
                'ad' => trim((string) $satir->AD),
                'format' => (int) ($satir->OZELLIK_FORMATI ?? 0),
                'zorunlu' => (bool) ($satir->DEGER_GIRISI_ZORUNLU ?? false),
                'salt_okunur' => (bool) ($satir->SALT_OKUNUR ?? false),
            ],
            $baglanti->select(
                'SELECT OZELLIK_ID, AD, OZELLIK_FORMATI, DEGER_GIRISI_ZORUNLU, SALT_OKUNUR
                 FROM VOHOM_EVRAK_OZELLIGI
                 WHERE EVRAK_KONUSU_ID = ? AND EVRAK_KISMI = ?
                 ORDER BY SIRA_NO',
                [$evrakKonusuId, $evrakKismi],
            ),
        );
    }
}
