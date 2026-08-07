import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { aktiviteSecenekleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/**
 * Talep satırının aktivite seçimi — kaynak, seçili projenin iş programındaki
 * aktivitelerdir (backend projeden iş programını kendisi çözer). Kod ve
 * açıklama sütunları bu kaydın iki yüzüdür.
 */
export function AktiviteSecimi({
  projemizId,
  goster,
  hata,
  disabled = false,
  ...ortak
}: KayitSecimProps) {
  const { t } = useTranslation()

  const aktiviteler = useQuery({
    queryKey: queryKeys.secenekler.aktiviteler(projemizId),
    queryFn: () => aktiviteSecenekleriGetir(projemizId),
    // Proje seçilmeden liste anlamsız
    enabled: projemizId !== '',
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={aktiviteler.data ?? []}
      goster={goster}
      disabled={disabled || projemizId === ''}
      yukleniyor={projemizId !== '' && aktiviteler.isPending}
      hata={hata ?? (aktiviteler.isError ? t(apiErrorKey(aktiviteler.error)) : undefined)}
    />
  )
}
