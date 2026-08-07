import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { ambalajSecenekleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/**
 * Ambalaj (kap) seçimi — ekranda kabın ADI görünür, saklanan değer KAP_ID.
 * Seçim, ambalaj miktarının ondalık basamak sayısını da satıra taşır.
 */
export function AmbalajSecimi({ goster, hata, ...ortak }: KayitSecimProps) {
  const { t } = useTranslation()

  const ambalajlar = useQuery({
    queryKey: queryKeys.secenekler.ambalajlar,
    queryFn: ambalajSecenekleriGetir,
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={ambalajlar.data ?? []}
      goster={goster}
      yukleniyor={ambalajlar.isPending}
      hata={hata ?? (ambalajlar.isError ? t(apiErrorKey(ambalajlar.error)) : undefined)}
    />
  )
}
