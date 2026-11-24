<?php

declare(strict_types=1);

namespace App\Services\Yedekleme;

use App\Services\Yedekleme\Hedefler\HedefHatasi;

/**
 * Yedekleme yapılandırmasının tek okuma noktası (config/yedekleme.php).
 * Değeri tüketen yer doğrular: geçersiz ayar sessizce dönüştürülmez, açık
 * hatayla durur (Dikkat_Edilecekler G6, G9; BY-004-R5).
 */
final class YedeklemeYapilandirmasi
{
    public function aktif(): bool
    {
        return config('yedekleme.aktif') === true;
    }

    public function klasor(): string
    {
        return $this->yol('klasor');
    }

    /**
     * Büyük dosya taşıyan geçici çalışma klasörlerinin kökü (YF-28):
     * tanımsızsa yedek klasörünün diskinde `<klasor>/.calisma`. Nokta önekli
     * girdileri dizin taraması ve sahipsiz yedek temizliği yok sayar.
     *
     * @throws YapilandirmaHatasi Tanımlı ama mutlak yol değil
     */
    public function calismaKlasoru(): string
    {
        $yol = config('yedekleme.calisma_klasoru');

        return $yol === null || $yol === '' ? $this->klasor().'/.calisma' : $this->yol('calisma_klasoru');
    }

    /**
     * Geçici çalışma klasörünün "artık" sayılacağı yaş (sn): o klasörü
     * kullanan en uzun işin (yedek, geri yükleme, şifreleme, kopyalama ve
     * yeniden denemeleri) bile bitmiş olacağı süre — bütün süre sınırlarının
     * üç katı + 1 saat. Etkin süre bütçesine (sınama) göre kısaltılmaz:
     * başka bir süreçteki uzun işin klasörü silinmesin.
     *
     * @throws YapilandirmaHatasi
     */
    public function artikKlasorYasi(): int
    {
        $toplam = 0;
        foreach (['yedek', 'geri_yukleme', 'kopya', 'sifreleme', 'baglanti', 'kilit_bekleme'] as $ad) {
            $toplam += $this->pozitifTamSayi("sure_siniri.{$ad}");
        }

        return 3 * $toplam + 3600;
    }

    public function durumKlasoru(): string
    {
        return $this->yol('durum_klasoru');
    }

    public function kaliciKok(): string
    {
        return $this->yol('kalici_kok');
    }

    public function imzaAnahtariYolu(): string
    {
        return $this->yol('imza_anahtari');
    }

    public function istekKlasoru(): string
    {
        return $this->yol('istek_klasoru');
    }

    public function ayarKopyasiYolu(): string
    {
        return $this->yol('ayar_kopyasi');
    }

    public function parolaDosyasi(): ?string
    {
        $yol = config('yedekleme.parola_dosyasi');

        return is_string($yol) && $yol !== '' ? $yol : null;
    }

    public function baglantiAdi(): string
    {
        $ad = config('yedekleme.baglanti');

        return is_string($ad) && $ad !== '' ? $ad : 'pgsql';
    }

    public function rol(): ?string
    {
        $rol = config('yedekleme.rol');

        return is_string($rol) && $rol !== '' ? $rol : null;
    }

    public function arac(string $ad): string
    {
        $yol = config("yedekleme.araclar.{$ad}") ?? config("yedekleme.{$ad}");

        if (! is_string($yol) || ! str_starts_with($yol, '/')) {
            throw new YapilandirmaHatasi("yedekleme aracı '{$ad}' için mutlak bir yol tanımlı değil.");
        }

        return $yol;
    }

    /** @return list<string> */
    public function verisiAlinmayanTablolar(): array
    {
        $tablolar = config('yedekleme.verisi_alinmayan_tablolar');

        return is_array($tablolar) ? array_values(array_filter($tablolar, 'is_string')) : [];
    }

    /**
     * Saniye cinsinden süre sınırı. Yalnız pozitif tam sayı (ya da onun
     * metni) kabul edilir: `abc`, `0`, `-10`, `1.5` açık hatadır — Symfony
     * Process'te 0 "sınırsız" demektir (G9).
     *
     * Etkin bir süre bütçesi varsa ("Bağlantıyı Sına") değer kalan süreye
     * kısalır; son zaman geçtiyse çağrı yapılmaz (SureButcesi, BY-016-R4).
     *
     * @throws YapilandirmaHatasi
     * @throws HedefHatasi Etkin bütçenin son zamanı geçti
     */
    public function sureSiniri(string $ad): int
    {
        return SureButcesi::sinirla($this->pozitifTamSayi("sure_siniri.{$ad}"));
    }

    public function kopyaDenemeSayisi(): int
    {
        return $this->pozitifTamSayi('kopya_deneme');
    }

    public function kopyaBeklemeGunu(): int
    {
        return $this->pozitifTamSayi('kopya_bekleme_gun');
    }

    /**
     * Kuyruktaki "üstüne geri yükle" isteğinin en çok kaç dakikalık olabileceği
     * (YF-02): çalıştırıcı geç kalınca bayat istek saatler sonra kesinti başlatmasın.
     *
     * @throws YapilandirmaHatasi
     */
    public function ustuneIstekOmruDakika(): int
    {
        return $this->pozitifTamSayi('ustune_istek_omru_dk');
    }

    /**
     * Aynı anda bekleyebilecek farklı ad kopyası (`erp_geri_*`) sayısı (YF-43):
     * her kopya veri diskinde canlı kadar yer tutar.
     *
     * @throws YapilandirmaHatasi
     */
    public function bekleyenKopyaSiniri(): int
    {
        return $this->pozitifTamSayi('bekleyen_kopya_siniri');
    }

    /** İşleri ayrı servis hesabının çalıştırıcısı mı yapıyor (BY-025) */
    public function servisCalistiricisi(): bool
    {
        return config('yedekleme.calistirici') === 'servis';
    }

    /**
     * Üstüne geri yüklemede durdurulan kuyruk servisi (systemd birim adı);
     * boşsa yönetilmez.
     *
     * @throws YapilandirmaHatasi Ad systemd birim adı biçiminde değil
     */
    public function kuyrukServisi(): ?string
    {
        $ad = config('yedekleme.kuyruk_servisi');

        if ($ad === null || $ad === '') {
            return null;
        }

        if (! is_string($ad) || preg_match('/^[A-Za-z0-9][A-Za-z0-9@._-]{0,63}$/D', $ad) !== 1) {
            throw new YapilandirmaHatasi('yedekleme.kuyruk_servisi geçerli bir systemd servis adı değil.');
        }

        return $ad;
    }

    public function sinamaGeriYuklemesi(): bool
    {
        return config('yedekleme.sinama_geri_yukleme') === true;
    }

    public function islemDurumu(): IslemDurumu
    {
        return new IslemDurumu($this->durumKlasoru().'/islem.json', $this->kaliciKok());
    }

    /**
     * Yayın kilidi (BY-013-R1): `deploy.sh` yayın boyunca tutar, üstüne geri
     * yükleme başlarken beklemeden almayı dener. İşlem kilidinden (islemDurumu()->kilit())
     * AYRIDIR: yayın öncesi yedeği servisten isterken ikisi birbirini beklemez.
     * Sunucuda dosyayı kurulum betiği açar (yayın kullanıcısı salt okunur açıp `flock`).
     */
    public function yayinKilidi(): YedekKilidi
    {
        return new YedekKilidi($this->yayinKilidiYolu(), $this->kaliciKok());
    }

    public function yayinKilidiYolu(): string
    {
        return $this->durumKlasoru().'/yayin.kilit';
    }

    /**
     * İstek dosyası sahibi olarak kabul edilen web kullanıcısının uid'si
     * (BY-025-R1). Sahip, dosyayı açan sürecin kullanıcısıdır; çekirdek verir,
     * istek içeriğinden bağımsızdır. Tanımsızsa bu sürecin kendi kullanıcısı:
     * geçiş kipinde web ve çalıştırıcı aynı hesaptır; servis kipinde tanımsız
     * kalırsa web istekleri reddedilir (kurulum betiği servis tanımına yazar).
     *
     * @throws YapilandirmaHatasi
     */
    public function webUid(): int
    {
        return $this->uid('web_uid') ?? $this->surecUid();
    }

    /**
     * Kullanıcısız yayın öncesi yedek isteğini bırakabilen yayın kullanıcısının
     * uid'si; tanımsızsa böyle istek kabul edilmez (geçiş kipinde yayın yedeği
     * kendisi alır, istek bırakmaz).
     *
     * @throws YapilandirmaHatasi Değer geçersiz ya da web kullanıcısıyla aynı (yayın istisnası taklit edilebilirdi)
     */
    public function yayinUid(): ?int
    {
        $uid = $this->uid('yayin_uid');

        if ($uid !== null && $uid === $this->webUid()) {
            throw new YapilandirmaHatasi('yedekleme.yayin_uid web kullanıcısıyla aynı olamaz.');
        }

        return $uid;
    }

    /**
     * @throws YapilandirmaHatasi
     */
    private function uid(string $anahtar): ?int
    {
        $deger = config("yedekleme.{$anahtar}");

        if ($deger === null || $deger === '') {
            return null;
        }

        // root (0) bir web / yayın kullanıcısı olamaz; sınır 32 bitlik uid'nin geçerli üst değeri
        if ((is_int($deger) || (is_string($deger) && preg_match('/^[1-9][0-9]{0,9}$/D', $deger) === 1))
            && (int) $deger >= 1 && (int) $deger <= 4_294_967_294) {
            return (int) $deger;
        }

        throw new YapilandirmaHatasi("yedekleme.{$anahtar} pozitif bir kullanıcı numarası (uid) olmalı.");
    }

    /**
     * Bu sürecin etkin kullanıcısı: Linux'ta /proc/self'in sahibi (posix
     * uzantısına bağlanmamak için; getmyuid() betik dosyasının sahibini verir).
     *
     * @throws YapilandirmaHatasi
     */
    private function surecUid(): int
    {
        clearstatcache(true, '/proc/self');
        $bilgi = @stat('/proc/self');

        if ($bilgi === false) {
            throw new YapilandirmaHatasi('Sürecin kullanıcısı okunamadı (/proc/self); yedekleme.web_uid tanımlanmalı.');
        }

        return $bilgi['uid'];
    }

    /**
     * @throws YapilandirmaHatasi
     */
    private function pozitifTamSayi(string $anahtar): int
    {
        $deger = config("yedekleme.{$anahtar}");

        if (is_int($deger) && $deger > 0) {
            return $deger;
        }

        if (is_string($deger) && preg_match('/^[1-9][0-9]{0,8}$/D', $deger) === 1) {
            return (int) $deger;
        }

        throw new YapilandirmaHatasi("yedekleme.{$anahtar} pozitif bir tam sayı olmalı (geçersiz değer sınırsıza dönüştürülmez).");
    }

    private function yol(string $anahtar): string
    {
        $yol = config("yedekleme.{$anahtar}");

        if (! is_string($yol) || ! str_starts_with($yol, '/')) {
            throw new YapilandirmaHatasi("yedekleme.{$anahtar} mutlak bir yol olmalı.");
        }

        return rtrim($yol, '/');
    }
}
