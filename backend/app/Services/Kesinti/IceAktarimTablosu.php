<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Support\Metin;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelTarihi;
use Throwable;

/**
 * Kesinti icmallerine içe aktarılan tablo: Excel dosyası (.xlsx) ya da
 * Excel'den kopyalanıp yapıştırılan metin (sekmeyle ayrılmış). Başlık satırı
 * adlarından tanınır; kolonların sırası ve fazlası (ör. eski HAKEDİŞ / AY)
 * önemsizdir. Her icmal kendi kolon tanımını verir: alan => kabul edilen
 * başlıklar (ilki standart ad; yapıştırılan metinde başlık yoksa bu sıra
 * esas alınır).
 */
final class IceAktarimTablosu
{
    /** Başlık satırı ilk bu kadar satırda aranır (Excel'de üstte başlık/toplam satırları var) */
    private const BASLIK_ARAMA_SATIRI = 20;

    /** Bir satırın başlık sayılması için tanınan en az kolon sayısı */
    private const ENAZ_BASLIK_ESLESMESI = 3;

    /**
     * Excel okunurken gereken bellek: ~2.400 satırlık İşgücü dosyası 111 MB'a
     * çıktı (2026-10-30 ölçümü); PHP varsayılanı 128 MB. Yalnız bu istekte.
     */
    private const EXCEL_BELLEK_MB = 512;

    /**
     * Başlık ve seçenek karşılaştırma anahtarı: Türkçe büyük harf, yalnız harf
     * ve rakam ("ADI - SOYADI" = "Adı Soyadı", "MİKTAR (saat)" = "MİKTAR SAAT").
     */
    public static function anahtar(string $metin): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', Metin::buyukHarf($metin));
    }

    /**
     * Zorunlu kolonların hepsini taşıyan HER sayfa okunur (aynı dosyada başka
     * düzende sayfalar olabilir: Kamp dosyasında oda ve malzeme sayfaları).
     * Hiçbir sayfa yetmiyorsa en çok kolonu tanınan sayfanın kolonları döner;
     * hangi zorunlu kolonların eksik olduğu böylece gösterilebilir.
     *
     * @param  array<string, list<string>>  $kolonlar
     * @param  list<string>  $zorunlular
     * @return array{satirlar: list<array{satir: int, sayfa: string|null, degerler: array<string, mixed>}>, kolonlar: list<string>, hata: string|null}
     */
    public static function excel(string $yol, array $kolonlar, array $zorunlular = []): array
    {
        self::bellekSiniriniYukselt();

        try {
            $okuyucu = IOFactory::createReader('Xlsx');
            $okuyucu->setReadDataOnly(true);
            $kitap = $okuyucu->load($yol);
        } catch (Throwable) {
            return ['satirlar' => [], 'kolonlar' => [], 'hata' => 'dosya_okunamadi'];
        }

        $satirlar = [];
        $okunanKolonlar = [];
        $enIyiEslesme = null;

        foreach ($kitap->getWorksheetIterator() as $sayfa) {
            $sonSatir = $sayfa->getHighestDataRow();
            $sonKolon = $sayfa->getHighestDataColumn();
            $ust = $sayfa->rangeToArray("A1:{$sonKolon}".min($sonSatir, self::BASLIK_ARAMA_SATIRI), null, false, false, false);
            $baslik = self::baslikBul($ust, $kolonlar);

            if ($baslik === null) {
                continue;
            }

            [$baslikSatiri, $eslesme] = $baslik;

            if (count($eslesme) > count($enIyiEslesme ?? [])) {
                $enIyiEslesme = $eslesme;
            }

            if (array_diff($zorunlular, array_keys($eslesme)) !== []) {
                continue;
            }

            $okunanKolonlar = [...$okunanKolonlar, ...array_keys($eslesme)];

            if ($baslikSatiri < $sonSatir) {
                // Formüller hesaplanmış, biçimlendirilmemiş ham değerler (tarih = Excel seri numarası)
                $veri = $sayfa->rangeToArray('A'.($baslikSatiri + 1).":{$sonKolon}{$sonSatir}", null, true, false, false);
                $satirlar = [...$satirlar, ...self::eslestir($veri, $eslesme, $baslikSatiri + 1, $sayfa->getTitle())];
            }
        }

        if ($okunanKolonlar !== []) {
            return ['satirlar' => $satirlar, 'kolonlar' => array_values(array_unique($okunanKolonlar)), 'hata' => null];
        }

        return $enIyiEslesme === null
            ? ['satirlar' => [], 'kolonlar' => [], 'hata' => 'baslik_bulunamadi']
            : ['satirlar' => [], 'kolonlar' => array_keys($enIyiEslesme), 'hata' => null];
    }

    /**
     * Excel'den kopyalanan metin: satırlar yeni satırla, hücreler sekmeyle
     * ayrılır. Başlık satırı da kopyalandıysa adlarından tanınır; yoksa
     * kolonların standart sırası esas alınır.
     *
     * @param  array<string, list<string>>  $kolonlar
     * @return array{satirlar: list<array{satir: int, sayfa: string|null, degerler: array<string, mixed>}>, kolonlar: list<string>, hata: string|null}
     */
    public static function metin(string $metin, array $kolonlar): array
    {
        $satirlar = array_map(
            fn (string $satir): array => explode("\t", $satir),
            preg_split('/\r\n|\n|\r/', rtrim($metin, "\r\n")) ?: [],
        );

        $baslik = self::baslikBul(array_slice($satirlar, 0, self::BASLIK_ARAMA_SATIRI), $kolonlar);

        if ($baslik !== null) {
            [$baslikSatiri, $eslesme] = $baslik;

            return [
                'satirlar' => self::eslestir(array_slice($satirlar, $baslikSatiri), $eslesme, $baslikSatiri + 1),
                'kolonlar' => array_keys($eslesme),
                'hata' => null,
            ];
        }

        $eslesme = array_flip(array_keys($kolonlar));

        return ['satirlar' => self::eslestir($satirlar, $eslesme, 1), 'kolonlar' => array_keys($eslesme), 'hata' => null];
    }

    /**
     * Sayı: Excel'den sayı olarak ya da Türkçe biçimli metin ("2.562,15",
     * "2562,15 ₺") olarak gelebilir. Boş ya da "-" ise null, sayı değilse false.
     */
    public static function sayi(mixed $deger): float|false|null
    {
        if (is_int($deger) || is_float($deger)) {
            return (float) $deger;
        }

        $metin = trim((string) $deger);

        // Excel'de "-" yok/sıfır anlamında kullanılıyor (İşgücü'nde ~600 satır)
        if ($metin === '' || in_array($metin, ['-', '–', '—'], true)) {
            return null;
        }

        // Para simgesi, boşluk (bölünmez dahil) vb. atılır
        $metin = (string) preg_replace('/[^\d,.\-]/u', '', $metin);

        if (str_contains($metin, ',')) {
            // Türkçe biçim: nokta binlik, virgül ondalık
            $metin = str_replace(',', '.', str_replace('.', '', $metin));
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $metin) === 1) {
            // Yalnız binlik ayracı ("1.234.567")
            $metin = str_replace('.', '', $metin);
        }

        return is_numeric($metin) ? (float) $metin : false;
    }

    /**
     * Tarih: Excel seri numarası ya da "25.03.2026" / "2026-03-25". Boşsa null,
     * geçersizse false; geçerliyse Y-m-d.
     */
    public static function tarih(mixed $deger): string|false|null
    {
        if (is_int($deger) || is_float($deger) || (is_string($deger) && preg_match('/^\d{5}(\.\d+)?$/', trim($deger)) === 1)) {
            $seri = (float) $deger;

            // 1 = 01.01.1900, 2958465 = 31.12.9999
            if ($seri < 1 || $seri > 2958465) {
                return false;
            }

            return ExcelTarihi::excelToDateTimeObject($seri)->format('Y-m-d');
        }

        $metin = trim((string) $deger);

        if ($metin === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})[.\/\-](\d{1,2})[.\/\-](\d{4})$/', $metin, $p) === 1) {
            [$gun, $ay, $yil] = [(int) $p[1], (int) $p[2], (int) $p[3]];
        } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $metin, $p) === 1) {
            [$yil, $ay, $gun] = [(int) $p[1], (int) $p[2], (int) $p[3]];
        } else {
            return false;
        }

        return checkdate($ay, $gun, $yil) ? sprintf('%04d-%02d-%02d', $yil, $ay, $gun) : false;
    }

    /**
     * Bellek sınırı yalnız düşükse yükseltilir (sınırsız ya da daha yüksekse
     * dokunulmaz). Excel'e aktarım da kullanır.
     */
    public static function bellekSiniriniYukselt(): void
    {
        $mevcut = trim((string) ini_get('memory_limit'));

        if ($mevcut === '-1') {
            return;
        }

        $bayt = (int) $mevcut * match (strtoupper(substr($mevcut, -1))) {
            'G' => 1024 ** 3,
            'M' => 1024 ** 2,
            'K' => 1024,
            default => 1,
        };

        if ($bayt < self::EXCEL_BELLEK_MB * 1024 ** 2) {
            ini_set('memory_limit', self::EXCEL_BELLEK_MB.'M');
        }
    }

    /**
     * @param  list<array<int, mixed>>  $satirlar
     * @param  array<string, list<string>>  $kolonlar
     * @return array{0: int, 1: array<string, int>}|null [başlık satırı (1'den), alan => kolon sırası]
     */
    private static function baslikBul(array $satirlar, array $kolonlar): ?array
    {
        $bilinen = [];
        foreach ($kolonlar as $alan => $basliklar) {
            foreach ($basliklar as $baslik) {
                $bilinen[self::anahtar($baslik)] = $alan;
            }
        }

        // En çok kolonu tanınan satır başlıktır: Excel'in üstündeki ara toplam
        // satırı da birkaç başlık adı (TOPLAM TUTAR…) taşır
        $enIyi = null;

        foreach ($satirlar as $sira => $satir) {
            $eslesme = [];
            foreach ($satir as $kolon => $hucre) {
                $alan = $bilinen[self::anahtar((string) $hucre)] ?? null;

                if ($alan !== null && ! isset($eslesme[$alan])) {
                    $eslesme[$alan] = $kolon;
                }
            }

            if (count($eslesme) >= min(self::ENAZ_BASLIK_ESLESMESI, count($kolonlar)) && count($eslesme) > count($enIyi[1] ?? [])) {
                $enIyi = [$sira + 1, $eslesme];
            }
        }

        return $enIyi;
    }

    /**
     * Tamamen boş satırlar atlanır.
     *
     * @param  list<array<int, mixed>>  $satirlar
     * @param  array<string, int>  $eslesme
     * @return list<array{satir: int, sayfa: string|null, degerler: array<string, mixed>}>
     */
    private static function eslestir(array $satirlar, array $eslesme, int $ilkSatirNo, ?string $sayfa = null): array
    {
        $sonuc = [];

        foreach ($satirlar as $sira => $satir) {
            $degerler = [];
            foreach ($eslesme as $alan => $kolon) {
                $deger = $satir[$kolon] ?? null;
                $degerler[$alan] = is_string($deger) ? trim($deger) : $deger;
            }

            if (array_filter($degerler, fn (mixed $d): bool => $d !== null && $d !== '') === []) {
                continue;
            }

            $sonuc[] = ['satir' => $ilkSatirNo + $sira, 'sayfa' => $sayfa, 'degerler' => $degerler];
        }

        return $sonuc;
    }
}
