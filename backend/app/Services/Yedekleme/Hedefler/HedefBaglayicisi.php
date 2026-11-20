<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

/**
 * Bir yedek hedefi türünün bağlayıcısı (README §4.6). Yeni bir tür eklemek
 * yalnız bu arayüzü uygulayan bir sınıf ve HedefKatalogu'nda bir satır
 * ister; kopyalama, sınama, saklama ve ekran türden habersizdir (BY-008).
 *
 * Her yöntem başarısızlıkta HedefHatasi fırlatır; hata mesajı parola, anahtar
 * ya da karşı tarafın ham yanıt gövdesini taşımaz (Dikkat_Edilecekler D2).
 */
interface HedefBaglayicisi
{
    /** Bağlantıyı kurar ve kimliği doğrular (sınamanın ilk adımı). */
    public function baglan(): void;

    /**
     * Yerel dosyayı hedefe yazar. Hedefte yarım dosya kalmaz: önce geçici ada
     * yazılır, tamamlanınca asıl ada taşınır (tür destekliyorsa).
     *
     * @return string Hedefteki kimlik (yol ya da dosya kimliği)
     */
    public function yaz(string $yerelYol, string $ad): string;

    /** Hedefteki dosyayı yerel yola indirir. */
    public function oku(string $uzakKimlik, string $yerelYol): void;

    public function sil(string $uzakKimlik): void;

    /**
     * Hedefte bu uygulamanın yazdığı dosyaların kimlikleri.
     *
     * @return list<string>
     */
    public function listele(): array;

    /** Kalan alan (bayt); tür bilmiyorsa null. */
    public function kalanAlan(): ?int;
}
