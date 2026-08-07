import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'

import { aktiviteSecenekleriGetir } from '../veri/secenekApi'
import { KodAdSecimi, type KodAdSecim, type KodAdSecenegi } from './KodAdSecimi'

interface AktiviteSecimiProps {
  id: string
  label: string
  /** Aktivitelerin okunacağı proje (başlıktaki İlgili kayıt); '' = liste boş */
  projemizId: string
  /** Seçili aktivitenin ERP AKTIVITE_ID'si */
  deger: string
  degisti: (secim: KodAdSecim | null) => void
  /** Kod yüzü mü açıklama yüzü mü çiziliyor */
  goster: 'kod' | 'ad'
  hata?: string
  disabled?: boolean
  etiketGizli?: boolean
}

/**
 * Talep satırının aktivite seçimi — kaynak, seçili projenin iş programındaki
 * aktivitelerdir. Kod ve açıklama sütunları bu bileşenin iki yüzüdür
 * (birinden seçince diğeri dolar); ERP AKTIVITE_ID tek kez saklanır.
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

  // ERP'nin ACIKLAMA'sı bu bileşende "ad" yüzüdür
  const secenekler = useMemo<KodAdSecenegi[]>(
    () =>
      (aktiviteler.data ?? []).map((aktivite) => ({
        kayit_id: aktivite.kayit_id,
        kod: aktivite.kod,
        ad: aktivite.aciklama,
      })),
    [aktiviteler.data],
  )

  return (
    <KodAdSecimi
      id={id}
      label={label}
      etiketGizli={etiketGizli}
      deger={deger}
      degisti={degisti}
      secenekler={secenekler}
      goster={goster}
      disabled={disabled || projemizId === ''}
      yukleniyor={projemizId !== '' && aktiviteler.isPending}
      hata={hata ?? (aktiviteler.isError ? t(apiErrorKey(aktiviteler.error)) : undefined)}
    />
  )
}
