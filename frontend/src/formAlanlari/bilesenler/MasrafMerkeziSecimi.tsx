import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { masrafMerkeziSecenekleriGetir } from '../veri/secenekApi'
import { KodAdSecimi, type KodAdSecim } from './KodAdSecimi'

interface MasrafMerkeziSecimiProps {
  id: string
  label: string
  /** Merkezlerin süzüleceği proje; projesiz (genel) merkezler her zaman gelir */
  projemizId: string
  /** Seçili merkezin ERP MASRAF_MERKEZI_ID'si — satırda MASRAF_MERKEZI_ID */
  deger: string
  degisti: (secim: KodAdSecim | null) => void
  /** Kod yüzü mü ad yüzü mü çiziliyor */
  goster: 'kod' | 'ad'
  hata?: string
  disabled?: boolean
  etiketGizli?: boolean
}

/**
 * Talep satırının masraf merkezi seçimi. Kod ve ad sütunları bu bileşenin iki
 * yüzüdür — kullanıcı ister koddan ister addan seçer, diğeri kendiliğinden
 * dolar ve tek MASRAF_MERKEZI_ID saklanır.
 */
export function MasrafMerkeziSecimi({
  id,
  label,
  projemizId,
  deger,
  degisti,
  goster,
  hata,
  disabled = false,
  etiketGizli = false,
}: MasrafMerkeziSecimiProps) {
  const { t } = useTranslation()

  const merkezler = useQuery({
    queryKey: queryKeys.secenekler.masrafMerkezleri(projemizId),
    queryFn: () => masrafMerkeziSecenekleriGetir(projemizId),
    enabled: projemizId !== '',
    staleTime: 5 * 60_000,
  })

  return (
    <KodAdSecimi
      id={id}
      label={label}
      etiketGizli={etiketGizli}
      deger={deger}
      degisti={degisti}
      secenekler={merkezler.data ?? []}
      goster={goster}
      disabled={disabled || projemizId === ''}
      yukleniyor={projemizId !== '' && merkezler.isPending}
      hata={hata ?? (merkezler.isError ? t(apiErrorKey(merkezler.error)) : undefined)}
    />
  )
}
