<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Services\ErpOkumaHatasi;
use Carbon\CarbonImmutable;
use Collator;

/**
 * Raporlar menüsündeki ERP raporlarının ortak akışı: proc ortak imzayla
 * çalışır (ErpRaporKaynagi), satırlar rapora özgü biçime çevrilip sıralanır.
 * Ekran süzme, sayfalama ve toplamları tarayıcıda yapar; Excel ekranda
 * süzülen satırları anahtarlarıyla alır ve toplam satırlarını buradan yazar
 * (ErpRaporExcelAktarici). Yeni rapor = sabitleri dolduran ince bir alt sınıf.
 */
abstract class ErpRaporu
{
    /** ERP proc'u — sabit, sorguya kullanıcı girdisi girmez */
    protected const PROC = '';

    /** lang/tr/rapor.php altındaki bölüm: başlık ve kolon başlıkları */
    public const DIL = '';

    /** Satır kimliği: Excel'e ekranda süzülen satırlar bununla gelir */
    public const ANAHTAR = '';

    public const DOSYA_ADI = '';

    /**
     * Ekrandaki ve Excel'deki kolonlar, Sıra No'dan sonra sırasıyla.
     *
     * @var array<string, 'metin'|'tarih'|'tutar'>
     */
    public const KOLONLAR = [];

    /**
     * Özet (pivot) sekmeleri, ekrandaki sırayla: sekme => grup kolonları
     * ("yil" / "ay" TARIH_ALANI'ndan türer). Frontend tanımıyla aynı olmalı;
     * Excel'de her biri ayrı sayfa.
     *
     * @var array<string, list<string>>
     */
    public const PIVOTLAR = [];

    /** Pivotlardaki Yıl / Ay'ın türediği tarih kolonu (Y-m-d) */
    public const TARIH_ALANI = '';

    public function __construct(
        private readonly ErpRaporKaynagi $kaynak,
    ) {}

    /**
     * Satırlar sekmenin grup kolonlarına göre toplanır: gruplar + satır
     * sayısı + tutar kolonları; gruplar soldan sağa sıralı (Türkçe, sayılar
     * sayı gibi).
     *
     * @param  list<array<string, int|string|float|null>>  $satirlar  ekranda süzülen satırlar
     * @return list<array<string, int|string|float>>
     */
    public function pivot(array $satirlar, string $pivot): array
    {
        $gruplar = static::PIVOTLAR[$pivot];
        $tutarlar = array_keys(array_filter(static::KOLONLAR, fn (string $tur): bool => $tur === 'tutar'));
        $sonuc = [];

        foreach ($satirlar as $satir) {
            $tarih = (string) ($satir[static::TARIH_ALANI] ?? '');
            $degerler = [];
            foreach ($gruplar as $alan) {
                $degerler[$alan] = match ($alan) {
                    'yil' => (int) substr($tarih, 0, 4),
                    'ay' => (int) substr($tarih, 5, 2),
                    default => (string) ($satir[$alan] ?? ''),
                };
            }

            $anahtar = implode("\0", $degerler);
            $sonuc[$anahtar] ??= [...$degerler, 'satir_sayisi' => 0, ...array_fill_keys($tutarlar, 0.0)];
            $sonuc[$anahtar]['satir_sayisi']++;
            foreach ($tutarlar as $alan) {
                $sonuc[$anahtar][$alan] += (float) $satir[$alan];
            }
        }

        $siralayici = new Collator('tr_TR');
        $siralayici->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        $sonuc = array_values($sonuc);
        usort($sonuc, function (array $a, array $b) use ($gruplar, $siralayici): int {
            foreach ($gruplar as $alan) {
                $fark = is_int($a[$alan]) ? $a[$alan] <=> $b[$alan] : (int) $siralayici->compare($a[$alan], $b[$alan]);
                if ($fark !== 0) {
                    return $fark;
                }
            }

            return 0;
        });

        // Toplamadaki float artıkları temizlenir (kuruş)
        return array_map(function (array $grup) use ($tutarlar): array {
            foreach ($tutarlar as $alan) {
                $grup[$alan] = round($grup[$alan], 2);
            }

            return $grup;
        }, $sonuc);
    }

    /**
     * @param  array{ilk_tarih: string, son_tarih: string, proje_kodu: string|null, firma_adi: string|null}  $parametreler
     * @return list<array<string, int|string|float|null>>
     *
     * @throws ErpOkumaHatasi
     */
    public function satirlar(int $erpKullaniciId, array $parametreler): array
    {
        $satirlar = array_map($this->satir(...), $this->kaynak->satirlar(static::PROC, $erpKullaniciId, $parametreler));
        usort($satirlar, $this->karsilastir(...));

        return $satirlar;
    }

    /**
     * Ekranda süzülmüş satırlar, verilen (ekrandaki) sırayla; proc arada
     * değiştiyse artık olmayan anahtar atlanır.
     *
     * @param  list<array<string, int|string|float|null>>  $satirlar  satirlar()'ın sonucu
     * @param  list<string>  $anahtarlar
     * @return list<array<string, int|string|float|null>>
     */
    public function sec(array $satirlar, array $anahtarlar): array
    {
        $anahtara = [];
        foreach ($satirlar as $satir) {
            $anahtara[(string) $satir[static::ANAHTAR]] = $satir;
        }

        return array_values(array_filter(array_map(fn (string $anahtar): ?array => $anahtara[$anahtar] ?? null, $anahtarlar)));
    }

    /**
     * Excel'in tablo altındaki toplam satırları (ekran aynı hesabı süzülen
     * satırlarla kendisi yapar).
     *
     * @param  list<array<string, int|string|float|null>>  $satirlar
     * @return list<array{etiket: string, degerler: array<string, string|float>}>
     */
    abstract public function toplamSatirlari(array $satirlar): array;

    /**
     * Proc satırı → rapor satırı (KOLONLAR ve ANAHTAR alanları).
     *
     * @param  array<string, mixed>  $ham
     * @return array<string, int|string|float|null>
     */
    abstract protected function satir(array $ham): array;

    /**
     * Varsayılan sıra.
     *
     * @param  array<string, int|string|float|null>  $a
     * @param  array<string, int|string|float|null>  $b
     */
    abstract protected function karsilastir(array $a, array $b): int;

    /** nchar kolonlar sonu boşlukla dolu gelir */
    protected function metin(mixed $deger): string
    {
        return trim((string) ($deger ?? ''));
    }

    /** Proc tutarları float / decimal metni döner: ERP'nin gösterdiği gibi kuruşa yuvarlanır */
    protected function tutar(mixed $deger): float
    {
        return round((float) ($deger ?? 0), 2);
    }

    protected function zaman(mixed $deger): ?CarbonImmutable
    {
        return $deger === null || trim((string) $deger) === '' ? null : CarbonImmutable::parse((string) $deger);
    }

    /**
     * Verilen tutar alanlarının toplamı.
     *
     * @param  list<array<string, int|string|float|null>>  $satirlar
     * @param  list<string>  $alanlar
     * @return array<string, float>
     */
    protected function topla(array $satirlar, array $alanlar): array
    {
        $toplam = array_fill_keys($alanlar, 0.0);

        foreach ($satirlar as $satir) {
            foreach ($alanlar as $alan) {
                $toplam[$alan] += (float) $satir[$alan];
            }
        }

        // Toplamadaki float artıkları temizlenir (kuruş)
        return array_map(fn (float $deger): float => round($deger, 2), $toplam);
    }

    /**
     * Para kodu başına verilen tutar alanlarının toplamı, para koduna göre sıralı.
     *
     * @param  list<array<string, int|string|float|null>>  $satirlar
     * @param  list<string>  $alanlar
     * @return list<array<string, string|float>>
     */
    protected function paraKodunaGoreTopla(array $satirlar, array $alanlar): array
    {
        $gruplar = [];
        foreach ($satirlar as $satir) {
            $gruplar[(string) $satir['para_kodu']][] = $satir;
        }
        ksort($gruplar);

        return array_map(
            fn (string $para, array $grup): array => ['para_kodu' => $para, ...$this->topla($grup, $alanlar)],
            array_keys($gruplar),
            array_values($gruplar),
        );
    }

    /**
     * @param  list<array<string, string|float>>  $toplamlar
     * @return list<array{etiket: string, degerler: array<string, string|float>}>
     */
    protected function etiketle(string $etiket, array $toplamlar): array
    {
        return array_map(fn (array $degerler): array => ['etiket' => $etiket, 'degerler' => $degerler], $toplamlar);
    }
}
