import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { partiYamasiPersonelleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/**
 * Parti yaması ağacındaki personel seçimi (TUR=2) — saklanan değer
 * PARTI_YAMASI_ID.
 *
 * Başlıktaki `PersonelSecimi` ile karıştırılmamalı: o, İK personel kartlarını
 * (VOHOM_ARAMA_PERSONEL / PERSONEL_ID) listeler. İki liste farklı view'lardan
 * gelir ve kimlikleri farklı alanlardır.
 */
export function PartiYamasiPersonelSecimi({ goster, hata, ...ortak }: KayitSecimProps) {
  const { t } = useTranslation()

  const personeller = useQuery({
    queryKey: queryKeys.secenekler.partiYamasiPersonelleri,
    queryFn: partiYamasiPersonelleriGetir,
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={personeller.data ?? []}
      goster={goster}
      yukleniyor={personeller.isPending}
      hata={hata ?? (personeller.isError ? t(apiErrorKey(personeller.error)) : undefined)}
    />
  )
}
