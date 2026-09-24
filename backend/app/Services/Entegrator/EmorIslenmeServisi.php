<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Services\ErpFaturaKaynagi;

/**
 * eMOR kolonu: gelen faturaların ERP'ye işlenip işlenmediğini PostgreSQL'e
 * yansıtır. Liste ERP'ye her istekte gitmez (ERP yavaşsa/kapalıysa ekran
 * etkilenmez; filtre/sıralama/Excel aynı veriden çalışır); efatura:emor
 * komutu 5 dakikada bir tazeler.
 *
 * Yalnız DEĞİŞEN satırlar yazılır. ERP okunamazsa hiçbir bayrak değişmez
 * (istisna çağırana gider) — "okunamadı" asla "işlenmedi" sayılmaz.
 */
final class EmorIslenmeServisi
{
    private const PARCA = 1000;

    public function __construct(
        private readonly ErpFaturaKaynagi $erp,
    ) {}

    /**
     * @return array{islendi: int, islenmedi: int, degisen: int}
     */
    public function tazele(): array
    {
        // Karşılaştırma harf duyarsız (İzibiz ETTN'i küçük harfle saklanır)
        $islenmis = array_flip(array_map(
            fn (string $ettn): string => mb_strtolower(trim($ettn)),
            $this->erp->islenmisGelenEttnler(),
        ));

        /** @var array{islendi: list<int>, islenmedi: list<int>} $degisenler yeni değere göre */
        $degisenler = ['islendi' => [], 'islenmedi' => []];
        $sayac = ['islendi' => 0, 'islenmedi' => 0];

        EFatura::query()
            ->where('yon', FaturaYonu::Gelen->value)
            ->select(['id', 'ettn', 'emor_islendi'])
            ->lazyById(self::PARCA)
            ->each(function (EFatura $fatura) use ($islenmis, &$degisenler, &$sayac): void {
                $islendi = isset($islenmis[mb_strtolower($fatura->ettn)]);
                $durum = $islendi ? 'islendi' : 'islenmedi';
                $sayac[$durum]++;

                if ($fatura->getAttribute('emor_islendi') !== $islendi) {
                    $degisenler[$durum][] = $fatura->id;
                }
            });

        foreach ($degisenler as $durum => $idler) {
            foreach (array_chunk($idler, self::PARCA) as $parca) {
                EFatura::query()->whereKey($parca)->update(['emor_islendi' => $durum === 'islendi']);
            }
        }

        return [...$sayac, 'degisen' => count($degisenler['islendi']) + count($degisenler['islenmedi'])];
    }
}
