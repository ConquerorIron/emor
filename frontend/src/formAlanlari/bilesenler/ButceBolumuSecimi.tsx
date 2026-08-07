import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { tabloMaddesiSecenekleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/** ERP'nin genel madde tablosunda bütçe bölümlerinin türü */
const BUTCE_BOLUMU_TURU = 136

/**
 * Bütçe bölümü seçimi — ERP'nin genel madde tablosundan (VOHOM_TABLO_MADDESI
 * TUR=136) okunur, yeni uç gerekmez. Ekranda AD görünür, saklanan değer
 * TABLO_MADDESI_ID.
 */
export function ButceBolumuSecimi({ goster, hata, ...ortak }: KayitSecimProps) {
  const { t } = useTranslation()

  const bolumler = useQuery({
    queryKey: queryKeys.secenekler.tabloMaddesi(BUTCE_BOLUMU_TURU, 'ad'),
    queryFn: () => tabloMaddesiSecenekleriGetir(BUTCE_BOLUMU_TURU, 'ad'),
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={bolumler.data ?? []}
      goster={goster}
      yukleniyor={bolumler.isPending}
      hata={hata ?? (bolumler.isError ? t(apiErrorKey(bolumler.error)) : undefined)}
    />
  )
}
