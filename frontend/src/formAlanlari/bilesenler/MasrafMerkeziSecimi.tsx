import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { masrafMerkeziSecenekleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/**
 * Talep satırının masraf merkezi seçimi. Kod ve ad sütunları bu kaydın iki
 * yüzüdür — kullanıcı ister koddan ister addan seçer, tek MASRAF_MERKEZI_ID
 * saklanır. Projesiz (genel) merkezler her projede listelenir.
 */
export function MasrafMerkeziSecimi({
  projemizId,
  goster,
  hata,
  disabled = false,
  ...ortak
}: KayitSecimProps) {
  const { t } = useTranslation()

  const merkezler = useQuery({
    queryKey: queryKeys.secenekler.masrafMerkezleri(projemizId),
    queryFn: () => masrafMerkeziSecenekleriGetir(projemizId),
    enabled: projemizId !== '',
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={merkezler.data ?? []}
      goster={goster}
      disabled={disabled || projemizId === ''}
      yukleniyor={projemizId !== '' && merkezler.isPending}
      hata={hata ?? (merkezler.isError ? t(apiErrorKey(merkezler.error)) : undefined)}
    />
  )
}
