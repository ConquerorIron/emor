<?php

declare(strict_types=1);

namespace App\Services\Entegrator\IceAktarim;

use App\Models\EFatura;
use App\Services\Kesinti\IceAktarimTablosu;
use App\Support\Metin;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;

/**
 * Statik kaynağın (EK-06) bir dosya türü: Logo e-Fatura listesi, e-Arşiv
 * fatura listesi ya da e-SMM makbuz listesi. Alt sınıf yalnız kolon eşlemesini
 * ve satırın alanlara dönüşümünü verir; okuma, doğrulama özeti, kayıt ve
 * geri alma EFaturaIceAktarimi'ndedir (tek akış).
 *
 * Kurallar (docs/efatura/Ek-Isler-Jira.md §4.4):
 * - Tutarlar dosyadaki değerdir, yeniden hesaplanmaz; float'tan geçmeden 4
 *   basamağa (kur 6) yuvarlanır, saklama sınırı (decimal(20,4): 16 tam basamak;
 *   kur decimal(20,6): 14) yuvarlamadan SONRA denetlenir (G14).
 * - Tarih ve sayı tam biçim denetiminden geçer, taşan tarih kaydırılmaz,
 *   biçimi tanınmayan sayı başka bir sayıya dönmez (G11).
 * - RED / iptal renkten değil kolondan okunur.
 */
abstract class StatikBelgeTuru
{
    /** Dosyadan gelen (yeniden yüklemede güncellenen) alanlar; elle işaretler dışarıda */
    public const KAYNAK_ALANLARI = [
        'belge_turu', 'ettn', 'belge_no', 'belge_tarihi', 'olusturma_zamani',
        'gonderici_vkn', 'gonderici_unvan', 'alici_vkn', 'alici_unvan', 'senaryo', 'fatura_tipi',
        'para_birimi', 'tutar', 'vergi_tutari', 'brut_tutar', 'stopaj_tutari',
        'durum', 'durum_aciklamasi', 'yanit_aciklamasi', 'gib_durum_kodu', 'gib_durum_aciklamasi',
        // Rapor verileri (2026-11-26; UBL'li kaynaklarda UBL'den gelir, Logo'da dosyadan)
        'indirim_tutari', 'vergi_haric_tutar', 'vergi_dahil_tutar', 'kur',
    ];

    /**
     * İsteğe bağlı rapor alanları: dosyada boş gelen (kolonu olmayan eski biçimli
     * liste, kısa yapıştırma) mevcut değeri SİLMEZ — vergi kalemleriyle aynı kural
     * (inceleme B-2 / E-1). Önizleme ve kayıt aynı yardımcıdan geçer.
     */
    public const BOSKEN_KORUNAN_ALANLAR = ['indirim_tutari', 'vergi_haric_tutar', 'vergi_dahil_tutar', 'kur'];

    /**
     * Mevcut satırın güncellenecek alanları: kaynak alanları, boş gelen
     * isteğe bağlı rapor alanları hariç. Yeni satır eklenirken kullanılmaz (toplu
     * insert'te bütün satırların anahtar kümesi aynı kalmalı).
     *
     * @param  array<string, mixed>  $kayit  `donustur`'ün alanları
     * @return array<string, mixed>
     */
    public static function guncellemeAlanlari(array $kayit): array
    {
        $alanlar = array_intersect_key($kayit, array_flip(self::KAYNAK_ALANLARI));

        foreach (self::BOSKEN_KORUNAN_ALANLAR as $alan) {
            if (array_key_exists($alan, $alanlar) && $alanlar[$alan] === null) {
                unset($alanlar[$alan]);
            }
        }

        return $alanlar;
    }

    /**
     * `donustur`'ün alanlarında isteğe bağlı vergi kalemleri listesi (kolon değil):
     * verilirse kayıtta faturanın kalemleri bununla baştan yazılır
     * (efatura_vergi_kalemleri; UblVerisi::$kalemler ile aynı şekil). Yalnız
     * dosyası oran bazında vergi taşıyan tür (Logo) verir.
     */
    public const VERGI_KALEMLERI = 'vergi_kalemleri';

    /** decimal(20,4): en çok 16 tam basamak */
    private const TUTAR_SINIRI = '10000000000000000';

    /** decimal(20,6): en çok 14 tam basamak (kur) */
    private const KUR_SINIRI = '100000000000000';

    /**
     * Alan => kabul edilen başlıklar (Excel başlığı adından tanınır).
     *
     * @return array<string, list<string>>
     */
    abstract public function kolonlar(): array;

    /** @return list<string> */
    abstract public function zorunlular(): array;

    /** e-Arşiv tanımında 'fatura' | 'makbuz'; Logo'da null */
    abstract public function belgeTuru(): ?string;

    /**
     * Satırı fatura alanlarına çevirir.
     *
     * @param  array<string, mixed>  $degerler  okuyucunun eşlediği hücreler
     * @param  list<string>  $sirketVknleri  şirketin VKN'leri (entegratör tanımları)
     * @return array{0: array<string, mixed>, 1: list<array{0: string, 1: string, 2: string}>} [alanlar (belge_anahtari dahil), hatalar [alan, değer, kod]]
     */
    abstract public function donustur(array $degerler, array $sirketVknleri): array;

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    protected function zorunluMetin(array $degerler, string $alan, int $azami, array &$hatalar): ?string
    {
        $metin = $this->metin($degerler[$alan] ?? null);

        if ($metin === null) {
            $hatalar[] = [$alan, '', 'zorunlu'];

            return null;
        }

        return $this->sinirli($alan, $metin, $azami, $hatalar);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    protected function secimliMetin(array $degerler, string $alan, ?int $azami, array &$hatalar): ?string
    {
        $metin = $this->metin($degerler[$alan] ?? null);

        return $metin === null || $azami === null ? $metin : $this->sinirli($alan, $metin, $azami, $hatalar);
    }

    /**
     * VKN (10) ya da TCKN (11) — metin: baştaki sıfır korunur.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    protected function vkn(array $degerler, string $alan, bool $zorunlu, array &$hatalar): ?string
    {
        $vkn = $this->metin($degerler[$alan] ?? null);

        if ($vkn === null) {
            if ($zorunlu) {
                $hatalar[] = [$alan, '', 'zorunlu'];
            }

            return null;
        }

        $vkn = Metin::bosluksuz($vkn);

        if (preg_match('/^\d{10,11}\z/', $vkn) !== 1) {
            $hatalar[] = [$alan, $vkn, 'vkn_gecersiz'];

            return null;
        }

        return $vkn;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    protected function tarih(array $degerler, string $alan, array &$hatalar): ?string
    {
        $tarih = IceAktarimTablosu::tarih($degerler[$alan] ?? null);

        if ($tarih === null || $tarih === false) {
            $hatalar[] = [$alan, $this->gosterim($degerler[$alan] ?? null), $tarih === null ? 'zorunlu' : 'tarih_gecersiz'];

            return null;
        }

        return $tarih;
    }

    /**
     * Tutar: 4 basamağa yuvarlanmış ondalık metin. Sınır yuvarlamadan sonra.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    protected function tutar(array $degerler, string $alan, bool $zorunlu, array &$hatalar): ?string
    {
        return $this->ondalik($degerler, $alan, $zorunlu, $hatalar, 4, self::TUTAR_SINIRI);
    }

    /**
     * Kur: kolonu decimal(20,6) — 6 basamak, en çok 14 tam basamak (UBL'li
     * kaynakların UblOkuyucu::kur'uyla aynı sınır; inceleme B-7 / E-5).
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    protected function kurDegeri(array $degerler, string $alan, array &$hatalar): ?string
    {
        return $this->ondalik($degerler, $alan, false, $hatalar, 6, self::KUR_SINIRI);
    }

    /**
     * Ondalık sayı, float'tan geçmeden (G14): metin tam biçim denetiminden geçer
     * (G11 — kabul edilmeyen biçim başka bir sayıya dönmez, `tutar_gecersiz`),
     * bcmath ile yarımı yukarı yuvarlanır; sınır yuvarlamadan SONRA denetlenir.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    private function ondalik(array $degerler, string $alan, bool $zorunlu, array &$hatalar, int $basamak, string $sinir): ?string
    {
        $ham = $degerler[$alan] ?? null;
        $sayi = $this->sayiMetni($ham);

        if ($sayi === null) {
            if ($zorunlu) {
                $hatalar[] = [$alan, '', 'zorunlu'];
            }

            return null;
        }

        $metin = $sayi === false ? null : $this->yuvarla($sayi, $basamak);

        if ($metin === null || bccomp(ltrim($metin, '-'), $sinir, $basamak) >= 0) {
            $hatalar[] = [$alan, $this->gosterim($ham), 'tutar_gecersiz'];

            return null;
        }

        return $metin;
    }

    /**
     * Hücrenin sayısı ondalık metin olarak ("-1234.56"): boşsa ya da "-" ise
     * null, sayı değilse false.
     *
     * - Excel sayı hücresi (double): en kısa geri dönen gösterimiyle okunur;
     *   Excel'in sakladığından (15 anlamlı basamak) fazlası güvenilmez, ret.
     * - Metin (kopyala-yapıştır): para simgesi (₺, TL) ve boşluklar atılır; yalnız
     *   Türkçe biçim (`1.234,56`, `1234,56`) ya da noktalı ondalık (`1234.5`)
     *   kabul edilir. İngilizce binlik (`1,234.56`), üslü, parantezli, harfli
     *   değer ve binlik mi ondalık mı olduğu belirsiz `43.125` reddedilir.
     *   Ortak okuyucu (IceAktarimTablosu::sayi) Kesinti'de aynı kalır.
     */
    private function sayiMetni(mixed $ham): string|false|null
    {
        if (is_int($ham)) {
            return (string) $ham;
        }

        if (is_float($ham)) {
            return is_finite($ham) ? $this->doubleMetni($ham) : false;
        }

        // Boşluklar (bölünmez dahil) ve baştaki / sondaki para simgesi atılır
        $metin = (string) preg_replace('/[\s\p{Z}]+/u', '', (string) $ham);
        $metin = (string) preg_replace('/^(?:₺|TL)|(?:₺|TL)\z/iu', '', $metin);

        // Excel'de "-" yok / sıfır anlamında (IceAktarimTablosu::sayi ile aynı)
        if ($metin === '' || in_array($metin, ['-', '–', '—'], true)) {
            return null;
        }

        // Tek nokta + tam üç hane: Türkçe binlik mi, noktalı ondalık mı bilinemez
        if (preg_match('/^-?\d{1,3}\.\d{3}\z/', $metin) === 1) {
            return false;
        }
        if (preg_match('/^-?(\d{1,3}(\.\d{3})+|\d+)(,\d+)?\z/', $metin) === 1) {
            return str_replace(',', '.', str_replace('.', '', $metin));
        }

        return preg_match('/^-?\d+\.\d+\z/', $metin) === 1 ? $metin : false;
    }

    /** Double'ın en kısa geri dönen gösterimi (var_export), üssü bcmath ile açılmış. */
    private function doubleMetni(float $sayi): string|false
    {
        [$katsayi, $us] = array_pad(explode('E', strtoupper(var_export($sayi, true)), 2), 2, '0');

        if (strlen(trim(str_replace(['-', '.'], '', $katsayi), '0')) > 15) {
            return false;
        }

        return $us === '0' ? $katsayi : bcmul($katsayi, bcpow('10', (string) (int) $us, 30), 30);
    }

    /** Yarımı sıfırdan uzağa yuvarlar (bcadd kırpar); "-0.0000" üretmez. */
    private function yuvarla(string $sayi, int $basamak): string
    {
        $eksi = str_starts_with($sayi, '-');
        $yarim = ($eksi ? '-' : '').'0.'.str_repeat('0', $basamak).'5';
        $sonuc = bcadd($sayi, $yarim, $basamak);

        return bccomp($sonuc, '0', $basamak) === 0 ? bcadd('0', '0', $basamak) : $sonuc;
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    protected function paraBirimi(array $degerler, string $alan, array &$hatalar): ?string
    {
        $kod = $this->metin($degerler[$alan] ?? null);

        if ($kod === null) {
            $hatalar[] = [$alan, '', 'zorunlu'];

            return null;
        }

        $kod = Metin::buyukHarf($kod);

        if (preg_match('/^[A-Z]{3}\z/', $kod) !== 1) {
            $hatalar[] = [$alan, $kod, 'para_birimi_gecersiz'];

            return null;
        }

        return $kod;
    }

    /**
     * Logo "Oluşturma Tarihi": metin ("04.01.2026 18:49:46", İstanbul saati)
     * ya da Excel tarih-saat seri numarası. Saklanan an UTC'dir (G17).
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    protected function zaman(array $degerler, string $alan, array &$hatalar): ?string
    {
        $ham = $degerler[$alan] ?? null;
        $saatDilimi = (string) config('entegrator.izibiz.saat_dilimi');

        if ($ham === null || $ham === '') {
            return null;
        }

        if (is_int($ham) || is_float($ham)) {
            $yerel = ExcelTarihi::excelToDateTimeObject((float) $ham)->format('Y-m-d H:i:s');

            return CarbonImmutable::parse($yerel, $saatDilimi)->utc()->format('Y-m-d H:i:s');
        }

        $metin = trim((string) $ham);
        $an = CarbonImmutable::createFromFormat('!d.m.Y H:i:s', $metin, $saatDilimi);

        // Taşan değer (31.02) kaydırılmaz: geri çevrilen biçim girdiyle birebir aynı olmalı
        if ($an === null || $an->format('d.m.Y H:i:s') !== $metin) {
            $hatalar[] = [$alan, $metin, 'zaman_gecersiz'];

            return null;
        }

        return $an->utc()->format('Y-m-d H:i:s');
    }

    protected function metin(mixed $deger): ?string
    {
        if ($deger === null) {
            return null;
        }

        $metin = trim(is_float($deger) && floor($deger) === $deger ? number_format($deger, 0, '', '') : (string) $deger);

        return $metin === '' ? null : $metin;
    }

    /** Belge anahtarının parçası: boşluksuz, Türkçe büyük harf */
    protected function anahtarParcasi(string $metin): string
    {
        return Metin::buyukHarf(Metin::bosluksuz($metin));
    }

    /**
     * e-Arşiv / e-SMM iptal-itiraz kolonları: doluysa kaynakta RED (iptal).
     *
     * @return array{durum: string|null, durum_aciklamasi: string|null, yanit_aciklamasi: string|null}
     */
    protected function iptal(array $degerler): array
    {
        $iptal = $this->metin($degerler['iptal'] ?? null);

        if ($iptal === null) {
            return ['durum' => null, 'durum_aciklamasi' => null, 'yanit_aciklamasi' => null];
        }

        $tarih = $this->metin($degerler['iptal_tarihi'] ?? null);
        $tarihMetni = IceAktarimTablosu::tarih($degerler['iptal_tarihi'] ?? null);
        if (is_string($tarihMetni)) {
            $tarih = CarbonImmutable::parse($tarihMetni)->format('d.m.Y');
        }

        return [
            'durum' => EFatura::DURUM_IPTAL,
            'durum_aciklamasi' => mb_substr($iptal, 0, 1000),
            'yanit_aciklamasi' => $tarih,
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $hatalar
     */
    private function sinirli(string $alan, string $metin, int $azami, array &$hatalar): ?string
    {
        if (mb_strlen($metin) > $azami) {
            $hatalar[] = [$alan, mb_substr($metin, 0, 40).'…', 'cok_uzun'];

            return null;
        }

        return $metin;
    }

    private function gosterim(mixed $deger): string
    {
        return mb_substr(trim((string) $deger), 0, 40);
    }
}
