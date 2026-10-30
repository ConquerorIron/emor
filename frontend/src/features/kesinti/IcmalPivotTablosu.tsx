import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { DataTable, type DataTableKolonu, type ToplamSatiri } from '@/components/DataTable'
import { ErrorState } from '@/components/ErrorState'
import { siralaUygula } from '@/components/SiralanabilirBaslik'
import { useKaliciSiralama } from '@/hooks/useKaliciSiralama'

import { tutarGoster } from './bicim'
import type { IcmalFiltresi, IcmalSatiri, PivotSatiri } from './icmalApi'
import { ayNumarasiAdi, type IcmalTanimi } from './icmalTanimi'

type Pivot = NonNullable<IcmalTanimi<IcmalSatiri>['pivotlar']>[number]

/**
 * Özet (pivot) sekmesi: listeyle aynı filtredeki satırlar sekmenin alanlarına
 * göre gruplanır, sayılar toplanır (sunucuda). Kolonlara göre ekranda sıralanır.
 *
 * Sekme başına ayrı çizilmelidir (`key={pivot.anahtar}`): filtre değişirken
 * önceki sonuç ekranda kalır, ama başka sekmenin satırları bu sekmenin
 * kolonlarıyla gösterilmemeli ve sıralama tercihi sekmeden sekmeye taşınmamalı.
 */
export function IcmalPivotTablosu<T extends IcmalSatiri>({
  tanim,
  pivot,
  filtre,
}: {
  tanim: IcmalTanimi<T>
  pivot: Pivot
  filtre: IcmalFiltresi
}) {
  const { t, i18n } = useTranslation()
  const { siralama, siralamaDegistir } = useKaliciSiralama(`${tanim.anahtar}Pivot.${pivot.anahtar}`)

  const sonuc = useQuery({
    queryKey: queryKeys.kesinti.icmalPivotu(tanim.anahtar, pivot.anahtar, { ...filtre }),
    queryFn: () => tanim.api.pivot(pivot.anahtar, filtre),
    // Yalnız aynı sekmenin önceki sonucu (filtre değişirken) yer tutar
    placeholderData: (onceki, oncekiSorgu) =>
      oncekiSorgu?.queryKey[4] === pivot.anahtar ? keepPreviousData(onceki) : undefined,
  })

  if (sonuc.error) {
    return (
      <ErrorState mesaj={t(apiErrorKey(sonuc.error))} tekrarDene={() => void sonuc.refetch()} />
    )
  }

  const grupHucresi = (satir: PivotSatiri, alan: string) => {
    const deger = satir[alan]

    if (deger === undefined || deger === null || deger === '') {
      return <span className="text-slate-400">—</span>
    }

    switch (alan) {
      case 'taseron':
        return <strong title={String(satir.taseron_ad ?? '')}>{deger}</strong>
      case 'ay':
        return ayNumarasiAdi(Number(deger), i18n.language)
      case 'yil':
        return deger
      default: {
        // Sabit seçenekler (yansıtma türü, vasıf, mesai türü) etiketiyle
        const tur = tanim.alanlar[alan]

        return tur.tur === 'secenek' ? t(`kesinti.secenek.${alan}.${String(deger)}`) : deger
      }
    }
  }

  const kolonlar: DataTableKolonu<PivotSatiri>[] = [
    ...pivot.gruplar.map((alan): DataTableKolonu<PivotSatiri> => ({
      anahtar: alan,
      baslik: t(`kesinti.alan.${alan}`),
      siralamaAnahtari: alan,
      render: (satir) => <span className="whitespace-nowrap">{grupHucresi(satir, alan)}</span>,
    })),
    {
      anahtar: 'satir_sayisi',
      baslik: t('kesinti.icmal.pivotSatirSayisi'),
      siralamaAnahtari: 'satir_sayisi',
      hizala: 'sag',
      render: (satir) => (
        <span className="whitespace-nowrap tabular-nums">
          {satir.satir_sayisi.toLocaleString('tr-TR')}
        </span>
      ),
    },
    ...pivot.toplamlar.map((alan): DataTableKolonu<PivotSatiri> => ({
      anahtar: alan,
      baslik: t(`kesinti.alan.${alan}`),
      siralamaAnahtari: alan,
      hizala: 'sag',
      render: (satir) => (
        <span className="whitespace-nowrap tabular-nums">
          {tutarGoster(String(satir[alan] ?? '0'))}
        </span>
      ),
    })),
  ]

  // Toplamlar metin gelir ("1234.50"): sayı olarak sıralanır
  const sayisal = Object.fromEntries(
    [...pivot.toplamlar, 'yil', 'ay'].map((alan) => [
      alan,
      (satir: PivotSatiri) => Number(satir[alan] ?? 0),
    ]),
  )

  return (
    <div className={sonuc.isPlaceholderData ? 'opacity-50' : undefined}>
      <DataTable
        kolonlar={kolonlar}
        satirlar={siralaUygula(sonuc.data ?? [], siralama, sayisal)}
        satirAnahtari={(satir) => pivot.gruplar.map((alan) => String(satir[alan])).join('|')}
        yukleniyor={sonuc.isPending}
        siralama={siralama}
        siralamaDegistir={siralamaDegistir}
        ustKaydirma
        toplamSatirlari={pivotToplamlari(
          sonuc.data ?? [],
          pivot.toplamlar,
          t('kesinti.icmal.toplamSatiri'),
        )}
      />
    </div>
  )
}

/**
 * Tablonun altında ve üstünde para birimine göre toplamlar: grupların
 * toplamları kuruş üzerinden toplanır (float yuvarlaması olmasın).
 */
function pivotToplamlari(
  satirlar: PivotSatiri[],
  alanlar: string[],
  etiket: string,
): ToplamSatiri[] {
  const paraBirimleri = new Map<string, { satir: number; kurus: Record<string, number> }>()

  for (const satir of satirlar) {
    const kod = String(satir.para_birimi ?? '')
    const toplam = paraBirimleri.get(kod) ?? { satir: 0, kurus: {} }
    toplam.satir += satir.satir_sayisi

    for (const alan of alanlar) {
      toplam.kurus[alan] = (toplam.kurus[alan] ?? 0) + Math.round(Number(satir[alan] ?? 0) * 100)
    }
    paraBirimleri.set(kod, toplam)
  }

  return [...paraBirimleri.entries()]
    .sort(([a], [b]) => a.localeCompare(b, 'tr'))
    .map(([kod, toplam]) => ({
      anahtar: kod,
      etiket,
      hucreler: {
        para_birimi: kod,
        satir_sayisi: <span className="tabular-nums">{toplam.satir.toLocaleString('tr-TR')}</span>,
        ...Object.fromEntries(
          alanlar.map((alan) => [
            alan,
            <span key={alan} className="tabular-nums">
              {tutarGoster((toplam.kurus[alan] ?? 0) / 100)}
            </span>,
          ]),
        ),
      },
    }))
}
