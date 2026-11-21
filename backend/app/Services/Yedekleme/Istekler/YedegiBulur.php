<?php

declare(strict_types=1);

namespace App\Services\Yedekleme\Istekler;

use App\Models\Yedek;
use App\Services\Yedekleme\YedekDurumu;

/**
 * `yedek_kimligi` parametreli işleyicilerin ortak ön koşulu.
 */
trait YedegiBulur
{
    /**
     * @throws IstekReddi YEDEK_BULUNAMADI; $silinmisOlmamali iken YEDEK_SILINMIS
     */
    private function yedegiBul(Istek $istek, bool $silinmisOlmamali = true): Yedek
    {
        $yedek = Yedek::query()->where('kimlik', (string) $istek->metin('yedek_kimligi'))->first();

        if ($yedek === null) {
            throw new IstekReddi('YEDEK_BULUNAMADI', 'Yedek bulunamadı.');
        }

        if ($silinmisOlmamali && $yedek->durum === YedekDurumu::Silindi) {
            throw new IstekReddi('YEDEK_SILINMIS', 'Yedek silinmiş.');
        }

        return $yedek;
    }
}
