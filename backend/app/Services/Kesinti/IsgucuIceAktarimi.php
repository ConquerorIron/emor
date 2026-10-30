<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\KesintiYuklemesi;
use App\Models\ParaBirimi;
use App\Models\Proje;
use App\Support\Metin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * İşgücü Kesintileri Excel / yapıştırma aktarımı (kullanıcı kararları 2026-10-30):
 *
 * - Tür, taşeron, proje ve para birimi KOD ile eşleşir; taşeron kodu tek firmaya
 *   düşmelidir (kodlar Firmalar ekranında Excel'le aynı yapılır).
 * - Tutarlar Excel'deki gibi alınır (yansıtılan/kalan yeniden hesaplanmaz).
 * - Hatalı satır varken hiçbir satır kaydedilmez; her yükleme bir partidir
 *   (KesintiYuklemesi) ve tek seferde geri alınabilir.
 */
final class IsgucuIceAktarimi
{
    /**
     * Alan => kabul edilen başlıklar. İlki kullanıcının standart başlığıdır;
     * diğerleri bugünkü Excel'deki adlardır (Excel düzeltilirken de çalışsın).
     */
    public const KOLONLAR = [
        'kesinti_turu' => ['KESİNTİ TÜRÜ'],
        'taseron' => ['TAŞERON ADI', 'TAŞERON', 'KESİNTİ YAPILACAK FİRMA'],
        'proje' => ['PROJE', 'BÜTÇE'],
        'mahal' => ['MAHAL'],
        'tarih' => ['TARİH'],
        'kaynak_firma' => ['KAYNAK FİRMA', 'FİRMA'],
        'kaynak_fis_no' => ['KAYNAK FİŞ NO', 'NO'],
        'adi_soyadi' => ['ADI SOYADI'],
        'vasif' => ['VASFI'],
        'mesai_turu' => ['MESAİ TÜRÜ', 'NORMAL MESAİ M./ FAZLA M.'],
        'yansitma_turu' => ['YANSITMA TÜRÜ', 'YANSITMA', 'YANSITMA 2'],
        'tanim' => ['TANIM'],
        'gorev' => ['GÖREV'],
        'departman' => ['DEPARTMAN', 'BİRİM'],
        'aktivite' => ['AKTİVİTE'],
        'miktar_saat' => ['MİKTAR (SAAT)'],
        'miktar_yevmiye' => ['MİKTAR (YEVMİYE)'],
        'birim_fiyat' => ['BİRİM FİYAT'],
        'para_birimi' => ['PARA BİRİMİ'],
        'toplam_tutar' => ['TOPLAM TUTAR'],
        'iptal_edilen_tutar' => ['İPTAL EDİLEN TUTAR'],
        'yansitilan_tutar' => ['YANSITILAN TUTAR'],
        'kesilen_tutar' => ['KESİLEN TUTAR'],
        'kalan_tutar' => ['KALAN TUTAR'],
    ];

    /** Dosyada bulunması zorunlu kolonlar (metin kolonlarının çoğu isteğe bağlı) */
    private const ZORUNLU_KOLONLAR = [
        'kesinti_turu', 'taseron', 'proje', 'tarih', 'adi_soyadi', 'vasif', 'mesai_turu', 'yansitma_turu',
        'miktar_saat', 'miktar_yevmiye', 'birim_fiyat', 'para_birimi',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    /** Metin alanları ve sınırları (isgucu_kesintileri) */
    private const METINLER = [
        'mahal' => 200, 'kaynak_firma' => 200, 'kaynak_fis_no' => 50, 'adi_soyadi' => 200,
        'tanim' => 200, 'gorev' => 200, 'departman' => 200, 'aktivite' => 200,
    ];

    /** Sayı alanları => [ondalık basamak, mutlak üst sınır (decimal kolon)] — boş hücre 0 sayılır */
    private const SAYILAR = [
        'miktar_saat' => [2, 1e8], 'miktar_yevmiye' => [2, 1e8], 'birim_fiyat' => [4, 1e14],
        'toplam_tutar' => [2, 1e16], 'iptal_edilen_tutar' => [2, 1e16], 'yansitilan_tutar' => [2, 1e16],
        'kesilen_tutar' => [2, 1e16], 'kalan_tutar' => [2, 1e16],
    ];

    private const SECENEKLER = [
        'vasif' => Vasif::class,
        'mesai_turu' => MesaiTuru::class,
        'yansitma_turu' => YansitmaTuru::class,
    ];

    /** Önizleme hata özetinde bir neden için gösterilen örnek satır sayısı */
    private const ORNEK_SATIR = 10;

    /**
     * Okunan tabloyu doğrular ve tanımlarla eşler.
     *
     * @param  array{satirlar: list<array{satir: int, degerler: array<string, mixed>}>, kolonlar: list<string>, hata: string|null}  $tablo
     * @return array{hata: string|null, eksik_kolonlar: list<string>, satirlar: list<array<string, mixed>>, kayitlar: list<array<string, mixed>>, hatalar: list<array{alan: string, deger: string, kod: string, adet: int, satirlar: list<int>}>, toplamlar: list<array<string, int|string>>}
     */
    public function incele(array $tablo): array
    {
        $bos = ['hata' => $tablo['hata'], 'eksik_kolonlar' => [], 'satirlar' => [], 'kayitlar' => [], 'hatalar' => [], 'toplamlar' => []];

        if ($tablo['hata'] !== null) {
            return $bos;
        }

        $eksik = array_values(array_diff(self::ZORUNLU_KOLONLAR, $tablo['kolonlar']));

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

        foreach ($tablo['satirlar'] as ['satir' => $no, 'degerler' => $degerler]) {
            [$gosterim, $kayit, $satirHatalari] = $this->satir($degerler, $sozlukler, $firmalar);

            foreach ($satirHatalari as [$alan, $deger, $kod]) {
                $anahtar = "{$alan}|{$kod}|{$deger}";
                $hatalar[$anahtar] ??= ['alan' => $alan, 'deger' => $deger, 'kod' => $kod, 'adet' => 0, 'satirlar' => []];
                $hatalar[$anahtar]['adet']++;

                if (count($hatalar[$anahtar]['satirlar']) < self::ORNEK_SATIR) {
                    $hatalar[$anahtar]['satirlar'][] = $no;
                }
            }

            $satirlar[] = ['satir' => $no, ...$gosterim, 'hatali' => $satirHatalari !== []];
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
                'icmal' => KesintiYuklemesi::ICMAL_ISGUCU,
                'kaynak' => $kaynak,
                'dosya_adi' => $dosyaAdi,
                'satir_sayisi' => count($kayitlar),
                'yukleyen_id' => $kullaniciId,
            ]);

            $simdi = now();

            // PostgreSQL parametre sınırının (65.535) altında kalan parçalar
            foreach (array_chunk($kayitlar, 500) as $parca) {
                IsgucuKesintisi::query()->insert(array_map(fn (array $kayit): array => [
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

        foreach (self::SECENEKLER as $alan => $enum) {
            $ham = self::metinDegeri($degerler[$alan] ?? null);
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

        foreach (self::METINLER as $alan => $sinir) {
            $deger = self::metinDegeri($degerler[$alan] ?? null);
            $gosterim[$alan] = $deger;
            $kayit[$alan] = $deger === '' ? null : $deger;

            if ($deger === '' && $alan === 'adi_soyadi') {
                $hatalar[] = [$alan, $deger, 'zorunlu'];
            } elseif (mb_strlen($deger) > $sinir) {
                $hatalar[] = [$alan, mb_substr($deger, 0, 40).'…', 'cok_uzun'];
            }
        }

        foreach (self::SAYILAR as $alan => [$basamak, $sinir]) {
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
        $alanlar = ['toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar'];
        $toplamlar = [];

        foreach ($kayitlar as $kayit) {
            $kod = (string) $kodlar[$kayit['para_birimi_id']];
            $toplamlar[$kod] ??= ['para_birimi' => $kod, 'satir_sayisi' => 0, ...array_fill_keys($alanlar, '0.00')];
            $toplamlar[$kod]['satir_sayisi']++;

            foreach ($alanlar as $alan) {
                $toplamlar[$kod][$alan] = bcadd((string) $toplamlar[$kod][$alan], (string) $kayit[$alan], 2);
            }
        }

        return array_values($toplamlar);
    }
}
