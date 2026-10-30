<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
     * Filtredeki satırların para birimine göre toplamları.
     *
     * @param  Builder<Model>  $sorgu
     * @return list<array{para_birimi: string, satir_sayisi: int, toplam_tutar: string, iptal_edilen_tutar: string, yansitilan_tutar: string, kesilen_tutar: string, kalan_tutar: string}>
     */
    public function ozet(Builder $sorgu): array
    {
        $tablo = static::TABLO;
        $toplamlar = implode(', ', array_map(fn (string $alan): string => "SUM({$tablo}.{$alan}) AS {$alan}", self::TUTARLAR));

        /** @var list<array{para_birimi: string, satir_sayisi: int, toplam_tutar: string, iptal_edilen_tutar: string, yansitilan_tutar: string, kesilen_tutar: string, kalan_tutar: string}> */
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
                ...array_combine(self::TUTARLAR, array_map(fn (string $alan): string => number_format((float) $satir->{$alan}, 2, '.', ''), self::TUTARLAR)),
            ])
            ->values()
            ->all();
    }
}
