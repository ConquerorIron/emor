<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Hedefler;

use App\Models\YedekHedefi;
use App\Services\Yedekleme\AtomikDosya;
use App\Services\Yedekleme\HedefTuru;
use App\Services\Yedekleme\KaliciKlasor;
use App\Services\Yedekleme\YedeklemeYapilandirmasi;
use RuntimeException;

/**
 * "Ağ paylaşımı (NAS)" hedefi (README §4.6, BY-023): sunucuya önceden
 * bağlanmış (mount) bir paylaşımın altındaki klasör. Bağlamayı kurulum yapar
 * (`admin` + `sudo`); uygulama yalnız yazar.
 *
 * Asıl tehlike: paylaşım düşünce bağlantı noktası sunucunun kendi diskinde
 * boş bir klasör olarak kalır; oraya yazılan yedek "kopyalandı" sayılırsa
 * sunucu dışında kopya yokken var görünür. Bu yüzden HER işlem önce bağlantı
 * noktasının gerçekten bağlı olduğunu (`/proc/self/mountinfo`) ve klasörün
 * onun altında olduğunu (bağlantıları çözülmüş yolla) denetler; değilse
 * BAGLI_DEGIL. Yazımdan sonra dosyanın paylaşımın aygıtında durduğu da
 * denetlenir: denetimle yazım arasında paylaşım düşerse kopya sayılmaz.
 *
 * Asılı kalan bir ağ paylaşımı dosya çağrılarını süresiz bekletebilir (PHP'de
 * süre sınırı konamaz): paylaşım `soft` + `timeo` seçenekleriyle bağlanmalı
 * (kurulum dokümanı, BY-024).
 */
final class NasBaglayicisi implements HedefBaglayicisi
{
    private const BAGLANTI_TABLOSU = '/proc/self/mountinfo';

    private const MUTLAK_YOL = '#^(/[^/\0]+)+$#D';

    public function __construct(
        private readonly YedekHedefi $hedef,
        private readonly YedeklemeYapilandirmasi $yapilandirma,
    ) {}

    public static function tanim(HedefTuru $tur = HedefTuru::Nas): HedefTuruTanimi
    {
        return new HedefTuruTanimi(HedefTuru::Nas, [
            new HedefAlani('baglanti_noktasi', 'yedekleme.hedef.alan.baglanti_noktasi', zorunlu: true, kurallar: ['string', 'max:500', 'regex:'.self::MUTLAK_YOL]),
            new HedefAlani('klasor', 'yedekleme.hedef.alan.klasor', zorunlu: true, kurallar: ['string', 'max:500', 'regex:'.self::MUTLAK_YOL]),
        ], sunucuDisi: true);
    }

    public function baglan(): void
    {
        [$klasor] = $this->dogrula();

        if (! is_writable($klasor)) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, "NAS klasörü yazılamıyor: {$klasor}");
        }
    }

    public function yaz(string $yerelYol, string $ad): string
    {
        $this->adDenetle($ad);
        [$klasor, $aygit] = $this->dogrula();
        $hedef = $klasor.'/'.$ad;

        $this->atomikKopyala($yerelYol, $hedef);
        clearstatcache(true);
        $yazilan = @stat($hedef);

        if ($yazilan === false || $yazilan['dev'] !== $aygit) {
            @unlink($hedef);

            throw new HedefHatasi(HedefHatasi::BAGLI_DEGIL, 'NAS yazım sırasında ayrıldı; dosya paylaşıma yazılmadı, kopya sayılmadı.');
        }

        return $ad;
    }

    public function oku(string $uzakKimlik, string $yerelYol): void
    {
        $this->adDenetle($uzakKimlik);
        [$klasor] = $this->dogrula();
        $kaynak = $klasor.'/'.$uzakKimlik;
        $gecici = AtomikDosya::geciciAd($yerelYol);

        try {
            if (! is_file($kaynak) || ! @copy($kaynak, $gecici) || ! @rename($gecici, $yerelYol)) {
                throw new HedefHatasi(HedefHatasi::OKUNAMADI, "NAS'taki dosya okunamadı: {$uzakKimlik}");
            }
        } finally {
            if (file_exists($gecici)) {
                @unlink($gecici);
            }
        }
    }

    public function sil(string $uzakKimlik): void
    {
        $this->adDenetle($uzakKimlik);
        [$klasor] = $this->dogrula();
        $yol = $klasor.'/'.$uzakKimlik;

        if (file_exists($yol) && ! @unlink($yol)) {
            throw new HedefHatasi(HedefHatasi::SILINEMEDI, "NAS'taki dosya silinemedi: {$uzakKimlik}");
        }
    }

    public function listele(): array
    {
        [$klasor] = $this->dogrula();
        $dosyalar = @scandir($klasor);

        if ($dosyalar === false) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, 'NAS klasörü listelenemedi.');
        }

        return array_values(array_filter($dosyalar, fn (string $ad): bool => preg_match('/^erp_.+\.(dump|gpg)$/D', $ad) === 1));
    }

    public function kalanAlan(): ?int
    {
        [$klasor] = $this->dogrula();
        $bos = @disk_free_space($klasor);

        return $bos === false ? null : (int) $bos;
    }

    /**
     * Geçici ada yaz, diske zorla, tek adımda taşı, klasörü eşitle (D3).
     * `AtomikDosya::kopyala` kullanılmaz: dosya sistemleri arası kopyada
     * (yerel disk → paylaşım) `stream_copy_to_stream` kaynağı sonuna kadar
     * okumadan kopyalar, `feof` false kalır ve başarılı kopya başarısız
     * sayılır. Burada parça parça okunur. İzin (0640) denenir ama paylaşım
     * izinleri kendi kurallarıyla yönetebilir (CIFS); dosya zaten şifrelidir.
     *
     * @throws HedefHatasi
     */
    private function atomikKopyala(string $kaynak, string $hedef): void
    {
        $gecici = AtomikDosya::geciciAd($hedef);
        $giris = @fopen($kaynak, 'rb');
        $cikis = $giris === false ? false : @fopen($gecici, 'xb');

        try {
            if ($giris === false || $cikis === false) {
                throw new HedefHatasi(HedefHatasi::YAZILAMADI, 'Yedek NAS\'a yazılmak üzere açılamadı.');
            }

            @chmod($gecici, 0640);

            while (! feof($giris)) {
                $parca = fread($giris, 1048576);

                if ($parca === false || fwrite($cikis, $parca) !== strlen($parca)) {
                    throw new HedefHatasi(HedefHatasi::YAZILAMADI, "NAS'a yazılamadı: {$hedef}");
                }
            }

            $yazildi = fflush($cikis) && fsync($cikis);
            fclose($cikis);
            $cikis = false;

            if (! $yazildi || ! @rename($gecici, $hedef)) {
                throw new HedefHatasi(HedefHatasi::YAZILAMADI, "NAS'a yazılamadı: {$hedef}");
            }

            KaliciKlasor::esitle(dirname($hedef));
        } catch (RuntimeException $hata) {
            throw new HedefHatasi(HedefHatasi::YAZILAMADI, $hata->getMessage());
        } finally {
            if ($giris !== false) {
                fclose($giris);
            }
            if ($cikis !== false) {
                fclose($cikis);
            }
            if (file_exists($gecici)) {
                @unlink($gecici);
            }
        }
    }

    /**
     * Bağlantı noktası bağlı, klasör var ve onun altında. PHP'nin yol ve
     * durum önbellekleri temizlenir: paylaşım az önce düştüyse eski sonuç
     * kullanılmasın.
     *
     * @return array{0: string, 1: int} [klasörün gerçek yolu, paylaşımın aygıt numarası]
     *
     * @throws HedefHatasi
     */
    private function dogrula(): array
    {
        $nokta = $this->hedef->baglanti['baglanti_noktasi'] ?? null;
        $klasor = $this->hedef->baglanti['klasor'] ?? null;

        if (! is_string($nokta) || ! is_string($klasor) || preg_match(self::MUTLAK_YOL, $nokta) !== 1 || preg_match(self::MUTLAK_YOL, $klasor) !== 1) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'NAS bağlantı noktası ve klasörü mutlak yol olmalı.');
        }

        clearstatcache(true);
        $gercekNokta = realpath($nokta);

        if ($gercekNokta === false || $gercekNokta === '/' || ! $this->baglantiNoktasiMi($gercekNokta)) {
            throw new HedefHatasi(HedefHatasi::BAGLI_DEGIL, "NAS bağlı değil: {$nokta} bir bağlantı noktası değil.");
        }

        $gercekKlasor = realpath($klasor);

        if ($gercekKlasor === false || ! is_dir($gercekKlasor)) {
            throw new HedefHatasi(HedefHatasi::ERISILEMEDI, "NAS klasörü yok: {$klasor}");
        }

        if ($gercekKlasor !== $gercekNokta && ! str_starts_with($gercekKlasor, $gercekNokta.'/')) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'NAS klasörü bağlantı noktasının altında değil.');
        }

        $aygit = @stat($gercekNokta);
        $klasorAygiti = @stat($gercekKlasor);

        if ($aygit === false || $klasorAygiti === false || $aygit['dev'] !== $klasorAygiti['dev']) {
            throw new HedefHatasi(HedefHatasi::BAGLI_DEGIL, 'NAS klasörü paylaşımın aygıtında değil.');
        }

        return [$gercekKlasor, $aygit['dev']];
    }

    /**
     * Çekirdeğin bağlantı tablosunda bu yol bir bağlantı noktası mı. Tablo
     * okunamıyorsa: aygıt numarası üst klasörünkinden farklı mı.
     */
    private function baglantiNoktasiMi(string $yol): bool
    {
        $tablo = @file(self::BAGLANTI_TABLOSU, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($tablo === false) {
            $kendi = @stat($yol);
            $ust = @stat(dirname($yol));

            return $kendi !== false && $ust !== false && $kendi['dev'] !== $ust['dev'];
        }

        foreach ($tablo as $satir) {
            $alanlar = explode(' ', $satir);

            // mountinfo(5): 5. alan bağlantı noktası; boşluk vb. sekizlik kaçışla (\040)
            if (isset($alanlar[4]) && $this->kacisCoz($alanlar[4]) === $yol) {
                return true;
            }
        }

        return false;
    }

    private function kacisCoz(string $alan): string
    {
        return (string) preg_replace_callback('/\\\\([0-7]{3})/', fn (array $e): string => chr((int) octdec($e[1])), $alan);
    }

    /** Yalnız düz dosya adı: hedef klasörünün dışına çıkılamaz */
    private function adDenetle(string $ad): void
    {
        if (preg_match('/^[A-Za-z0-9.][A-Za-z0-9._+-]{0,200}$/D', $ad) !== 1 || str_contains($ad, '..')) {
            throw new HedefHatasi(HedefHatasi::YAPILANDIRMA, 'Geçersiz hedef dosya adı.');
        }
    }
}
