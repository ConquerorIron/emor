<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as TemelSorgu;

/**
 * Kesinti icmali listelerinin filtresi, sıralaması ve para birimine göre
 * toplamları (Excel'in üstündeki ara toplam satırının karşılığı). Her icmal
 * tablosunu (TABLO, MODEL), kendi kolonlarını (KOLONLAR) ve aranan metin
 * kolonlarını (ARANANLAR) verir; tanım ilişkileri ve tutarlar ortaktır.
 */
abstract class IcmalSorgusu
{
    protected const TABLO = '';

    /** @var class-string<Model> */
    protected const MODEL = Model::class;

    /**
     * Kendi kolonuna göre sıralanan alanlar (ham ifadeye yalnız bunlar girer).
     *
     * @var list<string>
     */
    protected const KOLONLAR = [];

    /**
     * Aramanın baktığı metin kolonları.
     *
     * @var list<string>
     */
    protected const ARANANLAR = [];

    /** İlişkili tanımlar koduna göre sıralanır: sıralama anahtarı => [model, yabancı anahtar] */
    private const ILISKILI = [
        'kesinti_turu' => [KesintiTuru::class, 'kesinti_turu_id'],
        'taseron' => [Firma::class, 'firma_id'],
        'proje' => [Proje::class, 'proje_id'],
        'para_birimi' => [ParaBirimi::class, 'para_birimi_id'],
    ];

    private const TUTARLAR = ['toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar'];

    /**
     * Özet (pivot) sekmeleri: pivot anahtarı => gruplanan alanlar, sırasıyla.
     * Alanlar: tanımlar (kesinti_turu, taseron, proje, para_birimi), bu
     * tablonun kolonları ya da tarihten türetilen yil / ay.
     *
     * @var array<string, list<string>>
     */
    protected const PIVOTLAR = [];

    /**
     * Tutarlara ek toplanan sayı kolonları (ör. İşgücü'nde miktar saat): liste
     * toplamlarında (ozet) ve pivotlarda. 5 tutar her zaman toplanır.
     *
     * @var list<string>
     */
    protected const EK_TOPLAMLAR = [];

    /** Pivotta tanım alanının tablosu ve yabancı anahtarı (kod ile gruplanır) */
    private const PIVOT_TANIMLARI = [
        'kesinti_turu' => ['kesinti_turleri', 'kesinti_turu_id'],
        'taseron' => ['firmalar', 'firma_id'],
        'proje' => ['projeler', 'proje_id'],
        'para_birimi' => ['para_birimleri', 'para_birimi_id'],
    ];

    /**
     * Liste isteğinin kabul ettiği sıralama anahtarları.
     *
     * @return list<string>
     */
    public static function siralamalar(): array
    {
        return [...static::KOLONLAR, ...array_keys(self::ILISKILI)];
    }

    /**
     * @param  array{ara?: string|null, firma_id?: int|string|null, proje_id?: int|string|null, yukleme_id?: int|string|null, baslangic?: string|null, bitis?: string|null}  $filtre
     * @return Builder<Model>
     */
    public function filtrele(array $filtre): Builder
    {
        $tablo = static::TABLO;
        $sorgu = (static::MODEL)::query();

        foreach (['firma_id', 'proje_id', 'yukleme_id'] as $alan) {
            if (filled($filtre[$alan] ?? null)) {
                $sorgu->where("{$tablo}.{$alan}", (int) $filtre[$alan]);
            }
        }

        if (filled($filtre['baslangic'] ?? null)) {
            $sorgu->where("{$tablo}.tarih", '>=', $filtre['baslangic']);
        }

        if (filled($filtre['bitis'] ?? null)) {
            $sorgu->where("{$tablo}.tarih", '<=', $filtre['bitis']);
        }

        $ara = trim((string) ($filtre['ara'] ?? ''));

        if ($ara !== '') {
            $desen = '%'.addcslashes($ara, '%_\\').'%';
            $sorgu->where(function (Builder $q) use ($desen, $tablo): void {
                foreach (static::ARANANLAR as $kolon) {
                    $q->orWhereLike("{$tablo}.{$kolon}", $desen);
                }
            });
        }

        return $sorgu;
    }

    /**
     * @param  Builder<Model>  $sorgu
     * @return Builder<Model>
     */
    public function sirala(Builder $sorgu, ?string $anahtar, ?string $yon): Builder
    {
        $tablo = static::TABLO;
        $yon = $yon === 'asc' ? 'asc' : 'desc';

        if (isset(self::ILISKILI[$anahtar])) {
            [$model, $yabanci] = self::ILISKILI[$anahtar];
            $iliskiTablosu = (new $model)->getTable();
            $sorgu->orderBy($model::query()->select('kod')->whereColumn("{$iliskiTablosu}.id", "{$tablo}.{$yabanci}"), $yon);
        } else {
            $kolon = in_array($anahtar, static::KOLONLAR, true) ? $anahtar : 'tarih';
            // Boş değerler yönden bağımsız en sonda (PostgreSQL ile SQLite'ın NULL sırası farklı)
            $sorgu->orderByRaw("CASE WHEN {$tablo}.{$kolon} IS NULL THEN 1 ELSE 0 END")
                ->orderBy("{$tablo}.{$kolon}", $yon);
        }

        return $sorgu->orderBy("{$tablo}.id", $yon);
    }

    /**
     * Filtredeki satırların para birimine göre toplamları (ekranda tablonun
     * altında ve üstünde toplam satırları): ek toplamlar + 5 tutar.
     *
     * @param  Builder<Model>  $sorgu
     * @return list<array<string, int|string>>
     */
    public function ozet(Builder $sorgu): array
    {
        $tablo = static::TABLO;
        $alanlar = [...static::EK_TOPLAMLAR, ...self::TUTARLAR];
        $toplamlar = implode(', ', array_map(fn (string $alan): string => "SUM({$tablo}.{$alan}) AS {$alan}", $alanlar));

        return $sorgu->clone()
            ->join('para_birimleri', 'para_birimleri.id', '=', "{$tablo}.para_birimi_id")
            ->groupBy('para_birimleri.kod')
            ->orderBy('para_birimleri.kod')
            ->selectRaw("para_birimleri.kod AS para_birimi, COUNT(*) AS satir_sayisi, {$toplamlar}")
            ->toBase()
            ->get()
            ->map(fn (object $satir): array => [
                'para_birimi' => (string) $satir->para_birimi,
                'satir_sayisi' => (int) $satir->satir_sayisi,
                ...array_combine($alanlar, array_map(fn (string $alan): string => number_format((float) $satir->{$alan}, 2, '.', ''), $alanlar)),
            ])
            ->values()
            ->all();
    }

    /**
     * Bu icmalin özet (pivot) sekmeleri.
     *
     * @return list<string>
     */
    public static function pivotlar(): array
    {
        return array_keys(static::PIVOTLAR);
    }

    /**
     * Filtredeki satırların pivotu: gruplanan alanlar (tanımlar koduyla,
     * taşeron adıyla birlikte) + satır sayısı + toplamlar, gruplara göre sıralı.
     *
     * @param  Builder<Model>  $sorgu
     * @return list<array<string, int|string|null>>
     */
    public function pivot(Builder $sorgu, string $pivot): array
    {
        $tablo = static::TABLO;
        $gruplar = static::PIVOTLAR[$pivot];
        $toplamlar = [...static::EK_TOPLAMLAR, ...self::TUTARLAR];
        $taban = $sorgu->clone()->toBase();
        $secimler = [];
        $gruplama = [];

        foreach ($gruplar as $alan) {
            if (isset(self::PIVOT_TANIMLARI[$alan])) {
                [$tanimTablosu, $yabanci] = self::PIVOT_TANIMLARI[$alan];
                $taban->join("{$tanimTablosu} AS {$alan}_t", "{$alan}_t.id", '=', "{$tablo}.{$yabanci}");
                $secimler[] = "{$alan}_t.kod AS {$alan}";
                $gruplama[] = "{$alan}_t.kod";

                if ($alan === 'taseron') {
                    // Ekranda kodun üzerine gelince firmanın adı görünür
                    $secimler[] = 'MIN(taseron_t.ad) AS taseron_ad';
                }

                continue;
            }

            $ifade = $this->pivotIfadesi($taban, $alan);
            $secimler[] = "{$ifade} AS {$alan}";
            $gruplama[] = $ifade;
        }

        $secimler[] = 'COUNT(*) AS satir_sayisi';

        foreach ($toplamlar as $alan) {
            $secimler[] = "SUM({$tablo}.{$alan}) AS {$alan}";
        }

        $taban->selectRaw(implode(', ', $secimler))->groupByRaw(implode(', ', $gruplama));

        foreach ($gruplama as $ifade) {
            $taban->orderByRaw($ifade);
        }

        return $taban->get()
            ->map(fn (object $satir): array => [
                ...array_combine($gruplar, array_map(fn (string $alan): int|string|null => in_array($alan, ['yil', 'ay'], true) ? (int) $satir->{$alan} : $satir->{$alan}, $gruplar)),
                ...(in_array('taseron', $gruplar, true) ? ['taseron_ad' => $satir->taseron_ad] : []),
                'satir_sayisi' => (int) $satir->satir_sayisi,
                ...array_combine($toplamlar, array_map(fn (string $alan): string => number_format((float) $satir->{$alan}, 2, '.', ''), $toplamlar)),
            ])
            ->values()
            ->all();
    }

    /**
     * Pivotta tablonun kendi kolonu ya da tarihten türetilen yıl / ay (SQL
     * lehçesi: PostgreSQL canlıda, SQLite testlerde).
     */
    private function pivotIfadesi(TemelSorgu $sorgu, string $alan): string
    {
        $tarih = static::TABLO.'.tarih';
        $postgres = $sorgu->getConnection()->getDriverName() === 'pgsql';

        return match ($alan) {
            'yil' => $postgres ? "CAST(EXTRACT(YEAR FROM {$tarih}) AS INTEGER)" : "CAST(strftime('%Y', {$tarih}) AS INTEGER)",
            'ay' => $postgres ? "CAST(EXTRACT(MONTH FROM {$tarih}) AS INTEGER)" : "CAST(strftime('%m', {$tarih}) AS INTEGER)",
            default => static::TABLO.'.'.$alan,
        };
    }
}
