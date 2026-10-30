<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Support\Metin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Kesinti icmallerinin Excel / yapıştırma aktarımı (kullanıcı kararları 2026-10-30):
 *
 * - Tür, taşeron, proje ve para birimi KOD ile eşleşir; taşeron kodu tek firmaya
 *   düşmelidir (kodlar Firmalar ekranında Excel'le aynı yapılır).
 * - Tutarlar Excel'deki gibi alınır (yansıtılan/kalan yeniden hesaplanmaz).
 * - Hatalı satır varken hiçbir satır kaydedilmez; her yükleme bir partidir
 *   (KesintiYuklemesi) ve tek seferde geri alınabilir.
 *
 * Her icmal kendi kolonlarını sabitlerle tanımlar (ICMAL, MODEL, KOLONLAR,
 * ZORUNLU_KOLONLAR, METINLER, SAYILAR, SECENEKLER). Tanım seçimleri
 * (kesinti_turu, taseron, proje, para_birimi), tarih ve tutarlar ortaktır.
 */
abstract class IcmalIceAktarimi
{
    /** kesinti_yuklemeleri.icmal değeri */
    public const ICMAL = '';

    /** @var class-string<Model> */
    protected const MODEL = Model::class;

    /**
     * Alan => kabul edilen başlıklar. İlki kullanıcının standart başlığıdır;
     * diğerleri bugünkü Excel'deki adlardır (Excel düzeltilirken de çalışsın).
     *
     * @var array<string, list<string>>
     */
    public const KOLONLAR = [];

    /**
     * Dosyada bulunması zorunlu kolonlar.
     *
     * @var list<string>
     */
    protected const ZORUNLU_KOLONLAR = [];

    /**
     * Metin alanları => [sınır, zorunlu mu].
     *
     * @var array<string, array{0: int, 1: bool}>
     */
    protected const METINLER = [];

    /**
     * Sayı alanları => [ondalık basamak, mutlak üst sınır (decimal kolon)]; boş hücre 0 sayılır.
     *
     * @var array<string, array{0: int, 1: float}>
     */
    protected const SAYILAR = [];

    /**
     * Sabit seçenek alanları => enum sınıfı (ExcelEtiketli).
     *
     * @var array<string, class-string>
     */
    protected const SECENEKLER = [];

    /** Önizleme hata özetinde bir neden için gösterilen örnek satır sayısı */
    private const ORNEK_SATIR = 10;

    /** Toplamları alınan tutarlar (her icmalde aynı) */
    private const TUTARLAR = ['toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar'];

    /**
     * Excel dosyası: gerekli kolonların tamamını taşıyan her sayfa okunur;
     * başka düzendeki sayfalar atlanır (ör. Kamp dosyasının oda sayfası Kamp
     * Malzeme aktarımında).
     *
     * @return array{satirlar: list<array{satir: int, sayfa: string|null, degerler: array<string, mixed>}>, kolonlar: list<string>, hata: string|null}
     */
    public function excelOku(string $yol): array
    {
        return IceAktarimTablosu::excel($yol, static::KOLONLAR, static::ZORUNLU_KOLONLAR);
    }

    /**
     * @return array{satirlar: list<array{satir: int, sayfa: string|null, degerler: array<string, mixed>}>, kolonlar: list<string>, hata: string|null}
     */
    public function metinOku(string $metin): array
    {
        return IceAktarimTablosu::metin($metin, static::KOLONLAR);
    }

    /**
     * Okunan tabloyu doğrular ve tanımlarla eşler.
     *
     * @param  array{satirlar: list<array{satir: int, sayfa: string|null, degerler: array<string, mixed>}>, kolonlar: list<string>, hata: string|null}  $tablo
     * @return array{hata: string|null, eksik_kolonlar: list<string>, satirlar: list<array<string, mixed>>, kayitlar: list<array<string, mixed>>, hatalar: list<array{alan: string, deger: string, kod: string, adet: int, satirlar: list<string>}>, toplamlar: list<array<string, int|string>>}
     */
    public function incele(array $tablo): array
    {
        $bos = ['hata' => $tablo['hata'], 'eksik_kolonlar' => [], 'satirlar' => [], 'kayitlar' => [], 'hatalar' => [], 'toplamlar' => []];

        if ($tablo['hata'] !== null) {
            return $bos;
        }

        $eksik = array_values(array_diff(static::ZORUNLU_KOLONLAR, $tablo['kolonlar']));

        if ($eksik !== []) {
            return [...$bos, 'eksik_kolonlar' => $eksik];
        }

        if ($tablo['satirlar'] === []) {
            return [...$bos, 'hata' => 'satir_yok'];
        }

        $sozlukler = [
            'kesinti_turu' => KesintiTuru::query()->get(['id', 'kod'])->keyBy(fn (KesintiTuru $t): string => self::kodAnahtari($t->kod)),
            'proje' => Proje::query()->get(['id', 'kod'])->keyBy(fn (Proje $p): string => self::kodAnahtari($p->kod)),
            'para_birimi' => ParaBirimi::query()->get(['id', 'kod'])->keyBy(fn (ParaBirimi $p): string => self::kodAnahtari($p->kod)),
        ];
        // Kod tekrar edebilir (ERP kartları): taşeron kodu tek firmaya düşmeli
        $firmalar = Firma::query()->get(['id', 'kod'])->groupBy(fn (Firma $f): string => self::kodAnahtari($f->kod));

        $hatalar = [];
        $satirlar = [];
        $kayitlar = [];
        // Birden fazla sayfa okunduysa satır, sayfasıyla birlikte gösterilir
        $cokSayfa = count(array_unique(array_column($tablo['satirlar'], 'sayfa'))) > 1;

        foreach ($tablo['satirlar'] as ['satir' => $no, 'sayfa' => $sayfa, 'degerler' => $degerler]) {
            [$gosterim, $kayit, $satirHatalari] = $this->satir($degerler, $sozlukler, $firmalar);
            $konum = $cokSayfa ? "{$sayfa} › {$no}" : (string) $no;

            foreach ($satirHatalari as [$alan, $deger, $kod]) {
                $anahtar = "{$alan}|{$kod}|{$deger}";
                $hatalar[$anahtar] ??= ['alan' => $alan, 'deger' => $deger, 'kod' => $kod, 'adet' => 0, 'satirlar' => []];
                $hatalar[$anahtar]['adet']++;

                if (count($hatalar[$anahtar]['satirlar']) < self::ORNEK_SATIR) {
                    $hatalar[$anahtar]['satirlar'][] = $konum;
                }
            }

            $satirlar[] = ['satir' => $no, 'sayfa' => $sayfa, ...$gosterim, 'hatali' => $satirHatalari !== []];
            $kayitlar[] = $kayit;
        }

        $hataListesi = array_values($hatalar);
        usort($hataListesi, fn (array $a, array $b): int => $b['adet'] <=> $a['adet']);

        return [
            ...$bos,
            'satirlar' => $satirlar,
            // Tek hata bile varsa hiçbir satır kaydedilmez
            'kayitlar' => $hataListesi === [] ? $kayitlar : [],
            'hatalar' => $hataListesi,
            'toplamlar' => $hataListesi === [] ? $this->toplamlar($kayitlar, $sozlukler['para_birimi']) : [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $kayitlar
     */
    public function kaydet(array $kayitlar, string $kaynak, ?string $dosyaAdi, ?int $kullaniciId): KesintiYuklemesi
    {
        return DB::transaction(function () use ($kayitlar, $kaynak, $dosyaAdi, $kullaniciId): KesintiYuklemesi {
            $yukleme = KesintiYuklemesi::query()->create([
                'icmal' => static::ICMAL,
                'kaynak' => $kaynak,
                'dosya_adi' => $dosyaAdi,
                'satir_sayisi' => count($kayitlar),
                'yukleyen_id' => $kullaniciId,
            ]);

            $simdi = now();

            // PostgreSQL parametre sınırının (65.535) altında kalan parçalar
            foreach (array_chunk($kayitlar, 500) as $parca) {
                (static::MODEL)::query()->insert(array_map(fn (array $kayit): array => [
                    ...$kayit,
                    'yukleme_id' => $yukleme->id,
                    'kaydeden_id' => $kullaniciId,
                    'created_at' => $simdi,
                    'updated_at' => $simdi,
                ], $parca));
            }

            return $yukleme;
        });
    }

    /**
     * Yapıştırma tablosunun kolonları, standart sırayla.
     *
     * @return list<array{alan: string, basliklar: list<string>}>
     */
    public function kolonTanimi(): array
    {
        return array_map(
            fn (string $alan, array $basliklar): array => ['alan' => $alan, 'basliklar' => $basliklar],
            array_keys(static::KOLONLAR),
            array_values(static::KOLONLAR),
        );
    }

    /**
     * Kod karşılaştırma anahtarı: Türkçe büyük harf, fazla boşluk tek boşluk.
     */
    public static function kodAnahtari(string $kod): string
    {
        return Metin::buyukHarf((string) preg_replace('/\s+/u', ' ', trim($kod)));
    }

    /**
     * @param  array<string, mixed>  $degerler
     * @param  array<string, Collection<string, KesintiTuru|Proje|ParaBirimi>>  $sozlukler
     * @param  Collection<string, Collection<int, Firma>>  $firmalar
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: list<array{0: string, 1: string, 2: string}>}
     */
    private function satir(array $degerler, array $sozlukler, Collection $firmalar): array
    {
        $gosterim = [];
        $kayit = [];
        $hatalar = [];

        foreach ($sozlukler as $alan => $sozluk) {
            $ham = self::metinDegeri($degerler[$alan] ?? null);
            $gosterim[$alan] = $ham;
            $tanim = $ham === '' ? null : $sozluk->get(self::kodAnahtari($ham));

            if ($tanim === null) {
                $hatalar[] = [$alan, $ham, $ham === '' ? 'zorunlu' : 'bulunamadi'];
            } else {
                $kayit[$alan.'_id'] = $tanim->id;
            }
        }

        $ham = self::metinDegeri($degerler['taseron'] ?? null);
        $gosterim['taseron'] = $ham;
        $adaylar = $ham === '' ? null : $firmalar->get(self::kodAnahtari($ham));

        if ($ham === '') {
            $hatalar[] = ['taseron', $ham, 'zorunlu'];
        } elseif ($adaylar === null) {
            $hatalar[] = ['taseron', $ham, 'bulunamadi'];
        } elseif ($adaylar->count() > 1) {
            $hatalar[] = ['taseron', $ham, 'birden_fazla'];
        } else {
            $kayit['firma_id'] = $adaylar->first()?->id;
        }

        foreach (static::SECENEKLER as $alan => $enum) {
            $ham = self::metinDegeri($degerler[$alan] ?? null);
            /** @var (\UnitEnum&\BackedEnum)|null $secenek */
            $secenek = $ham === '' ? null : $enum::excelden($ham);
            $gosterim[$alan] = $secenek?->etiket() ?? $ham;

            if ($secenek === null) {
                $hatalar[] = [$alan, $ham, $ham === '' ? 'zorunlu' : 'gecersiz_secenek'];
            } else {
                $kayit[$alan] = $secenek->value;
            }
        }

        $tarih = IceAktarimTablosu::tarih($degerler['tarih'] ?? null);
        $gosterim['tarih'] = is_string($tarih) ? $tarih : self::metinDegeri($degerler['tarih'] ?? null);

        if (is_string($tarih)) {
            $kayit['tarih'] = $tarih;
        } else {
            $hatalar[] = ['tarih', $gosterim['tarih'], $tarih === null ? 'zorunlu' : 'gecersiz_tarih'];
        }

        foreach (static::METINLER as $alan => [$sinir, $zorunlu]) {
            $deger = self::metinDegeri($degerler[$alan] ?? null);
            $gosterim[$alan] = $deger;
            $kayit[$alan] = $deger === '' ? null : $deger;

            if ($deger === '' && $zorunlu) {
                $hatalar[] = [$alan, $deger, 'zorunlu'];
            } elseif (mb_strlen($deger) > $sinir) {
                $hatalar[] = [$alan, mb_substr($deger, 0, 40).'…', 'cok_uzun'];
            }
        }

        foreach (static::SAYILAR as $alan => [$basamak, $sinir]) {
            $sayi = IceAktarimTablosu::sayi($degerler[$alan] ?? null);

            if ($sayi === false || abs($sayi ?? 0.0) >= $sinir) {
                $hata = self::metinDegeri($degerler[$alan] ?? null);
                $gosterim[$alan] = $hata;
                $hatalar[] = [$alan, $hata, 'gecersiz_sayi'];

                continue;
            }

            $kayit[$alan] = number_format(round($sayi ?? 0.0, $basamak), $basamak, '.', '');
            $gosterim[$alan] = $kayit[$alan];
        }

        return [$gosterim, $kayit, $hatalar];
    }

    /**
     * Hücre değeri metin olarak: tam sayıya denk gelen sayılar ondalıksız
     * (Excel'de sayı olarak tutulan fiş no / VKN gibi kodlar için).
     */
    private static function metinDegeri(mixed $deger): string
    {
        if (is_float($deger) && floor($deger) === $deger && abs($deger) < 1e15) {
            return sprintf('%.0f', $deger);
        }

        return trim((string) $deger);
    }

    /**
     * Para birimi başına toplamlar (önizleme özeti).
     *
     * @param  list<array<string, mixed>>  $kayitlar
     * @param  Collection<string, KesintiTuru|Proje|ParaBirimi>  $paraBirimleri
     * @return list<array<string, int|string>>
     */
    private function toplamlar(array $kayitlar, Collection $paraBirimleri): array
    {
        $kodlar = $paraBirimleri->mapWithKeys(fn (ParaBirimi $p): array => [$p->id => $p->kod]);
        $toplamlar = [];

        foreach ($kayitlar as $kayit) {
            $kod = (string) $kodlar[$kayit['para_birimi_id']];
            $toplamlar[$kod] ??= ['para_birimi' => $kod, 'satir_sayisi' => 0, ...array_fill_keys(self::TUTARLAR, '0.00')];
            $toplamlar[$kod]['satir_sayisi']++;

            foreach (self::TUTARLAR as $alan) {
                $toplamlar[$kod][$alan] = bcadd((string) $toplamlar[$kod][$alan], (string) $kayit[$alan], 2);
            }
        }

        return array_values($toplamlar);
    }
}
