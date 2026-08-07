import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { butceKalemiSecenekleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/**
 * Bütçe kalemi seçimi — ekranda kalemin ADI görünür, saklanan değer
 * HARCAMA_KALEMI_ID. Liste seçili projenin bütçesinden gelir; bütçede ya da
 * nakit akışında kullanılmayan kalemler listelenmez.
 */
export function ButceKalemiSecimi({
  projemizId,
  goster,
  hata,
  disabled = false,
  ...ortak
}: KayitSecimProps) {
  const { t } = useTranslation()

  const kalemler = useQuery({
    queryKey: queryKeys.secenekler.butceKalemleri(projemizId),
    queryFn: () => butceKalemiSecenekleriGetir(projemizId),
    enabled: projemizId !== '',
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={kalemler.data ?? []}
      goster={goster}
      disabled={disabled || projemizId === ''}
      yukleniyor={projemizId !== '' && kalemler.isPending}
      hata={hata ?? (kalemler.isError ? t(apiErrorKey(kalemler.error)) : undefined)}
    />
  )
}
