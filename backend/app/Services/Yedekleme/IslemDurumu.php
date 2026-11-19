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
 * Tek yazar: dosyayı yalnız DURUM KLASÖRÜNÜN kilidini (`<klasör>/kilit` —
 * Backup-Jira BY-005 "Dosyaların yeri") tutan süreç yazar (çalıştırıcı; acil
 * durum komutu da önce kilidi alır). Oku-değiştir-yaz yapan iki süreç
 * birbirinin kaydını ezerdi (Fable F16); başka bir dosyanın kilidi de yetmez
 * (BY-005-R6). Okumak için kilit gerekmez.
 */
final class IslemDurumu
{
    private const KILIT_DOSYASI = 'kilit';

    /**
     * @param  string  $kaliciKok  Kurulumun açıp diske eşitlediği klasör; durum klasörü onun kendisi ya da altında (KaliciKlasor)
     *
     * @throws InvalidArgumentException Durum klasörü kalıcı kökün altında değil
     */
    public function __construct(private readonly string $yol, private readonly string $kaliciKok)
    {
        KaliciKlasor::kokAltindaOlmali(dirname($yol), $kaliciKok);
    }

    /** Bu durum dosyasının yazarlarının alacağı kilit (alınmamış hâlde). */
    public function kilit(): YedekKilidi
    {
        return new YedekKilidi($this->kilitYolu(), $this->kaliciKok);
    }

    private function kilitYolu(): string
    {
        return dirname($this->yol).'/'.self::KILIT_DOSYASI;
    }

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
     * @param  YedekKilidi  $kilit  Yazanın tuttuğu, bu durum dosyasının kilidi (kilit())
     *
     * @throws LogicException Bu dosyanın kilidi tutulmuyor; dosyaya dokunulmadı
     * @throws InvalidArgumentException Kayıt şemaya uymuyor; dosyaya dokunulmadı
     * @throws RuntimeException Yazılamadı ya da diske eşitlenemedi
     */
    public function yaz(IslemKaydi $kayit, YedekKilidi $kilit): void
    {
        if (! $kilit->dosyayiTutuyorMu($this->kilitYolu())) {
            throw new LogicException('İşlem durumu yalnız durum klasörünün kilidini ('.$this->kilitYolu().') tutan süreç tarafından yazılır; önceki durum korundu.');
        }

        // Okumadaki doğrulamanın aynısı ve YAZILAN, doğrulanmış kaydın
        // kendisidir: okuyucunun taşımadığı bir alan dosyaya giremez
        // (BY-005-R2, R7; Dikkat_Edilecekler G10)
        try {
            $dizi = IslemKaydi::diziden($kayit->diziye(), $this->yol)->diziye();
        } catch (IslemDurumuBozuk $hata) {
            throw new InvalidArgumentException('Geçersiz işlem kaydı yazılmadı; önceki durum korundu. '.$hata->getMessage(), previous: $hata);
        }

        $icerik = json_encode($dizi, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        $klasor = dirname($this->yol);
        KaliciKlasor::ac($klasor, $this->kaliciKok);

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

        KaliciKlasor::esitle($klasor);
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
