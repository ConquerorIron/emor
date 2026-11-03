<?php

declare(strict_types=1);

namespace App\Services\Rapor;

use App\Services\ErpOkumaHatasi;
use App\Services\Kesinti\ErpFirmaKaynagi;
use App\Services\Kesinti\ErpProjeKaynagi;
use Collator;

/**
 * Rapor parametrelerinin seçenekleri: proc'lar proje KODU ve firma ADIYLA
 * süzer. Kaynak canlı ERP'nin WEB_ERP_PROJE_LISTESI / WEB_ERP_TASERON_LISTESI
 * view'ları (canlıda VOHOM_ARAMA_PARTI_YAMASI okunamıyor). Taşeron listesinde
 * olmayan cari (ör. gider yansıtmada birkaç ünvan) burada seçilemez; ekranın
 * anlık süzgeci gelen veriden olduğu için orada seçilebilir.
 */
final class ErpRaporSecenekleri
{
    public function __construct(
        private readonly ErpProjeKaynagi $projeler,
        private readonly ErpFirmaKaynagi $firmalar,
    ) {}

    /**
     * @return array{projeler: list<array{kod: string, ad: string}>, firmalar: list<string>}
     *
     * @throws ErpOkumaHatasi
     */
    public function getir(): array
    {
        // Türkçe harf sırası; sayılar sayı gibi (ZONE 7, ZONE 10'dan önce)
        $siralayici = new Collator('tr_TR');
        $siralayici->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);

        $projeListesi = [];
        foreach ($this->projeler->projeler() as $proje) {
            if ($proje['kod'] !== '') {
                $projeListesi[$proje['kod']] = ['kod' => $proje['kod'], 'ad' => $proje['ad']];
            }
        }
        $projeListesi = array_values($projeListesi);
        usort($projeListesi, fn (array $a, array $b): int => (int) $siralayici->compare($a['kod'], $b['kod']));

        $firmaAdlari = array_values(array_unique(array_filter(
            array_column($this->firmalar->firmalar(), 'ad'),
            fn (string $ad): bool => $ad !== '',
        )));
        $siralayici->sort($firmaAdlari);

        return ['projeler' => $projeListesi, 'firmalar' => $firmaAdlari];
    }
}
