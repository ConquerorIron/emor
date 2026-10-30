<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Models\IsgucuKesintisi;
use App\Models\KesintiTuru;
use App\Models\ParaBirimi;
use App\Models\Proje;
use Illuminate\Database\Eloquent\Builder;

/**
 * İşgücü Kesintileri listesinin filtresi, sıralaması ve para birimine göre
 * toplamları (Excel'in üstündeki ara toplam satırının karşılığı).
 */
final class IsgucuKesintisiSorgusu
{
    /** İlişkili tanımlar koduna göre sıralanır: sıralama anahtarı => [model, yabancı anahtar] */
    private const ILISKILI = [
        'kesinti_turu' => [KesintiTuru::class, 'kesinti_turu_id'],
        'taseron' => [Firma::class, 'firma_id'],
        'proje' => [Proje::class, 'proje_id'],
        'para_birimi' => [ParaBirimi::class, 'para_birimi_id'],
    ];

    /** Kendi kolonuna göre sıralanan alanlar (ham ifadeye yalnız bunlar girer) */
    private const KOLONLAR = [
        'tarih', 'mahal', 'kaynak_firma', 'kaynak_fis_no', 'adi_soyadi', 'vasif', 'mesai_turu', 'yansitma_turu',
        'tanim', 'gorev', 'departman', 'aktivite', 'miktar_saat', 'miktar_yevmiye', 'birim_fiyat',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    public const SIRALAMALAR = [...self::KOLONLAR, 'kesinti_turu', 'taseron', 'proje', 'para_birimi'];

    /** Aramanın baktığı metin kolonları */
    private const ARANANLAR = ['adi_soyadi', 'kaynak_fis_no', 'kaynak_firma', 'mahal', 'tanim', 'gorev', 'departman', 'aktivite'];

    private const TUTARLAR = ['toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar'];

    /**
     * @param  array{ara?: string|null, firma_id?: int|string|null, proje_id?: int|string|null, yukleme_id?: int|string|null, baslangic?: string|null, bitis?: string|null}  $filtre
     * @return Builder<IsgucuKesintisi>
     */
    public function filtrele(array $filtre): Builder
    {
        $sorgu = IsgucuKesintisi::query();

        foreach (['firma_id', 'proje_id', 'yukleme_id'] as $alan) {
            if (filled($filtre[$alan] ?? null)) {
                $sorgu->where("isgucu_kesintileri.{$alan}", (int) $filtre[$alan]);
            }
        }

        if (filled($filtre['baslangic'] ?? null)) {
            $sorgu->where('isgucu_kesintileri.tarih', '>=', $filtre['baslangic']);
        }

        if (filled($filtre['bitis'] ?? null)) {
            $sorgu->where('isgucu_kesintileri.tarih', '<=', $filtre['bitis']);
        }

        $ara = trim((string) ($filtre['ara'] ?? ''));

        if ($ara !== '') {
            $desen = '%'.addcslashes($ara, '%_\\').'%';
            $sorgu->where(function (Builder $q) use ($desen): void {
                foreach (self::ARANANLAR as $kolon) {
                    $q->orWhereLike("isgucu_kesintileri.{$kolon}", $desen);
                }
            });
        }

        return $sorgu;
    }

    /**
     * @param  Builder<IsgucuKesintisi>  $sorgu
     * @return Builder<IsgucuKesintisi>
     */
    public function sirala(Builder $sorgu, ?string $anahtar, ?string $yon): Builder
    {
        $yon = $yon === 'asc' ? 'asc' : 'desc';

        if (isset(self::ILISKILI[$anahtar])) {
            [$model, $yabanci] = self::ILISKILI[$anahtar];
            $tablo = (new $model)->getTable();
            $sorgu->orderBy($model::query()->select('kod')->whereColumn("{$tablo}.id", "isgucu_kesintileri.{$yabanci}"), $yon);
        } else {
            $kolon = in_array($anahtar, self::KOLONLAR, true) ? $anahtar : 'tarih';
            // Boş değerler yönden bağımsız en sonda (PostgreSQL ile SQLite'ın NULL sırası farklı)
            $sorgu->orderByRaw("CASE WHEN isgucu_kesintileri.{$kolon} IS NULL THEN 1 ELSE 0 END")
                ->orderBy("isgucu_kesintileri.{$kolon}", $yon);
        }

        return $sorgu->orderBy('isgucu_kesintileri.id', $yon);
    }

    /**
     * Filtredeki satırların para birimine göre toplamları.
     *
     * @param  Builder<IsgucuKesintisi>  $sorgu
     * @return list<array{para_birimi: string, satir_sayisi: int, toplam_tutar: string, iptal_edilen_tutar: string, yansitilan_tutar: string, kesilen_tutar: string, kalan_tutar: string}>
     */
    public function ozet(Builder $sorgu): array
    {
        $toplamlar = implode(', ', array_map(fn (string $alan): string => "SUM(isgucu_kesintileri.{$alan}) AS {$alan}", self::TUTARLAR));

        return $sorgu->clone()
            ->join('para_birimleri', 'para_birimleri.id', '=', 'isgucu_kesintileri.para_birimi_id')
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
