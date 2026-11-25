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
 * - Tutarlar dosyadaki değerdir, yeniden hesaplanmaz; 4 basamağa yuvarlanır,
 *   saklama sınırı (decimal(20,4): 16 tam basamak) yuvarlamadan SONRA denetlenir (G14).
 * - Tarih ve sayı tam biçim denetiminden geçer, taşan tarih kaydırılmaz (G11).
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
    ];

    /** decimal(20,4): en çok 16 tam basamak */
    private const TUTAR_SINIRI = '10000000000000000';

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
        $sayi = IceAktarimTablosu::sayi($degerler[$alan] ?? null);

        if ($sayi === null) {
            if ($zorunlu) {
                $hatalar[] = [$alan, '', 'zorunlu'];
            }

            return null;
        }

        $metin = $sayi === false || ! is_finite($sayi) ? null : number_format(round($sayi, 4), 4, '.', '');

        if ($metin === null || bccomp(ltrim($metin, '-'), self::TUTAR_SINIRI, 4) >= 0) {
            $hatalar[] = [$alan, $this->gosterim($degerler[$alan] ?? null), 'tutar_gecersiz'];

            return null;
        }

        return $metin;
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
