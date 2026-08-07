import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { aktiviteSecenekleriGetir, type AktiviteSecenegi } from '../veri/secenekApi'
import { CokYuzluSecim } from './CokYuzluSecim'

interface AktiviteSecimiProps {
  id: string
  label: string
  /** Aktivitelerin okunacağı proje (başlıktaki İlgili kayıt); '' = liste boş */
  projemizId: string
  /** Seçili aktivitenin ERP AKTIVITE_ID'si */
  deger: string
  degisti: (secim: AktiviteSecenegi | null) => void
  /** Hangi yüz çiziliyor: kod sütunu mu, açıklama sütunu mu */
  goster: 'kod' | 'aciklama'
  hata?: string
  disabled?: boolean
  etiketGizli?: boolean
}

/**
 * Talep satırının aktivite seçimi — kaynak, seçili projenin iş programındaki
 * aktivitelerdir (backend projeden iş programını kendisi çözer). Kod ve
 * açıklama sütunları bu kaydın iki yüzüdür.
 */
export function AktiviteSecimi({
  id,
  label,
  projemizId,
  deger,
  degisti,
  goster,
  hata,
  disabled = false,
  etiketGizli = false,
}: AktiviteSecimiProps) {
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
      id={id}
      label={label}
      etiketGizli={etiketGizli}
      deger={deger}
      degisti={degisti}
      secenekler={aktiviteler.data ?? []}
      goster={goster}
      disabled={disabled || projemizId === ''}
      yukleniyor={projemizId !== '' && aktiviteler.isPending}
      hata={hata ?? (aktiviteler.isError ? t(apiErrorKey(aktiviteler.error)) : undefined)}
    />
  )
}
