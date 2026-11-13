<?php

declare(strict_types=1);

namespace App\Services\Kesinti;

use App\Models\IsgCezaKesintisi;
use App\Models\IsgucuKesintisi;
use App\Models\KampKesintisi;
use App\Models\KampMalzemeKesintisi;
use App\Models\KartBasimKesintisi;
use App\Models\KesintiYuklemesi;
use App\Models\MakinaEkipmanKesintisi;
use App\Models\MalzemeKesintisi;
use App\Models\OsgbKesintisi;
use App\Models\TetkikKesintisi;
use App\Models\TumKesinti;
use App\Models\TutanakKesintisi;
use App\Models\YemekKesintisi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as TemelSorgu;
use Illuminate\Support\Facades\DB;

/**
 * Tüm Kesintiler raporu (kullanıcı isteği 2026-10-31): bütün icmal
 * tablolarının ortak kolonları tek listede (UNION ALL). Filtre, sıralama,
 * toplamlar ve özet sekmeleri IcmalSorgusu'nun aynısı; veri girilmez.
 */
final class TumKesintilerSorgusu extends IcmalSorgusu
{
    protected const TABLO = 'tum_kesintiler';

    protected const MODEL = TumKesinti::class;

    protected const KOLONLAR = [
        'tarih', 'yansitma_turu', 'mahal', 'kaynak_firma',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    protected const ARANANLAR = ['kaynak_firma', 'mahal'];

    /** Özet sekmeleri (kullanıcı isteği 2026-10-31); yalnız 5 tutar toplanır */
    protected const PIVOTLAR = [
        'taseron' => ['taseron', 'proje', 'para_birimi'],
        'yansitma_turu' => ['yansitma_turu', 'proje', 'para_birimi'],
        'proje' => ['proje', 'para_birimi'],
        'tarih' => ['yil', 'ay', 'proje', 'para_birimi'],
    ];

    /**
     * Birleşime giren icmaller: anahtar => satır modeli. Yeni icmal ekranı
     * buraya da eklenir (TumKesintilerTest her ICMALLER'in burada olduğunu denetler).
     *
     * @var array<string, class-string<Model>>
     */
    public const KAYNAKLAR = [
        KesintiYuklemesi::ICMAL_ISGUCU => IsgucuKesintisi::class,
        KesintiYuklemesi::ICMAL_KAMP => KampKesintisi::class,
        KesintiYuklemesi::ICMAL_KAMP_MALZEME => KampMalzemeKesintisi::class,
        KesintiYuklemesi::ICMAL_MAKINA_EKIPMAN => MakinaEkipmanKesintisi::class,
        KesintiYuklemesi::ICMAL_TUTANAK => TutanakKesintisi::class,
        KesintiYuklemesi::ICMAL_YEMEK => YemekKesintisi::class,
        KesintiYuklemesi::ICMAL_OSGB => OsgbKesintisi::class,
        KesintiYuklemesi::ICMAL_TETKIK => TetkikKesintisi::class,
        KesintiYuklemesi::ICMAL_ISG_CEZA => IsgCezaKesintisi::class,
        KesintiYuklemesi::ICMAL_MALZEME => MalzemeKesintisi::class,
        KesintiYuklemesi::ICMAL_KART_BASIM => KartBasimKesintisi::class,
    ];

    /** Her icmal tablosunda aynı adla duran kolonlar */
    private const ORTAK_KOLONLAR = [
        'id', 'yukleme_id', 'kesinti_turu_id', 'firma_id', 'yansitma_turu', 'proje_id', 'para_birimi_id',
        'mahal', 'tarih', 'kaynak_firma',
        'toplam_tutar', 'iptal_edilen_tutar', 'yansitilan_tutar', 'kesilen_tutar', 'kalan_tutar',
    ];

    /**
     * Kesinti raporlarının (Kesinti Özeti, Firma Özeti) ortak süzgeci: seçilen
     * tarihe kadarki satırlar, tek para biriminde; projeler ve firmalar boşsa hepsi.
     *
     * @param  array{tarih: string, para_birimi_id: int, proje_id: list<int>, firma_id: list<int>}  $parametreler
     * @return Builder<Model>
     */
    public function raporSorgusu(array $parametreler): Builder
    {
        return $this->filtrele(['bitis' => $parametreler['tarih']])
            ->where(self::TABLO.'.para_birimi_id', $parametreler['para_birimi_id'])
            ->when($parametreler['proje_id'] !== [], fn (Builder $q): Builder => $q->whereIn(self::TABLO.'.proje_id', $parametreler['proje_id']))
            ->when($parametreler['firma_id'] !== [], fn (Builder $q): Builder => $q->whereIn(self::TABLO.'.firma_id', $parametreler['firma_id']));
    }

    /**
     * Kesinti raporlarının parametre seçimleri: kesintide geçen projeler ve
     * firmalar (koduna göre) ve para birimleri (en çok satırı olan önce —
     * ekranın varsayılanı).
     *
     * @return array{projeler: list<array{id: int, kod: string, ad: string}>, firmalar: list<array{id: int, kod: string, ad: string}>, para_birimleri: list<array{id: int, kod: string}>}
     */
    public function raporSecenekleri(): array
    {
        $paraBirimleri = $this->filtrele([])->toBase()
            ->join('para_birimleri', 'para_birimleri.id', '=', self::TABLO.'.para_birimi_id')
            ->groupBy('para_birimleri.id', 'para_birimleri.kod')
            ->orderByRaw('COUNT(*) DESC')
            ->orderBy('para_birimleri.kod')
            ->select(['para_birimleri.id', 'para_birimleri.kod'])
            ->get()
            ->map(fn (object $para): array => ['id' => (int) $para->id, 'kod' => (string) $para->kod])
            ->all();
        $tanimlar = $this->filtreSecenekleri();

        return [
            'projeler' => $tanimlar['projeler'],
            'firmalar' => $tanimlar['firmalar'],
            'para_birimleri' => $paraBirimleri,
        ];
    }

    /**
     * Bütün icmal tablolarının ortak kolonları + satırın icmali, tek "tablo" olarak.
     *
     * @return Builder<Model>
     */
    protected function temelSorgu(): Builder
    {
        $birlesim = null;

        foreach (self::KAYNAKLAR as $icmal => $model) {
            $tablo = (new $model)->getTable();
            // Anahtar koddaki sabit: ham ifadeye kullanıcı girdisi girmez
            $parca = DB::table($tablo)
                ->select(array_map(fn (string $kolon): string => "{$tablo}.{$kolon}", self::ORTAK_KOLONLAR))
                ->selectRaw("'{$icmal}' AS icmal");
            $birlesim = $birlesim instanceof TemelSorgu ? $birlesim->unionAll($parca) : $parca;
        }

        return TumKesinti::query()->fromSub($birlesim, self::TABLO);
    }

    /**
     * id yalnız kendi tablosunda benzersiz: aynı id'li satırlar icmale göre
     * sıralanır (sayfalar arasında satır kayıp / tekrar olmasın).
     *
     * @param  Builder<Model>  $sorgu
     * @return Builder<Model>
     */
    public function sirala(Builder $sorgu, ?string $anahtar, ?string $yon): Builder
    {
        return parent::sirala($sorgu, $anahtar, $yon)->orderBy(self::TABLO.'.icmal');
    }
}
