<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\Firma;
use App\Models\ParaBirimi;
use App\Models\Proje;
use Collator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as TemelSorgu;

/**
 * Kesinti Özeti raporu (kullanıcı isteği 2026-11-13; kullanıcının Excel'deki
 * "… itibarıyla kesinti listesi"): Tüm Kesintiler'in seçilen tarihe kadarki
 * satırları, tek para biriminde (kur çevrimi yok).
 *
 * HAB Üstlendiği = yansıtma türü HAB olan satırların toplamı. Taşeron payı ve
 * onun İptal / Kesilen / Kalan'ı yansıtma türü TAŞERON olanlardan (her satırda
 * Toplam = İptal + Kesilen + Kalan). Yansıtma türü zorunlu olduğu için
 * Toplam = HAB + Taşeron payı.
 */
final class KesintiOzeti
{
    /** Genel özetin ve her grubun tutarları, sırasıyla */
    public const TUTARLAR = ['toplam', 'hab', 'taseron', 'iptal', 'kesilen', 'kalan'];

    /** "En yüksek kesintili taşeronlar" tablosundaki firma sayısı */
    public const TASERON_SAYISI = 10;

    public function __construct(private readonly TumKesintilerSorgusu $kesintiler) {}

    /**
     * Firma süzgeci her iki yansıtma türüne uygulanır: seçilen firmanın HAB'ın
     * üstlendiği kesintileri de rapora girer.
     *
     * @param  array{tarih: string, para_birimi_id: int, proje_id: list<int>, firma_id: list<int>}  $parametreler
     * @return array{
     *     tarih: string,
     *     para_birimi: string,
     *     isveren: string|null,
     *     projeler: list<string>,
     *     firmalar: list<string>,
     *     genel: array<string, string>,
     *     proje_dagilimi: list<array<string, string>>,
     *     tur_dagilimi: list<array<string, string>>,
     *     taseronlar: list<array<string, string>>,
     * }
     */
    public function hesapla(array $parametreler): array
    {
        $projeIdleri = $parametreler['proje_id'];
        $firmaIdleri = $parametreler['firma_id'];
        $sorgu = $this->kesintiler->raporSorgusu($parametreler);

        $projeDagilimi = $this->gruplar($sorgu, 'projeler', 'proje_id');
        // Proje kodları doğal sırayla: ZONE 7, ZONE 10'dan önce
        $siralayici = new Collator('tr_TR');
        $siralayici->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        usort($projeDagilimi, fn (array $a, array $b): int => (int) $siralayici->compare($a['kod'], $b['kod']));
        $projeler = $projeIdleri === []
            ? array_column($projeDagilimi, 'kod')
            : Proje::query()->whereKey($projeIdleri)->pluck('kod')->all();
        usort($projeler, fn (string $a, string $b): int => (int) $siralayici->compare($a, $b));
        $firmalar = $firmaIdleri === [] ? [] : Firma::query()->whereKey($firmaIdleri)->pluck('kod')->all();
        usort($firmalar, fn (string $a, string $b): int => (int) $siralayici->compare($a, $b));

        return [
            'tarih' => $parametreler['tarih'],
            'para_birimi' => (string) ParaBirimi::query()->whereKey($parametreler['para_birimi_id'])->value('kod'),
            'isveren' => Firma::query()->where('firmamiz', true)->value('kod'),
            // Başlıktaki "Bütçe": seçilen projeler; seçim yoksa veride geçenler
            'projeler' => $projeler,
            // Başlıktaki "Taşeron": yalnız firma seçildiyse (yazdırılan rapor süzüldüğünü göstersin)
            'firmalar' => $firmalar,
            'genel' => $this->tutarlar($this->toplamlariSec($sorgu->clone()->toBase())->first()),
            'proje_dagilimi' => $projeDagilimi,
            'tur_dagilimi' => $this->gruplar($sorgu, 'kesinti_turleri', 'kesinti_turu_id', toplamaGore: true),
            // Taşeron tablosu yalnız taşerona yansıtılanlardan: firmanın toplamı = taşeron payı
            'taseronlar' => $this->gruplar(
                $sorgu->clone()->where('tum_kesintiler.yansitma_turu', YansitmaTuru::Taseron->value),
                'firmalar',
                'firma_id',
                toplamaGore: true,
                sinir: self::TASERON_SAYISI,
            ),
        ];
    }

    /**
     * Tanıma (proje, kesinti türü, firma) göre gruplar: kod, ad ve tutarlar.
     * Sıra koda göre ya da toplama göre büyükten küçüğe.
     *
     * @param  Builder<Model>  $sorgu
     * @return list<array<string, string>>
     */
    private function gruplar(Builder $sorgu, string $tanimTablosu, string $yabanci, bool $toplamaGore = false, ?int $sinir = null): array
    {
        $taban = $this->toplamlariSec($sorgu->clone()->toBase())
            ->join("{$tanimTablosu} AS tanim", 'tanim.id', '=', "tum_kesintiler.{$yabanci}")
            ->addSelect(['tanim.kod'])
            ->selectRaw('MIN(tanim.ad) AS ad')
            ->groupBy('tanim.kod')
            ->when($toplamaGore, fn (TemelSorgu $q): TemelSorgu => $q->orderByRaw('SUM(tum_kesintiler.toplam_tutar) DESC'))
            ->orderBy('tanim.kod')
            ->when($sinir !== null, fn (TemelSorgu $q): TemelSorgu => $q->limit((int) $sinir));

        return $taban->get()
            ->map(fn (object $satir): array => [
                'kod' => (string) $satir->kod,
                'ad' => (string) $satir->ad,
                ...$this->tutarlar($satir),
            ])
            ->values()
            ->all();
    }

    /** HAB / taşeron ayrımlı toplamlar (yansıtma türleri bağlanan değer: koddaki sabit) */
    private function toplamlariSec(TemelSorgu $sorgu): TemelSorgu
    {
        $yansitmaya = 'SUM(CASE WHEN tum_kesintiler.yansitma_turu = ? THEN tum_kesintiler.%s ELSE 0 END) AS %s';

        return $sorgu->selectRaw(
            implode(', ', [
                'SUM(tum_kesintiler.toplam_tutar) AS toplam',
                sprintf($yansitmaya, 'toplam_tutar', 'hab'),
                sprintf($yansitmaya, 'toplam_tutar', 'taseron'),
                sprintf($yansitmaya, 'iptal_edilen_tutar', 'iptal'),
                sprintf($yansitmaya, 'kesilen_tutar', 'kesilen'),
                sprintf($yansitmaya, 'kalan_tutar', 'kalan'),
            ]),
            [
                YansitmaTuru::Hab->value,
                YansitmaTuru::Taseron->value,
                YansitmaTuru::Taseron->value,
                YansitmaTuru::Taseron->value,
                YansitmaTuru::Taseron->value,
            ],
        );
    }

    /**
     * Noktalı iki ondalıklı metin (diğer kesinti uçlarıyla aynı); boş toplam 0.
     *
     * @return array<string, string>
     */
    private function tutarlar(?object $satir): array
    {
        return array_combine(
            self::TUTARLAR,
            array_map(fn (string $alan): string => number_format((float) ($satir->{$alan} ?? 0), 2, '.', ''), self::TUTARLAR),
        );
    }
}
