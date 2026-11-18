<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;

/**
 * İşlem durumu dosyası (BY-005, README §5.2, §6.4, §7.2): o anki ya da son
 * yedek / geri yükleme işleminin durumu. Veritabanına DAYANMAZ — geri yükleme
 * sırasında veritabanı değişir. Şema: IslemKaydi (Backup-Jira.md BY-005).
 *
 * Yazma atomik ve kalıcıdır: kayıt önce okumadaki şemayla denetlenir; aynı
 * klasörde geçici dosyaya yazılır, izni verilip diske zorlanır, tek adımda
 * yerine taşınır, sonra klasör de diske zorlanır (yoksa elektrik kesintisinde
 * taşıma kaybolabilir — fsync(2)). Okuyan hiçbir an yarım dosya görmez; yazma
 * yarıda kalırsa önceki durum yerinde kalır (Dikkat_Edilecekler D3).
 *
 * Tek yazar: dosyayı yalnız yedek kilidini tutan süreç yazar (çalıştırıcı;
 * acil durum komutu da önce kilidi alır). Oku-değiştir-yaz yapan iki süreç
 * birbirinin kaydını ezerdi (Fable incelemesi F16). Okumak için kilit gerekmez.
 */
final class IslemDurumu
{
    public function __construct(private readonly string $yol) {}

    /**
     * Dosya yoksa null (hiç işlem olmamış) — ama yalnız yokluğu DOĞRULANMIŞSA:
     * klasöre erişilemiyorsa `file_exists` da false döner, bu "işlem yok"
     * sayılmaz. Okunamayan ya da şemaya uymayan dosya istisnadır.
     *
     * @throws IslemDurumuBozuk
     */
    public function oku(): ?IslemKaydi
    {
        if (! file_exists($this->yol)) {
            if ($this->yoklukDogrulandi($this->yol)) {
                return null;
            }

            throw IslemDurumuBozuk::neden($this->yol, 'klasörüne erişilemiyor; dosyanın olup olmadığı bilinmiyor');
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

    /**
     * @param  YedekKilidi  $kilit  Yazanın tuttuğu yedek kilidi (tek yazar kuralı)
     *
     * @throws LogicException Kilit tutulmuyor; dosyaya dokunulmadı
     * @throws InvalidArgumentException Kayıt şemaya uymuyor; dosyaya dokunulmadı
     * @throws RuntimeException Yazılamadı ya da diske eşitlenemedi
     */
    public function yaz(IslemKaydi $kayit, YedekKilidi $kilit): void
    {
        if (! $kilit->tutuyorMu()) {
            throw new LogicException('İşlem durumu yalnız yedek kilidini tutan süreç tarafından yazılır; önceki durum korundu.');
        }

        $dizi = $kayit->diziye();

        // Okumadaki doğrulamanın aynısı: okunamayacak bir kayıt son geçerli
        // durumun üzerine yazılmaz (BY-005-R2)
        try {
            IslemKaydi::diziden($dizi, $this->yol);
        } catch (IslemDurumuBozuk $hata) {
            throw new InvalidArgumentException('Geçersiz işlem kaydı yazılmadı; önceki durum korundu. '.$hata->getMessage(), previous: $hata);
        }

        $icerik = json_encode($dizi, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        $klasor = dirname($this->yol);
        $this->klasoruAc($klasor);

        // tempnam değil: klasör yazılamazsa sistemin geçici klasörüne düşer ve
        // taşıma artık tek adım olmaz. Nokta önekli ad, okuyanların göreceği ad değildir.
        $gecici = $klasor.'/.'.basename($this->yol).'.'.bin2hex(random_bytes(6)).'.tmp';
        $tutamac = @fopen($gecici, 'x');
        if ($tutamac === false) {
            throw new RuntimeException("İşlem durumu yazılamadı: {$klasor} klasörüne geçici dosya açılamadı.");
        }

        try {
            // Sıra: izin → içerik → dosyayı diske zorla → taşı → klasörü diske zorla
            $yazildi = chmod($gecici, 0640)
                && fwrite($tutamac, $icerik) === strlen($icerik)
                && fflush($tutamac)
                && fsync($tutamac);
            fclose($tutamac);
            $tutamac = null;

            if (! $yazildi || ! rename($gecici, $this->yol)) {
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

        $this->klasoruEsitle($klasor);
    }

    /**
     * Yol yok mu — yalnız üst klasörü okunabiliyor ve listede yoksa "evet".
     * Üst klasör de yoksa aynı soru bir üst klasöre sorulur (geçiş düzeninde
     * ilk çalıştırma: klasör henüz açılmamış).
     */
    private function yoklukDogrulandi(string $yol): bool
    {
        $ust = dirname($yol);
        $liste = @scandir($ust);

        if ($liste !== false) {
            return ! in_array(basename($yol), $liste, true);
        }

        return $ust !== $yol && ! file_exists($ust) && $this->yoklukDogrulandi($ust);
    }

    /**
     * Klasörü (gerekirse üstleriyle) açar; yeni açılan her klasörün üst
     * klasörünü diske zorlar — yoksa kesintide klasör girdisi kaybolabilir.
     */
    private function klasoruAc(string $klasor): void
    {
        if (is_dir($klasor)) {
            return;
        }

        $yeniler = [];
        for ($k = $klasor; ! is_dir($k) && dirname($k) !== $k; $k = dirname($k)) {
            $yeniler[] = $k;
        }

        if (! @mkdir($klasor, 0750, true) && ! is_dir($klasor)) {
            throw new RuntimeException("İşlem durumu klasörü açılamadı: {$klasor}");
        }

        foreach (array_reverse($yeniler) as $yeni) {
            $this->klasoruEsitle(dirname($yeni));
        }
    }

    /** Klasör girdilerini diske zorlar; olmazsa başarı sayılmaz. */
    private function klasoruEsitle(string $klasor): void
    {
        $tutamac = @fopen($klasor, 'r');
        $esitlendi = $tutamac !== false && fsync($tutamac);

        if ($tutamac !== false) {
            fclose($tutamac);
        }

        if (! $esitlendi) {
            throw new RuntimeException("İşlem durumu klasörü diske eşitlenemedi: {$klasor} (yazılan durum kesintide kaybolabilir).");
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
