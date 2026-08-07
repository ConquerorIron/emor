import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { ekipmanSecenekleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/**
 * Ekipman seçimi — ekranda ekipmanın ADI görünür, saklanan değer EKIPMAN_ID.
 * Liste projeye bağlı değildir (ekipman havuzu program genelindedir).
 */
export function EkipmanSecimi({ goster, hata, ...ortak }: KayitSecimProps) {
  const { t } = useTranslation()

  const ekipmanlar = useQuery({
    queryKey: queryKeys.secenekler.ekipmanlar,
    queryFn: ekipmanSecenekleriGetir,
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={ekipmanlar.data ?? []}
      goster={goster}
      yukleniyor={ekipmanlar.isPending}
      hata={hata ?? (ekipmanlar.isError ? t(apiErrorKey(ekipmanlar.error)) : undefined)}
    />
  )
}
