import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { duranVarlikSecenekleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/**
 * Duran varlık seçimi — kaynak, personele zimmetli varlıklardır. Ekranda
 * varlığın ADI görünür, saklanan değer DURAN_VARLIK_ID.
 */
export function DuranVarlikSecimi({ goster, hata, ...ortak }: KayitSecimProps) {
  const { t } = useTranslation()

  const varliklar = useQuery({
    queryKey: queryKeys.secenekler.duranVarliklar,
    queryFn: duranVarlikSecenekleriGetir,
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={varliklar.data ?? []}
      goster={goster}
      yukleniyor={varliklar.isPending}
      hata={hata ?? (varliklar.isError ? t(apiErrorKey(varliklar.error)) : undefined)}
    />
  )
}
