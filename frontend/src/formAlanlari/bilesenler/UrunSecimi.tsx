import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { useDebounce } from '@/hooks/useDebounce'

import { urunSecenekleriGetir } from '../veri/secenekApi'
import { CokYuzluSecim, type KayitSecimProps } from './CokYuzluSecim'

/**
 * Ürün seçimi — kod, barkod ve ad aynı kaydın üç yüzüdür; üçünden birinden
 * seçmek yeterlidir (URUN_YAMASI_ID saklanır).
 *
 * Diğer listelerden farkı: ürün sayısı on binlerle ölçülür, bu yüzden liste
 * tarayıcıya toptan indirilmez — arama sunucuda yapılır ve ilk 50 kayıt gelir.
 */
export function UrunSecimi({ goster, hata, ...ortak }: KayitSecimProps) {
  const { t } = useTranslation()
  const [arama, setArama] = useState('')
  // Her tuş vuruşunda istek atılmasın
  const gecikmeliArama = useDebounce(arama)

  const urunler = useQuery({
    queryKey: queryKeys.secenekler.urunler(gecikmeliArama),
    queryFn: () => urunSecenekleriGetir(gecikmeliArama),
    staleTime: 5 * 60_000,
  })

  return (
    <CokYuzluSecim
      {...ortak}
      secenekler={urunler.data ?? []}
      goster={goster}
      aramaDegisti={setArama}
      yukleniyor={urunler.isPending}
      hata={hata ?? (urunler.isError ? t(apiErrorKey(urunler.error)) : undefined)}
    />
  )
}
