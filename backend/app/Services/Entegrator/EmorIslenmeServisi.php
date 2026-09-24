<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Services\ErpFaturaKaynagi;

/**
 * eMOR kolonu: e-faturanın ERP'de karşılığı var mı, PostgreSQL'e yansıtır.
 * Liste ERP'ye her istekte gitmez (ERP yavaşsa/kapalıysa ekran etkilenmez;
 * filtre/sıralama/Excel aynı veriden çalışır); efatura:emor komutu 5 dakikada
 * bir tazeler, "ERP Senkronla" düğmesi hemen.
 *
 * - Gelen: TOHOM_FATURA'daki alış faturalarının E_FATURA_ETTN'i ile.
 * - Giden: ERP_GONDERILEN_E_FATURA_LISTESI; önce ETTN, ERP satırında ETTN
 *   yoksa (gider yansıtma) belge no + alıcı VKN'si. İki tarafta da ETTN varsa
 *   belge no ile eşleştirilmez (kullanıcı kuralı: yalnız fatura no ile olmaz).
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
    public function tazele(FaturaYonu $yon): array
    {
        $islendiMi = $yon === FaturaYonu::Gelen ? $this->gelenEslestirici() : $this->gidenEslestirici();

        /** @var array{islendi: list<int>, islenmedi: list<int>} $degisenler yeni değere göre */
        $degisenler = ['islendi' => [], 'islenmedi' => []];
        $sayac = ['islendi' => 0, 'islenmedi' => 0];

        EFatura::query()
            ->where('yon', $yon->value)
            ->select(['id', 'ettn', 'belge_no', 'alici_vkn', 'emor_islendi'])
            ->lazyById(self::PARCA)
            ->each(function (EFatura $fatura) use ($islendiMi, &$degisenler, &$sayac): void {
                $islendi = $islendiMi($fatura);
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

    /**
     * @return callable(EFatura): bool
     */
    private function gelenEslestirici(): callable
    {
        $ettnler = array_flip(array_map($this->ettn(...), $this->erp->islenmisGelenEttnler()));

        return fn (EFatura $fatura): bool => isset($ettnler[$this->ettn($fatura->ettn)]);
    }

    /**
     * @return callable(EFatura): bool
     */
    private function gidenEslestirici(): callable
    {
        $ettnler = [];
        $ettnsizler = [];

        foreach ($this->erp->gonderilenFaturalar() as $satir) {
            if ($satir['ettn'] !== null) {
                $ettnler[$this->ettn($satir['ettn'])] = true;
            } elseif ($satir['belge_no'] !== '' && $satir['vkn'] !== '') {
                $ettnsizler[$this->noVkn($satir['belge_no'], $satir['vkn'])] = true;
            }
        }

        return fn (EFatura $fatura): bool => isset($ettnler[$this->ettn($fatura->ettn)])
            || isset($ettnsizler[$this->noVkn($fatura->belge_no, (string) $fatura->getAttribute('alici_vkn'))]);
    }

    /** Karşılaştırma harf duyarsız (İzibiz ETTN'i küçük harfle saklanır). */
    private function ettn(string $ettn): string
    {
        return mb_strtolower(trim($ettn));
    }

    private function noVkn(string $belgeNo, string $vkn): string
    {
        return mb_strtoupper(trim($belgeNo)).'|'.trim($vkn);
    }
}
