import { useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'

import { apiErrorKey } from '@/api/errors'
import { queryKeys } from '@/api/queryKeys'
import { SelectField, type SecenekOgesi } from '@/components/SelectField'

import { personelSecenekleriGetir } from '../veri/secenekApi'

export interface PersonelSecim {
  /** ERP KAYIT_ID (= PERSONEL_ID) — proc'larda parti/personel kimliği */
  kayitId: string
  unvan: string
}

interface PersonelSecimiProps {
  id: string
  label: string
  /** Seçili personelin KAYIT_ID'si; '' = seçim yok */
  deger: string
  degisti: (secim: PersonelSecim | null) => void
  hata?: string
  isClearable?: boolean
  /** Salt okunur (ekran tasarımı) — seçim değiştirilemez */
  disabled?: boolean
}

/**
 * ERP personel seçimi — VOHOM_ARAMA_PERSONEL'den aramalı liste. Program
 * genelinde personel seçilen her yerde bu bileşen kullanılır; seçenekler
 * 5 dk önbelleklenir, arama react-select içinde client tarafında yapılır.
 */
export function PersonelSecimi({
  id,
  label,
  deger,
  degisti,
  hata,
  isClearable = true,
  disabled = false,
}: PersonelSecimiProps) {
  const { t } = useTranslation()

  const personeller = useQuery({
    queryKey: queryKeys.secenekler.personeller,
    queryFn: personelSecenekleriGetir,
    staleTime: 5 * 60_000,
  })

  const secenekler = useMemo<SecenekOgesi[]>(
    () =>
      (personeller.data ?? []).map((p) => ({
        value: String(p.kayit_id),
        label: p.unvan,
      })),
    [personeller.data],
  )

  return (
    <SelectField
      id={id}
      label={label}
      options={secenekler}
      value={secenekler.find((s) => s.value === deger) ?? null}
      onChange={(secim) => {
        degisti(secim ? { kayitId: secim.value, unvan: secim.label } : null)
      }}
      placeholder={t('personel.sec')}
      isClearable={isClearable}
      disabled={disabled}
      yukleniyor={personeller.isPending}
      hata={hata ?? (personeller.isError ? t(apiErrorKey(personeller.error)) : undefined)}
    />
  )
}
