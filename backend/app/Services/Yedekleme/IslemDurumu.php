<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use JsonException;
use RuntimeException;

/**
 * İşlem durumu dosyası (BY-005, README §5.2, §6.4, §7.2): o anki ya da son
 * yedek / geri yükleme işleminin durumu. Veritabanına DAYANMAZ — geri yükleme
 * sırasında veritabanı değişir. Şema: IslemKaydi (Backup-Jira.md BY-005).
 *
 * Yazma atomiktir: aynı klasörde geçici dosyaya yazılır, diske zorlanır, tek
 * adımda yerine taşınır. Okuyan hiçbir an yarım dosya görmez; yazma yarıda
 * kalırsa önceki durum yerinde kalır (Dikkat_Edilecekler D3).
 */
final class IslemDurumu
{
    public function __construct(private readonly string $yol) {}

    /**
     * Dosya yoksa null (hiç işlem olmamış). Okunamayan ya da şemaya uymayan
     * dosya istisnadır, "işlem yok" sayılmaz.
     *
     * @throws IslemDurumuBozuk
     */
    public function oku(): ?IslemKaydi
    {
        if (! file_exists($this->yol)) {
            return null;
        }

        $icerik = is_file($this->yol) ? @file_get_contents($this->yol) : false;
        if ($icerik === false) {
            throw IslemDurumuBozuk::neden($this->yol, 'dosya okunamadı');
        }

        try {
            $veri = json_decode($icerik, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw IslemDurumuBozuk::neden($this->yol, 'geçerli JSON değil');
        }

        return IslemKaydi::diziden($veri, $this->yol);
    }

    public function yaz(IslemKaydi $kayit): void
    {
        $icerik = json_encode($kayit->diziye(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        $klasor = dirname($this->yol);

        if (! is_dir($klasor) && ! @mkdir($klasor, 0750, true) && ! is_dir($klasor)) {
            throw new RuntimeException("İşlem durumu klasörü açılamadı: {$klasor}");
        }

        // tempnam değil: klasör yazılamazsa sistemin geçici klasörüne düşer ve
        // taşıma artık tek adım olmaz. Nokta önekli ad, okuyanların göreceği ad değildir.
        $gecici = $klasor.'/.'.basename($this->yol).'.'.bin2hex(random_bytes(6)).'.tmp';
        $tutamac = @fopen($gecici, 'x');
        if ($tutamac === false) {
            throw new RuntimeException("İşlem durumu yazılamadı: {$klasor} klasörüne geçici dosya açılamadı.");
        }

        try {
            $yazildi = fwrite($tutamac, $icerik) === strlen($icerik) && fflush($tutamac) && fsync($tutamac);
            fclose($tutamac);
            $tutamac = null;

            if (! $yazildi || ! chmod($gecici, 0640) || ! rename($gecici, $this->yol)) {
                throw new RuntimeException("İşlem durumu yazılamadı: {$this->yol}");
            }
        } finally {
            if ($tutamac !== null) {
                fclose($tutamac);
            }
            if (file_exists($gecici)) {
                @unlink($gecici);
            }
        }
    }

    /**
     * Uygulamayı kapalı tutan bir üstüne geri yükleme var mı (BY-029, README
     * §6.4): varsa yayın, planlı yedek, kuyruk ve zamanlayıcı başlamaz. Dosya
     * bozuksa istisna fırlar — çağıran bunu da "başlama" diye yorumlamalı.
     *
     * @throws IslemDurumuBozuk
     */
    public function acikGeriYuklemeVarMi(): bool
    {
        $kayit = $this->oku();

        return $kayit !== null && $kayit->tur === IslemTuru::GeriYuklemeUstune && $kayit->acik;
    }
}
