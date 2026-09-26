<?php

declare(strict_types=1);

namespace App\Services\Entegrator;

use App\Models\EFatura;
use App\Services\EntegratorBaglantiServisi;
use App\Services\ErpFaturaKaynagi;

/**
 * eMOR kolonu: e-faturanın ERP'deki aşaması (EmorDurumu), PostgreSQL'e
 * yansıtılır. Liste ERP'ye her istekte gitmez (ERP yavaşsa/kapalıysa ekran
 * etkilenmez; filtre/sıralama/Excel aynı veriden çalışır); efatura:emor
 * komutu 5 dakikada bir tazeler, "ERP Senkronla" düğmesi hemen.
 *
 * - Gelen: alış faturasının (TOHOM_FATURA) ya da harcama belgesinin
 *   (TOHOM_HARCAMA_BELGESI) E_FATURA_ETTN'i → işlendi; değilse ETTN'siz elle
 *   girilmiş kayıtta fatura no + gönderen VKN birlikte → elle işlendi; değilse
 *   TOHOM_E_FATURA havuzunda (UUID) → havuzda; hiçbiri değilse yok.
 * - Giden: ERP_GONDERILEN_E_FATURA_LISTESI; önce ETTN, ERP satırında ETTN
 *   yoksa (gider yansıtma) belge no + alıcı VKN'si. İki tarafta da ETTN varsa
 *   belge no ile eşleştirilmez (kullanıcı kuralı: yalnız fatura no ile olmaz).
 *
 * Gelen faturada vergi istisna kodu ve GB/PK etiketleri de aynı okumada
 * havuzdan (TOHOM_E_FATURA) ETTN ile tazelenir; İzibiz yanıtında bunlar yok
 * (etiketler neredeyse hep boş gelir).
 *
 * Yalnız DEĞİŞEN satırlar yazılır. ERP okunamazsa hiçbir alan değişmez
 * (istisna çağırana gider) — "okunamadı" asla "yok" sayılmaz.
 */
final class EmorIslenmeServisi
{
    private const PARCA = 1000;

    /** Havuzdan (TOHOM_E_FATURA) yazılan kolonlar: kolon => havuz alanı */
    private const HAVUZ_KOLONLARI = [
        'vergi_istisna_kodu' => 'istisna_kodu',
        'erp_gonderici_etiketi' => 'gonderici_etiketi',
        'erp_alici_etiketi' => 'alici_etiketi',
    ];

    public function __construct(
        private readonly ErpFaturaKaynagi $erp,
        private readonly EntegratorBaglantiServisi $baglantilar,
    ) {}

    /**
     * @return array{islendi: int, elle_islendi: int, havuzda: int, yok: int, degisen: int}
     */
    public function tazele(FaturaYonu $yon): array
    {
        $tanim = $this->baglantilar->aktif();
        if ($tanim === null) {
            return ['islendi' => 0, 'elle_islendi' => 0, 'havuzda' => 0, 'yok' => 0, 'degisen' => 0];
        }

        $gelen = $yon === FaturaYonu::Gelen;
        // Gelen: [muhasebeleşmiş ETTN'ler, elle işlenmiş no+VKN'ler, havuz ETTN => ERP bilgileri]
        $islenmis = $gelen ? $this->kume($this->erp->islenmisGelenEttnler()) : null;
        $elleIslenmis = $gelen ? $this->noVknKumesi($this->erp->islenmisGelenBelgeler()) : null;
        $havuz = $gelen ? $this->havuz() : null;
        $gidenIslendiMi = $gelen ? null : $this->gidenEslestirici();

        /** @var array<string, array<string, list<int>>> $degisenler kolon => yeni değer ('' = null) => id'ler */
        $degisenler = ['emor_durumu' => [], ...array_fill_keys(array_keys(self::HAVUZ_KOLONLARI), [])];
        $sayac = ['islendi' => 0, 'elle_islendi' => 0, 'havuzda' => 0, 'yok' => 0];

        EFatura::query()
            ->where('entegrator_baglanti_id', $tanim->id)
            ->where('yon', $yon->value)
            ->select(['id', 'ettn', 'belge_no', 'gonderici_vkn', 'alici_vkn', 'emor_durumu', ...array_keys(self::HAVUZ_KOLONLARI)])
            ->lazyById(self::PARCA)
            ->each(function (EFatura $fatura) use ($islenmis, $elleIslenmis, $havuz, $gidenIslendiMi, &$degisenler, &$sayac): void {
                $ettn = $this->ettn($fatura->ettn);

                if ($gidenIslendiMi !== null) {
                    $durum = $gidenIslendiMi($fatura) ? EmorDurumu::Islendi : EmorDurumu::Yok;
                } else {
                    $durum = match (true) {
                        isset($islenmis[$ettn]) => EmorDurumu::Islendi,
                        isset($elleIslenmis[$this->noVkn($fatura->belge_no, (string) $fatura->getAttribute('gonderici_vkn'))]) => EmorDurumu::ElleIslendi,
                        array_key_exists($ettn, $havuz ?? []) => EmorDurumu::Havuzda,
                        default => EmorDurumu::Yok,
                    };

                    // Havuzda olmayan faturanın ERP bilgisi boşalır
                    foreach (self::HAVUZ_KOLONLARI as $kolon => $alan) {
                        $deger = $havuz[$ettn][$alan] ?? null;
                        if ($fatura->getAttribute($kolon) !== $deger) {
                            $degisenler[$kolon][$deger ?? ''][] = $fatura->id;
                        }
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
     * @return array<string, array{istisna_kodu: string|null, gonderici_etiketi: string|null, alici_etiketi: string|null}> küçük harf ETTN => ERP bilgileri
     */
    private function havuz(): array
    {
        $havuz = [];
        foreach ($this->erp->havuzdakiGelenler() as $ettn => $bilgi) {
            $havuz[$this->ettn((string) $ettn)] = $bilgi;
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
     * @param  list<array{belge_no: string, vkn: string}>  $belgeler
     * @return array<string, true>
     */
    private function noVknKumesi(array $belgeler): array
    {
        $kume = [];
        foreach ($belgeler as $belge) {
            if (trim($belge['belge_no']) !== '' && trim($belge['vkn']) !== '') {
                $kume[$this->noVkn($belge['belge_no'], $belge['vkn'])] = true;
            }
        }

        return $kume;
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
