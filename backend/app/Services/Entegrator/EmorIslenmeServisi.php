<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Services\ErpFaturaKaynagi;

/**
 * eMOR kolonu: e-faturanın ERP'deki aşaması (EmorDurumu), PostgreSQL'e
 * yansıtılır. Liste ERP'ye her istekte gitmez (ERP yavaşsa/kapalıysa ekran
 * etkilenmez; filtre/sıralama/Excel aynı veriden çalışır); efatura:emor
 * komutu 5 dakikada bir tazeler, "ERP Senkronla" düğmesi hemen.
 *
 * - Gelen: TOHOM_FATURA'daki alış faturasının E_FATURA_ETTN'i → işlendi;
 *   değilse TOHOM_E_FATURA havuzunda (UUID) → havuzda; ikisi de değilse yok.
 * - Giden: ERP_GONDERILEN_E_FATURA_LISTESI; önce ETTN, ERP satırında ETTN
 *   yoksa (gider yansıtma) belge no + alıcı VKN'si. İki tarafta da ETTN varsa
 *   belge no ile eşleştirilmez (kullanıcı kuralı: yalnız fatura no ile olmaz).
 *
 * Gelen faturada vergi istisna kodu da aynı okumada havuzdan
 * (TOHOM_E_FATURA.VERGI_ISTISNA_KODU) ETTN ile tazelenir; İzibiz yanıtında yok.
 *
 * Yalnız DEĞİŞEN satırlar yazılır. ERP okunamazsa hiçbir alan değişmez
 * (istisna çağırana gider) — "okunamadı" asla "yok" sayılmaz.
 */
final class EmorIslenmeServisi
{
    private const PARCA = 1000;

    public function __construct(
        private readonly ErpFaturaKaynagi $erp,
    ) {}

    /**
     * @return array{islendi: int, havuzda: int, yok: int, degisen: int}
     */
    public function tazele(FaturaYonu $yon): array
    {
        $gelen = $yon === FaturaYonu::Gelen;
        // Gelen: [muhasebeleşmiş ETTN'ler, havuz ETTN => istisna kodu]
        $islenmis = $gelen ? $this->kume($this->erp->islenmisGelenEttnler()) : null;
        $havuz = $gelen ? $this->havuz() : null;
        $gidenIslendiMi = $gelen ? null : $this->gidenEslestirici();

        /** @var array<string, array<string, list<int>>> $degisenler kolon => yeni değer ('' = null) => id'ler */
        $degisenler = ['emor_durumu' => [], 'vergi_istisna_kodu' => []];
        $sayac = ['islendi' => 0, 'havuzda' => 0, 'yok' => 0];

        EFatura::query()
            ->where('yon', $yon->value)
            ->select(['id', 'ettn', 'belge_no', 'alici_vkn', 'emor_durumu', 'vergi_istisna_kodu'])
            ->lazyById(self::PARCA)
            ->each(function (EFatura $fatura) use ($islenmis, $havuz, $gidenIslendiMi, &$degisenler, &$sayac): void {
                $ettn = $this->ettn($fatura->ettn);

                if ($gidenIslendiMi !== null) {
                    $durum = $gidenIslendiMi($fatura) ? EmorDurumu::Islendi : EmorDurumu::Yok;
                } else {
                    $durum = match (true) {
                        isset($islenmis[$ettn]) => EmorDurumu::Islendi,
                        array_key_exists($ettn, $havuz ?? []) => EmorDurumu::Havuzda,
                        default => EmorDurumu::Yok,
                    };

                    $kod = $havuz[$ettn] ?? null;
                    if ($fatura->getAttribute('vergi_istisna_kodu') !== $kod) {
                        $degisenler['vergi_istisna_kodu'][$kod ?? ''][] = $fatura->id;
                    }
                }

                $sayac[$durum->value]++;

                if ($fatura->getAttribute('emor_durumu') !== $durum) {
                    $degisenler['emor_durumu'][$durum->value][] = $fatura->id;
                }
            });

        foreach ($degisenler as $kolon => $degerler) {
            foreach ($degerler as $deger => $idler) {
                foreach (array_chunk($idler, self::PARCA) as $parca) {
                    EFatura::query()->whereKey($parca)->update([$kolon => $deger === '' ? null : (string) $deger]);
                }
            }
        }

        $degisen = array_sum(array_map('count', $degisenler['emor_durumu']));

        return [...$sayac, 'degisen' => $degisen];
    }

    /**
     * @return array<string, string|null> küçük harf ETTN => istisna kodu
     */
    private function havuz(): array
    {
        $havuz = [];
        foreach ($this->erp->havuzdakiGelenler() as $ettn => $kod) {
            $havuz[$this->ettn((string) $ettn)] = $kod;
        }

        return $havuz;
    }

    /**
     * @param  list<string>  $ettnler
     * @return array<string, true>
     */
    private function kume(array $ettnler): array
    {
        return array_fill_keys(array_map($this->ettn(...), $ettnler), true);
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
