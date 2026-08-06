import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'

import { depoSecenekleriGetir } from './api'

export interface DepoSecim {
  /** ERP KAYIT_ID (= PARTI_YAMASI_ID) — sipariş satırlarında DEPOMUZ_ID */
  kayitId: string
  ad: string
}

interface DepoSecimiProps {
  id: string
  label: string
  /** Seçili deponun KAYIT_ID'si; '' = seçim yok */
  deger: string
  degisti: (secim: DepoSecim | null) => void
  hata?: string
  isClearable?: boolean
  /** Salt okunur (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
}

/**
 * ERP depo seçimi — AD sıralı aramalı liste; program genelinde depo seçilen
 * her yerde bu bileşen kullanılır. Seçenekler 5 dk önbelleklenir.
 */
export function DepoSecimi({
  id,
  label,
  deger,
  degisti,
  hata,
  isClearable = true,
  disabled = false,
}: DepoSecimiProps) {
  const { t } = useTranslation()

  const depolar = useQuery({
    queryKey: queryKeys.secenekler.depolar,
    queryFn: depoSecenekleriGetir,
    staleTime: 5 * 60_000,
  })

  const secenekler = useMemo<SecenekOgesi[]>(
    () =>
      (depolar.data ?? []).map((depo) => ({
        value: String(depo.kayit_id),
        label: depo.ad,
      })),
    [depolar.data],
  )

  return (
    <SelectField
      id={id}
      label={label}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => {
        degisti(secim ? { kayitId: secim.value, ad: secim.label } : null)
      }}
      placeholder={t('ortak.secVeyaAra')}
      isClearable={isClearable}
      disabled={disabled}
      yukleniyor={depolar.isPending}
      hata={hata ?? (depolar.isError ? t(apiErrorKey(depolar.error)) : undefined)}
    />
  )
}
