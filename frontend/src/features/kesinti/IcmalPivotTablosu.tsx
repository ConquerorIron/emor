import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { DataTable, type DataTableKolonu } from '@/components/DataTable'
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
    placeholderData: keepPreviousData,
  })

  if (sonuc.error) {
    return (
      <ErrorState mesaj={t(apiErrorKey(sonuc.error))} tekrarDene={() => void sonuc.refetch()} />
    )
  }

  const grupHucresi = (satir: PivotSatiri, alan: string) => {
    const deger = satir[alan]

    if (deger === null || deger === '') {
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
      />
    </div>
  )
}
